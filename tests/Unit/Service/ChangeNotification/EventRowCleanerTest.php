<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\Db\EventChangeRowMapper;
use OCA\SendentSynchroniser\Service\ChangeNotification\EventRowCleaner;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EventRowCleanerTest extends TestCase {

	private EventChangeRowMapper&MockObject $eventRows;
	private LoggerInterface&MockObject $logger;
	private EventRowCleaner $cleaner;

	protected function setUp(): void {
		$this->eventRows = $this->createMock(EventChangeRowMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->cleaner = new EventRowCleaner($this->eventRows, $this->logger);
	}

	public function testTheRowsAreRemovedForThePrincipalOfTheUser(): void {
		$this->eventRows->expects($this->once())
			->method('deleteForPrincipal')
			->with('principals/users/alice')
			->willReturn(2);
		$this->logger->expects($this->never())->method('error');

		$this->cleaner->removeForUser('alice');
	}

	public function testAFailedRemovalIsLoggedAndNotThrown(): void {
		$this->eventRows->method('deleteForPrincipal')
			->willThrowException(new \RuntimeException('database is away'));

		$this->logger->expects($this->once())->method('error');

		$this->cleaner->removeForUser('alice');
	}
}
