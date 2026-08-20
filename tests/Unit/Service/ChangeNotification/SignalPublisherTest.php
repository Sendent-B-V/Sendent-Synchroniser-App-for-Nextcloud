<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\Db\DirtyCollection;
use OCA\SendentSynchroniser\Service\ChangeNotification\BatchWindowService;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
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

		$serverConfig = $this->createMock(IConfig::class);
		$serverConfig->method('getSystemValueString')->willReturn('inst');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1755676800);
		$metrics = $this->createMock(\OCA\SendentSynchroniser\Service\ChangeNotification\SignalMetrics::class);

		$this->publisher = new SignalPublisher(
			$this->window,
			$this->ledger,
			new SignalBuilder($serverConfig),
			$this->transport,
			$this->config,
			$time,
			$metrics,
			new NullLogger(),
		);
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
		$this->config->method('flushedSeq')->willReturn(500);
		$this->ledger->method('rows')->with(500, 4)->willReturn([
			$this->row('personal', 501),
			$this->row('work', 502),
		]);
		$this->transport->method('publish')->willReturn(true);
		$this->config->expects($this->once())->method('setFlushedSeq')->with(502);

		$signal = $this->publisher->flushIfDue();

		$this->assertNotNull($signal);
		$this->assertSame(500, $signal['prev']);
		$this->assertSame(502, $signal['cursor']);
		$this->assertCount(2, $signal['refs']);
		$this->assertFalse($signal['truncated']);
	}

	public function testFlushWithNothingNewPublishesNothing(): void {
		$this->window->method('tryOpenWindow')->willReturn(true);
		$this->config->method('flushedSeq')->willReturn(500);
		$this->ledger->method('rows')->willReturn([]);
		$this->transport->expects($this->never())->method('publish');
		$this->config->expects($this->never())->method('setFlushedSeq');

		$this->assertNull($this->publisher->flushIfDue());
	}

	public function testAnOverfullBatchIsPublishedTruncated(): void {
		// maxRefsPerSignal is 3; the publisher asks for 4 rows and gets 4,
		// so the signal switches to "go read /changes" form. The refs are
		// not in the signal at all, and the cursor points at the ledger's
		// true position.
		$this->window->method('tryOpenWindow')->willReturn(true);
		$this->config->method('flushedSeq')->willReturn(0);
		$this->ledger->method('rows')->with(0, 4)->willReturn([
			$this->row('a', 1),
			$this->row('b', 2),
			$this->row('c', 3),
			$this->row('d', 4),
		]);
		$this->transport->method('publish')->willReturn(true);
		$this->config->expects($this->once())->method('setFlushedSeq')->with(4);

		$signal = $this->publisher->flushIfDue();

		$this->assertTrue($signal['truncated']);
		$this->assertSame([], $signal['refs']);
		$this->assertSame(4, $signal['cursor']);
	}

	public function testTheWatermarkDoesNotAdvanceWhenPublishFails(): void {
		// A failed publish leaves the refs above the watermark so the sweeper
		// republishes them. Polling readers never notice either way.
		$this->window->method('tryOpenWindow')->willReturn(true);
		$this->config->method('flushedSeq')->willReturn(500);
		$this->ledger->method('rows')->willReturn([$this->row('personal', 501)]);
		$this->transport->method('publish')->willReturn(false);
		$this->config->expects($this->never())->method('setFlushedSeq');

		$this->assertNull($this->publisher->flushIfDue());
	}

	public function testForcedFlushSkipsTheWindow(): void {
		$this->window->expects($this->never())->method('tryOpenWindow');
		$this->config->method('flushedSeq')->willReturn(500);
		$this->ledger->method('rows')->willReturn([$this->row('personal', 501)]);
		$this->transport->method('publish')->willReturn(true);

		$this->assertNotNull($this->publisher->flush());
	}
}
