<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\Db\DirtyCollection;
use OCA\SendentSynchroniser\Service\ChangeNotification\BatchWindowService;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\CursorService;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushTransport;
use OCA\SendentSynchroniser\Service\ChangeNotification\SignalBuilder;
use OCA\SendentSynchroniser\Service\ChangeNotification\SignalPublisher;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SignalPublisherTest extends TestCase {

	/** @var BatchWindowService&MockObject */
	private $window;

	/** @var ChangeLedgerService&MockObject */
	private $ledger;

	/** @var NotifyPushTransport&MockObject */
	private $transport;

	/** @var ChangeNotificationConfig&MockObject */
	private $config;

	/** @var IConfig&MockObject */
	private $serverConfig;

	/** @var \OCP\BackgroundJob\IJobList&MockObject */
	private $jobList;

	/** @var CursorService&MockObject */
	private $cursor;

	/** What the mocked counter reports as handed out so far. */
	private int $counter = 1000000;

	private SignalPublisher $publisher;

	protected function setUp(): void {
		parent::setUp();
		$this->window = $this->createMock(BatchWindowService::class);
		$this->ledger = $this->createMock(ChangeLedgerService::class);
		// ChangeLedgerService is mocked, so highestSeqOf() would otherwise
		// return PHPUnit's default (0) for its declared int return type
		// regardless of the rows passed in. The publisher relies on this
		// method to compute the real cursor, so give the mock the same
		// behaviour as the real implementation.
		$this->ledger->method('highestSeqOf')->willReturnCallback(
			static fn (array $rows): int => array_reduce(
				$rows,
				static fn (int $carry, DirtyCollection $row): int => max($carry, (int)$row->getChangeSeq()),
				0
			)
		);
		$this->transport = $this->createMock(NotifyPushTransport::class);
		$this->config = $this->createMock(ChangeNotificationConfig::class);
		$this->config->method('maxRefsPerSignal')->willReturn(3);
		$this->config->method('webhookEnabled')->willReturn(false);

		$this->serverConfig = $this->createMock(IConfig::class);
		$this->serverConfig->method('getSystemValueString')->willReturn('inst');
		$this->jobList = $this->createMock(\OCP\BackgroundJob\IJobList::class);
		$this->cursor = $this->createMock(CursorService::class);
		// Default: the counter is well ahead of every row, so the fence never
		// caps the watermark unless a test says otherwise.
		$this->cursor->method('current')->willReturnCallback(fn (): int => $this->counter);
		$metrics =$this->createMock(\OCA\SendentSynchroniser\Service\ChangeNotification\SignalMetrics::class);

		$this->publisher = new SignalPublisher(
			$this->window,
			$this->ledger,
			new SignalBuilder($this->serverConfig),
			$this->transport,
			$this->config,
			$this->timeFactory(),
			$metrics,
			$this->jobList,
			new NullLogger(),
			$this->cursor,
		);
	}

	private function timeFactory(): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1755676800);
		return $time;
	}

	private function configWithWebhook(): ChangeNotificationConfig {
		$config = $this->createMock(ChangeNotificationConfig::class);
		$config->method('maxRefsPerSignal')->willReturn(3);
		$config->method('flushState')->willReturn(['flushed' => 500, 'published' => 500]);
		$config->method('webhookEnabled')->willReturn(true);
		$config->expects($this->once())->method('recordFlush')->with(501, 501);
		return $config;
	}

	private function webhookJobList(): \OCP\BackgroundJob\IJobList {
		$jobList = $this->createMock(\OCP\BackgroundJob\IJobList::class);
		$jobList->expects($this->once())->method('add');
		return $jobList;
	}

	private function row(string $uri, int $seq): DirtyCollection {
		$row = new DirtyCollection();
		$row->setPrincipalUri('principals/users/alice');
		$row->setCollectionType('caldav');
		$row->setCollectionUri($uri);
		$row->setSyncToken(1);
		$row->setChangeSeq($seq);
		$row->setStructuralSeq(0);
		$row->setUpdatedAt(1755676800);
		return $row;
	}

	public function testFlushIfDueDoesNothingWhenTheWindowIsClosed(): void {
		$this->window->method('tryOpenWindow')->willReturn(false);
		$this->transport->expects($this->never())->method('publish');

		$this->assertNull($this->publisher->flushIfDue());
	}

	public function testFlushPublishesEverythingAboveTheWatermarkAndAdvancesIt(): void {
		$this->window->method('tryOpenWindow')->willReturn(true);
		$this->config->method('flushState')->willReturn(['flushed' => 500, 'published' => 500]);
		$this->ledger->method('rows')->with(500, 4)->willReturn([
			$this->row('personal', 501),
			$this->row('work', 502),
		]);
		$this->transport->method('publish')->willReturn(true);
		$this->config->expects($this->once())->method('recordFlush')->with(502, 502);

		$signal = $this->publisher->flushIfDue();

		$this->assertNotNull($signal);
		$this->assertSame(500, $signal['prev']);
		$this->assertSame(502, $signal['cursor']);
		$this->assertCount(2, $signal['refs']);
		$this->assertFalse($signal['truncated']);
	}

	public function testFlushWithNothingNewPublishesNothing(): void {
		$this->window->method('tryOpenWindow')->willReturn(true);
		$this->config->method('flushState')->willReturn(['flushed' => 500, 'published' => 500]);
		$this->ledger->method('rows')->willReturn([]);
		$this->transport->expects($this->never())->method('publish');
		$this->config->expects($this->never())->method('recordFlush');

		$this->assertNull($this->publisher->flushIfDue());
	}

	public function testAnOverfullBatchIsPublishedTruncated(): void {
		// maxRefsPerSignal is 3; the publisher asks for 4 rows and gets 4,
		// so the signal switches to "go read /changes" form. The refs are
		// not in the signal at all, and the cursor points at the ledger's
		// true position.
		$this->window->method('tryOpenWindow')->willReturn(true);
		$this->config->method('flushState')->willReturn(['flushed' => 0, 'published' => 0]);
		$this->ledger->method('rows')->with(0, 4)->willReturn([
			$this->row('a', 1),
			$this->row('b', 2),
			$this->row('c', 3),
			$this->row('d', 4),
		]);
		$this->transport->method('publish')->willReturn(true);
		$this->config->expects($this->once())->method('recordFlush')->with(4, 4);

		$signal = $this->publisher->flushIfDue();

		$this->assertTrue($signal['truncated']);
		$this->assertSame([], $signal['refs']);
		$this->assertSame(4, $signal['cursor']);
	}

	public function testTheWatermarkDoesNotAdvanceWhenPublishFails(): void {
		// A failed publish leaves the refs above the watermark so the sweeper
		// republishes them. Polling readers never notice either way.
		$this->window->method('tryOpenWindow')->willReturn(true);
		$this->config->method('flushState')->willReturn(['flushed' => 500, 'published' => 500]);
		$this->ledger->method('rows')->willReturn([$this->row('personal', 501)]);
		$this->transport->method('publish')->willReturn(false);
		$this->config->expects($this->never())->method('recordFlush');

		$this->assertNull($this->publisher->flushIfDue());
	}

	public function testForcedFlushSkipsTheWindow(): void {
		$this->window->expects($this->never())->method('tryOpenWindow');
		$this->config->method('flushState')->willReturn(['flushed' => 500, 'published' => 500]);
		$this->ledger->method('rows')->willReturn([$this->row('personal', 501)]);
		$this->transport->method('publish')->willReturn(true);

		$this->assertNotNull($this->publisher->flush());
	}

	public function testAWebhookOnlySetupStillAdvancesTheWatermark(): void {
		// notify_push publish fails (no bot/queue) but the webhook channel is
		// enabled: the signal is queued for webhook delivery and the watermark
		// advances — otherwise the sweeper would re-deliver forever.
		$this->window->method('tryOpenWindow')->willReturn(true);
		$this->config->method('flushState')->willReturn(['flushed' => 500, 'published' => 500]);
		$this->ledger->method('rows')->willReturn([$this->row('personal', 501)]);
		$this->transport->method('publish')->willReturn(false);

		$publisher = new SignalPublisher(
			$this->window,
			$this->ledger,
			new SignalBuilder($this->serverConfig),
			$this->transport,
			$this->configWithWebhook(),
			$this->timeFactory(),
			$this->createMock(\OCA\SendentSynchroniser\Service\ChangeNotification\SignalMetrics::class),
			$this->webhookJobList(),
			new NullLogger(),
			$this->cursor,
		);

		$this->assertNotNull($publisher->flushIfDue());
	}

	public function testAnOversizedWebhookSignalIsQueuedTruncated(): void {
		// NC's job list rejects arguments over ~32k JSON chars; an oversized
		// signal must degrade to the truncated frame for the webhook channel.
		$this->window->method('tryOpenWindow')->willReturn(true);
		$this->transport->method('publish')->willReturn(true);

		$rows = [];
		for ($i = 1; $i <= 200; $i++) {
			$rows[] = $this->row(str_repeat('x', 200) . $i, 500 + $i);
		}
		$this->ledger->method('rows')->willReturn($rows);

		$captured = null;
		$jobList = $this->createMock(\OCP\BackgroundJob\IJobList::class);
		$jobList->method('add')->willReturnCallback(
			function (string $class, array $argument) use (&$captured): void {
				$captured = $argument;
			}
		);

		$config = $this->createMock(ChangeNotificationConfig::class);
		$config->method('maxRefsPerSignal')->willReturn(500);
		$config->method('flushState')->willReturn(['flushed' => 500, 'published' => 500]);
		$config->method('webhookEnabled')->willReturn(true);

		$publisher = new SignalPublisher(
			$this->window,
			$this->ledger,
			new SignalBuilder($this->serverConfig),
			$this->transport,
			$config,
			$this->timeFactory(),
			$this->createMock(\OCA\SendentSynchroniser\Service\ChangeNotification\SignalMetrics::class),
			$jobList,
			new NullLogger(),
			$this->cursor,
		);

		$signal = $publisher->flushIfDue();

		$this->assertNotNull($signal);
		$this->assertFalse($signal['truncated']); // the live/notify_push frame keeps its refs
		$this->assertNotNull($captured);
		$this->assertTrue($captured['signal']['truncated']);
		$this->assertSame([], $captured['signal']['refs']);
	}

	public function testTheFenceIsRaisedBeforeTheLedgerIsRead(): void {
		// Order matters: a writer that commits after the read must already see
		// the raised fence, or it cannot know it was read past.
		$this->counter = 777;
		$this->config->method('flushState')->willReturn(['flushed' => 500, 'published' => 500]);
		$calls = [];
		$this->cursor->method('raiseFence')->willReturnCallback(
			function (int $seq) use (&$calls): void {
				$calls[] = 'fence:' . $seq;
			}
		);
		$this->ledger->method('rows')->willReturnCallback(
			function () use (&$calls): array {
				$calls[] = 'read';
				return [];
			}
		);

		$this->publisher->flush();

		$this->assertSame(['fence:777', 'read'], $calls);
	}

	public function testTheWatermarkStopsAtTheFence(): void {
		// The counter stood at 501 when the flush began. Row 503 was handed out
		// and committed in the moment between fence and read; row 502 was
		// handed out then too but is still uncommitted. Moving the watermark to
		// 503 would bury 502 below it for good, so it stops at 501. The signal
		// still carries everything it read, with the true cursor.
		$this->counter = 501;
		$this->window->method('tryOpenWindow')->willReturn(true);
		$this->config->method('flushState')->willReturn(['flushed' => 500, 'published' => 500]);
		$this->ledger->method('rows')->willReturn([
			$this->row('personal', 501),
			$this->row('work', 503),
		]);
		$this->transport->method('publish')->willReturn(true);
		$this->config->expects($this->once())->method('recordFlush')->with(501, 503);

		$signal = $this->publisher->flushIfDue();

		$this->assertSame(503, $signal['cursor']);
		$this->assertCount(2, $signal['refs']);
	}

	public function testPrevIsThePreviousSignalsCursorNotTheWatermark(): void {
		// The last signal carried cursor 503 while the watermark stayed at 501.
		// The next signal must report prev 503: a Connector that saw 503 has
		// missed nothing. Reporting 501 would also hide a genuinely missed
		// frame, because prev would never rise above the reader's cursor.
		$this->window->method('tryOpenWindow')->willReturn(true);
		$this->config->method('flushState')->willReturn(['flushed' => 501, 'published' => 503]);
		$this->ledger->method('rows')->with(501, 4)->willReturn([
			$this->row('work', 503),
			$this->row('personal', 504),
		]);
		$this->transport->method('publish')->willReturn(true);

		$signal = $this->publisher->flushIfDue();

		$this->assertSame(503, $signal['prev']);
		$this->assertSame(504, $signal['cursor']);
	}

	public function testARereadOfAlreadySignalledRowsNeverMovesTheCursorBack(): void {
		$this->window->method('tryOpenWindow')->willReturn(true);
		$this->config->method('flushState')->willReturn(['flushed' => 501, 'published' => 510]);
		$this->ledger->method('rows')->willReturn([$this->row('work', 503)]);
		$this->transport->method('publish')->willReturn(true);

		$signal = $this->publisher->flushIfDue();

		$this->assertSame(510, $signal['prev']);
		$this->assertSame(510, $signal['cursor']);
	}

	public function testPinnedPollingWithoutWebhookSkipsTheWindowEntirely(): void {
		$this->config->method('transportMode')->willReturn('polling');
		$this->window->expects($this->never())->method('tryOpenWindow');
		$this->ledger->expects($this->never())->method('rows');

		$this->assertNull($this->publisher->flushIfDue());
	}
}
