<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Cron;

use OCA\SendentSynchroniser\Cron\ChangeNotificationMaintenance;
use OCA\SendentSynchroniser\Db\EventChangeRowMapper;
use OCA\SendentSynchroniser\Db\SequenceMapper;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushTransport;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ChangeNotificationMaintenanceTest extends TestCase {

	private const NOW = 1755676800;
	private const THREE_DAYS = 259200;

	/** @var NotifyPushTransport&MockObject */
	private $transport;

	/** @var SequenceMapper&MockObject */
	private $sequence;

	/** @var EventChangeRowMapper&MockObject */
	private $eventRows;

	/** @var LoggerInterface&MockObject */
	private $logger;

	private ChangeNotificationMaintenance $job;

	protected function setUp(): void {
		parent::setUp();
		$this->transport = $this->createMock(NotifyPushTransport::class);
		$this->sequence = $this->createMock(SequenceMapper::class);
		$this->eventRows = $this->createMock(EventChangeRowMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$this->job = new ChangeNotificationMaintenance($time, $this->transport, $this->sequence, $this->eventRows, $this->logger);
	}

	public function testEachRunSendsOneHintAndPrunesTheSequenceTable(): void {
		// The hint bounds how long a change written by a long-running process
		// (whose own hint waits for that process to exit) or a lost hint can
		// go unnoticed: one cron interval.
		$this->transport->expects($this->once())->method('publishHint');
		$this->sequence->expects($this->once())->method('prune');

		$this->job->start($this->createMock(IJobList::class));
	}

	public function testAFailingHintStillPrunes(): void {
		$this->transport->method('publishHint')->willThrowException(new \RuntimeException('redis down'));
		$this->sequence->expects($this->once())->method('prune');

		$this->job->start($this->createMock(IJobList::class));
	}

	public function testEachRunRemovesTheEventRowsWrittenMoreThanThreeDaysAgo(): void {
		$this->eventRows->expects($this->once())
			->method('deleteWrittenBefore')
			->with(self::NOW - self::THREE_DAYS);

		$this->job->start($this->createMock(IJobList::class));
	}

	public function testAFailingRemovalIsLoggedAndTheJobEndsNormally(): void {
		$this->eventRows->expects($this->once())
			->method('deleteWrittenBefore')
			->willThrowException(new \RuntimeException('database gone'));
		// One error, from the removal. The other two steps are quiet.
		$this->logger->expects($this->once())
			->method('error')
			->with($this->stringContains('database gone'));
		$this->transport->expects($this->once())->method('publishHint');
		$this->sequence->expects($this->once())->method('prune');

		// The server logs an exception that leaves run() and reports the job
		// as done, so the test asks the job list: it gets the time taken only
		// when run() ended without an exception.
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())->method('setExecutionTime');

		$this->job->start($jobList);
	}

	public function testAFailingHintStillRemovesTheEventRows(): void {
		$this->transport->method('publishHint')->willThrowException(new \RuntimeException('redis down'));
		$this->eventRows->expects($this->once())->method('deleteWrittenBefore');

		$this->job->start($this->createMock(IJobList::class));
	}

	public function testAFailingPruneStillRemovesTheEventRows(): void {
		$this->sequence->method('prune')->willThrowException(new \RuntimeException('table locked'));
		$this->eventRows->expects($this->once())->method('deleteWrittenBefore');

		$this->job->start($this->createMock(IJobList::class));
	}
}
