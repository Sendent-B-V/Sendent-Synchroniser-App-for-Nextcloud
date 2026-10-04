<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Service\ChangeNotification\AfterCommit;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\PostCommitQueue;
use OCA\SendentSynchroniser\Service\ChangeNotification\SignalPublisher;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Exercises PostCommitQueue together with a real AfterCommit, whose
 * end-of-request hook is captured instead of registered with PHP.
 */
class PostCommitQueueTest extends TestCase {

	/** @var ChangeLedgerService&MockObject */
	private $ledger;

	/** @var SignalPublisher&MockObject */
	private $publisher;

	/** @var list<callable> work handed to the end-of-request hook */
	private array $scheduled = [];

	private bool $inTransaction = true;

	private PostCommitQueue $queue;

	protected function setUp(): void {
		parent::setUp();
		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturnCallback(fn (): bool => $this->inTransaction);
		$this->ledger = $this->createMock(ChangeLedgerService::class);
		$this->publisher = $this->createMock(SignalPublisher::class);
		$afterCommit = new AfterCommit($db, new NullLogger(), function (callable $work): void {
			$this->scheduled[] = $work;
		});
		$this->queue = new PostCommitQueue($afterCommit, $this->ledger, $this->publisher, new NullLogger());
	}

	/** @return array{ref: CollectionReference, seq: int} */
	private function stamp(string $uri, int $seq): array {
		return ['ref' => new CollectionReference('principals/users/alice', 'caldav', $uri, 1, false), 'seq' => $seq];
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
		$this->publisher->expects($this->never())->method('flushIfDue');

		$this->queue->add([$this->stamp('personal', 10)]);

		$this->assertCount(1, $this->scheduled);
	}

	public function testStampsAreConfirmedBeforeAFlushIsOfferedAfterCommit(): void {
		$order = [];
		$this->ledger->expects($this->once())->method('confirm')
			->with([$this->stamp('personal', 10)])
			->willReturnCallback(function () use (&$order): int {
				$order[] = 'confirm';
				return 0;
			});
		$this->publisher->expects($this->once())->method('flushIfDue')
			->willReturnCallback(function () use (&$order): ?array {
				$order[] = 'flush';
				return null;
			});

		$this->queue->add([$this->stamp('personal', 10)]);
		$this->endRequest();

		$this->assertSame(['confirm', 'flush'], $order);
	}

	public function testOutsideATransactionTheWorkRunsImmediately(): void {
		$this->inTransaction = false;
		$this->ledger->expects($this->once())->method('confirm');
		$this->publisher->expects($this->once())->method('flushIfDue');

		$this->queue->add([$this->stamp('personal', 10)]);

		$this->assertSame([], $this->scheduled);
	}

	public function testManyEventsInOneRequestRunOnceAndKeepTheHighestStampPerCollection(): void {
		$this->ledger->expects($this->once())->method('confirm')->with([
			$this->stamp('personal', 12),
			$this->stamp('work', 11),
		]);
		$this->publisher->expects($this->once())->method('flushIfDue');

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
		$this->publisher->expects($this->never())->method('flushIfDue');

		$this->queue->add([$this->stamp('personal', 10)]);
		foreach ($this->scheduled as $work) {
			$work(); // still inside the transaction
		}
	}

	public function testFailuresAreSwallowedAndTheFlushIsStillOffered(): void {
		$this->ledger->method('confirm')->willThrowException(new \RuntimeException('db gone'));
		$this->publisher->expects($this->once())->method('flushIfDue')
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
}
