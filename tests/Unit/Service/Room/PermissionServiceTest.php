<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\Room;

use OCA\SendentSynchroniser\Db\RoomPermissionMapper;
use OCA\SendentSynchroniser\Service\Room\PermissionService;
use OCA\SendentSynchroniser\Service\Room\RoomValidationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PermissionServiceTest extends TestCase {

	private RoomPermissionMapper&MockObject $mapper;
	private PermissionService $svc;

	protected function setUp(): void {
		$this->mapper = $this->createMock(RoomPermissionMapper::class);
		$this->svc = new PermissionService($this->mapper);
	}

	public function testGrantInsertsPermission(): void {
		$this->mapper->expects($this->once())->method('insert')->willReturnArgument(0);
		$p = $this->svc->grantOnRoom('boardroom-a', 'booker', 'user', 'alice');
		$this->assertSame('boardroom-a', $p->getRoomId());
		$this->assertSame('booker', $p->getRole());
		$this->assertSame('user', $p->getPrincipalType());
		$this->assertSame('alice', $p->getPrincipalId());
	}

	public function testGrantRejectsUnknownRole(): void {
		$this->expectException(RoomValidationException::class);
		$this->svc->grantOnRoom('boardroom-a', 'wizard', 'user', 'alice');
	}

	public function testGrantRejectsUnknownPrincipalType(): void {
		$this->expectException(RoomValidationException::class);
		$this->svc->grantOnRoom('boardroom-a', 'booker', 'service', 'alice');
	}
}
