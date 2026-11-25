<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\ChangeNotification\EventChange;
use OCA\SendentSynchroniser\ChangeNotification\EventChangeKind;
use OCA\SendentSynchroniser\ChangeNotification\EventStamp;
use OCA\SendentSynchroniser\Service\ChangeNotification\AfterCommit;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushTransport;
use OCA\SendentSynchroniser\Service\ChangeNotification\PostCommitQueue;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Exercises PostCommitQueue together with a real AfterCommit, whose
 * end-of-request hook is captured instead of registered with PHP.
 */
class PostCommitQueueTest extends TestCase {

	/** @var ChangeLedgerService&MockObject */
	private $ledger;

	/** @var NotifyPushTransport&MockObject */
	private $publisher;

	/** @var list<callable> work handed to the end-of-request hook */
	private array $scheduled = [];

	private bool $inTransaction = true;

	private PostCommitQueue $queue;

	protected function setUp(): void {
		parent::setUp();
		$this->ledger = $this->createMock(ChangeLedgerService::class);
		$this->publisher = $this->createMock(NotifyPushTransport::class);
		$this->queue = $this->queueWithLogger(new NullLogger());
	}

	private function queueWithLogger(LoggerInterface $logger): PostCommitQueue {
		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturnCallback(fn (): bool => $this->inTransaction);
		$afterCommit = new AfterCommit($db, new NullLogger(), function (callable $work): void {
			$this->scheduled[] = $work;
		});

		return new PostCommitQueue($afterCommit, $this->ledger, $this->publisher, $logger);
	}

	private function eventStamp(string $calendarUri, string $fileName, int $seq): EventStamp {
		$calendar = new CollectionReference('principals/users/alice', 'caldav', $calendarUri, false);

		return new EventStamp(new EventChange($calendar, $fileName, EventChangeKind::Updated), $seq);
	}

	/**
	 * Makes the ledger keep each list of per-event stamps it is given.
	 *
	 * @param list<list<EventStamp>> $calls
	 */
	private function keepTheEventStamps(array &$calls): void {
		$this->ledger->method('confirmEventRows')->willReturnCallback(
			function (array $eventStamps) use (&$calls): int {
				$calls[] = $eventStamps;
				return 0;
			}
		);
	}

	/** @return array{ref: CollectionReference, seq: int} */
	private function stamp(string $uri, int $seq): array {
		return ['ref' => new CollectionReference('principals/users/alice', 'caldav', $uri, false), 'seq' => $seq];
	}

	/** Simulates the DAV backend committing and the request ending. */
	private function endRequest(): void {
		$this->inTransaction = false;
		foreach ($this->scheduled as $work) {
			$work();
		}
	}

	public function testNothingIsPublishedWhileTheDavTransactionIsOpen(): void {
		$this->ledger->expects($this->never())->method('confirm');
		$this->publisher->expects($this->never())->method('publishHint');

		$this->queue->add([$this->stamp('personal', 10)]);

		$this->assertCount(1, $this->scheduled);
	}

	public function testStampsAreConfirmedBeforeTheHintIsSentAfterCommit(): void {
		$order = [];
		$this->ledger->expects($this->once())->method('confirm')
			->with([$this->stamp('personal', 10)])
			->willReturnCallback(function () use (&$order): int {
				$order[] = 'confirm';
				return 0;
			});
		$this->publisher->expects($this->once())->method('publishHint')
			->willReturnCallback(function () use (&$order): bool {
				$order[] = 'hint';
				return true;
			});

		$this->queue->add([$this->stamp('personal', 10)]);
		$this->endRequest();

		$this->assertSame(['confirm', 'hint'], $order);
	}

	public function testOutsideATransactionTheWorkRunsImmediately(): void {
		$this->inTransaction = false;
		$this->ledger->expects($this->once())->method('confirm');
		$this->publisher->expects($this->once())->method('publishHint');

		$this->queue->add([$this->stamp('personal', 10)]);

		$this->assertSame([], $this->scheduled);
	}

	public function testManyEventsInOneRequestRunOnceAndKeepTheHighestStampPerCollection(): void {
		$this->ledger->expects($this->once())->method('confirm')->with([
			$this->stamp('personal', 12),
			$this->stamp('work', 11),
		]);
		$this->publisher->expects($this->once())->method('publishHint');

		$this->queue->add([$this->stamp('personal', 10)]);
		$this->queue->add([$this->stamp('work', 11)]);
		$this->queue->add([$this->stamp('personal', 12)]);
		$this->endRequest();

		$this->assertCount(1, $this->scheduled);
	}

	public function testATransactionLeftOpenAtShutdownPublishesNothing(): void {
		// The write was never committed and rolls back with the connection;
		// advertising it would point the Connector at a change that never was.
		$this->ledger->expects($this->never())->method('confirm');
		$this->publisher->expects($this->never())->method('publishHint');

		$this->queue->add([$this->stamp('personal', 10)]);
		foreach ($this->scheduled as $work) {
			$work(); // still inside the transaction
		}
	}

	public function testFailuresAreSwallowedAndTheHintIsStillSent(): void {
		$this->ledger->method('confirm')->willThrowException(new \RuntimeException('db gone'));
		$this->publisher->expects($this->once())->method('publishHint')
			->willThrowException(new \RuntimeException('redis gone'));

		$this->queue->add([$this->stamp('personal', 10)]);
		$this->endRequest();
	}

