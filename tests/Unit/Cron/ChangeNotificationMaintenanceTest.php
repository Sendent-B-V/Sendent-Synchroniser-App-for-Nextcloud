<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Cron;

use OCA\SendentSynchroniser\Cron\ChangeNotificationMaintenance;
use OCA\SendentSynchroniser\Db\SequenceMapper;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushTransport;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ChangeNotificationMaintenanceTest extends TestCase {

	/** @var NotifyPushTransport&MockObject */
	private $transport;

	/** @var SequenceMapper&MockObject */
	private $sequence;

	private ChangeNotificationMaintenance $job;

	protected function setUp(): void {
		parent::setUp();
		$this->transport = $this->createMock(NotifyPushTransport::class);
		$this->sequence = $this->createMock(SequenceMapper::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1755676800);

		$this->job = new ChangeNotificationMaintenance($time, $this->transport, $this->sequence, new NullLogger());
	}

	public function testEachRunSendsOneHintAndPrunesTheSequenceTable(): void {
		// The hint bounds how long a change written by a long-running process
		// (whose own hint waits for that process to exit) or a lost hint can
		// go unnoticed: one cron interval.
		$this->transport->expects($this->once())->method('publishHint');
		$this->sequence->expects($this->once())->method('prune');

		$this->job->start($this->createMock(\OCP\BackgroundJob\IJobList::class));
	}

	public function testAFailingHintStillPrunes(): void {
		$this->transport->method('publishHint')->willThrowException(new \RuntimeException('redis down'));
		$this->sequence->expects($this->once())->method('prune');

		$this->job->start($this->createMock(\OCP\BackgroundJob\IJobList::class));
	}
}
