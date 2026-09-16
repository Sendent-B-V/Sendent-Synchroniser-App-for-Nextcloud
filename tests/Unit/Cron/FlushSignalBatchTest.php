<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Cron;

use OCA\SendentSynchroniser\Cron\FlushSignalBatch;
use OCA\SendentSynchroniser\Db\SequenceMapper;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\SignalPublisher;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class FlushSignalBatchTest extends TestCase {

	/** @var SignalPublisher&MockObject */
	private $publisher;

	/** @var SequenceMapper&MockObject */
	private $sequence;

	/** @var ChangeNotificationConfig&MockObject */
	private $config;

	private FlushSignalBatch $job;

	protected function setUp(): void {
		parent::setUp();
		$this->publisher = $this->createMock(SignalPublisher::class);
		$this->sequence = $this->createMock(SequenceMapper::class);
		$this->config = $this->createMock(ChangeNotificationConfig::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1755676800);

		$this->job = new FlushSignalBatch($time, $this->publisher, $this->sequence, $this->config, new NullLogger());
	}

	public function testRunFlushesAndPrunesTheSequenceTable(): void {
		$this->config->method('botUser')->willReturn('sendent-sync');
		$this->publisher->expects($this->once())->method('flush');
		$this->sequence->expects($this->once())->method('prune');

		$this->job->start($this->createMock(\OCP\BackgroundJob\IJobList::class));
	}

	public function testAFailingFlushStillPrunes(): void {
		$this->config->method('botUser')->willReturn('sendent-sync');
		$this->publisher->method('flush')->willThrowException(new \RuntimeException('redis down'));
		$this->sequence->expects($this->once())->method('prune');

		$this->job->start($this->createMock(\OCP\BackgroundJob\IJobList::class));
	}

	public function testAnUnconfiguredInstanceSkipsTheFlushButStillPrunes(): void {
		// No bot user and no webhook = no signal channel at all. flush() would
		// read rows, fail to publish, never advance the watermark - and repeat
		// every cron run forever. Skip it; polling readers use the ledger
		// directly and lose nothing.
		$this->config->method('botUser')->willReturn('');
		$this->config->method('webhookEnabled')->willReturn(false);
		$this->publisher->expects($this->never())->method('flush');
		$this->sequence->expects($this->once())->method('prune');

		$this->job->start($this->createMock(\OCP\BackgroundJob\IJobList::class));
	}

	public function testPollingPinnedWithoutWebhookSkipsTheFlushButStillPrunes(): void {
		$this->config->method('transportMode')->willReturn('polling');
		$this->config->method('botUser')->willReturn('sendent-sync');
		$this->config->method('webhookEnabled')->willReturn(false);
		$this->publisher->expects($this->never())->method('flush');
		$this->sequence->expects($this->once())->method('prune');

		$this->job->start($this->createMock(\OCP\BackgroundJob\IJobList::class));
	}
}