	public function testALaterRequestPhaseIsQueuedAgainAfterTheFirstRan(): void {
		// A long CLI process: the first batch ran outside a transaction; later
		// changes must still be processed rather than silently held forever.
		$this->inTransaction = false;
		$this->ledger->expects($this->exactly(2))->method('confirm');

		$this->queue->add([$this->stamp('personal', 10)]);
		$this->queue->add([$this->stamp('personal', 11)]);
	}

	public function testNoStampsMeansNoWork(): void {
		$this->queue->add([]);

		$this->assertSame([], $this->scheduled);
	}

	// ── Per-event stamps ────────────────────────────────────────────────

	public function testPerEventStampsWaitForTheCommit(): void {
		$this->ledger->expects($this->never())->method('confirmEventRows');
		$this->publisher->expects($this->never())->method('publishHint');

		$this->queue->add([$this->stamp('personal', 11), $this->eventStamp('personal', 'one.ics', 10)]);

		$this->assertCount(1, $this->scheduled);
	}

	public function testTheSecondRowsAreWrittenAfterTheCommitAndBeforeTheHint(): void {
		$eventStamp = $this->eventStamp('personal', 'one.ics', 10);
		$order = [];
		$this->ledger->expects($this->once())->method('confirmEventRows')
			->with([$eventStamp])
			->willReturnCallback(function () use (&$order): int {
				$order[] = 'second rows';
				return 0;
			});
		$this->publisher->expects($this->once())->method('publishHint')
			->willReturnCallback(function () use (&$order): bool {
				$order[] = 'hint';
				return true;
			});

		$this->queue->add([$eventStamp, $this->stamp('personal', 11)]);
		$this->endRequest();

		$this->assertSame(['second rows', 'hint'], $order);
	}

	public function testThePerCalendarConfirmGetsOnlyThePerCalendarStamps(): void {
		$this->ledger->expects($this->once())->method('confirm')->with([$this->stamp('personal', 11)]);

		$this->queue->add([$this->eventStamp('personal', 'one.ics', 10), $this->stamp('personal', 11)]);
		$this->endRequest();
	}

	public function testTwoEventsInOneCalendarStayTwoEntries(): void {
		$one = $this->eventStamp('personal', 'one.ics', 10);
		$two = $this->eventStamp('personal', 'two.ics', 12);
		$calls = [];
		$this->keepTheEventStamps($calls);
		// The two per-calendar stamps still become one, as before.
		$this->ledger->expects($this->once())->method('confirm')->with([$this->stamp('personal', 13)]);

		$this->queue->add([$one, $this->stamp('personal', 11)]);
		$this->queue->add([$two, $this->stamp('personal', 13)]);
		$this->endRequest();

		$this->assertCount(1, $calls);
		$this->assertEqualsCanonicalizing([10, 12], array_map(static fn (EventStamp $s): int => $s->seq, $calls[0]));
		$this->assertCount(1, $this->scheduled);
	}

	public function testTwoChangesOfOneEventStayTwoEntries(): void {
		$calls = [];
		$this->keepTheEventStamps($calls);

		$this->queue->add([$this->eventStamp('personal', 'one.ics', 10)]);
		$this->queue->add([$this->eventStamp('personal', 'one.ics', 12)]);
		$this->endRequest();

		$this->assertCount(1, $calls);
		$this->assertEqualsCanonicalizing([10, 12], array_map(static fn (EventStamp $s): int => $s->seq, $calls[0]));
	}

	public function testPerEventStampsWithoutAPerCalendarStampStillRunAfterTheCommit(): void {
		$eventStamp = $this->eventStamp('personal', 'one.ics', 10);
		$this->ledger->expects($this->once())->method('confirmEventRows')->with([$eventStamp]);
		$this->publisher->expects($this->once())->method('publishHint');

		$this->queue->add([$eventStamp]);
		$this->assertCount(1, $this->scheduled);
		$this->endRequest();
	}

	public function testAFailedSecondRowIsLoggedAndTheHintIsStillSent(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');
		$queue = $this->queueWithLogger($logger);
		$this->ledger->method('confirmEventRows')->willThrowException(new \RuntimeException('db gone'));
		// The per-calendar stamps are confirmed as before.
		$this->ledger->expects($this->once())->method('confirm')->with([$this->stamp('personal', 11)]);
		$this->publisher->expects($this->once())->method('publishHint');

		$queue->add([$this->eventStamp('personal', 'one.ics', 10), $this->stamp('personal', 11)]);
		$this->endRequest();
	}

	public function testPerEventStampsAreNotKeptForALaterRun(): void {
		// A long CLI process: each batch runs with its own stamps only.
		$this->inTransaction = false;
		$calls = [];
		$this->keepTheEventStamps($calls);

		$this->queue->add([$this->eventStamp('personal', 'one.ics', 10)]);
		$this->queue->add([$this->eventStamp('personal', 'two.ics', 12)]);

		$this->assertSame(
			[[10], [12]],
			array_map(static fn (array $call): array => array_map(static fn (EventStamp $s): int => $s->seq, $call), $calls),
		);
	}
}
