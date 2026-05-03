<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\Room;

use OCA\SendentSynchroniser\Service\Room\HiddenUserService;
use OCA\SendentSynchroniser\UserBackend\RoomUserBackend;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class HiddenUserServiceTest extends TestCase {

	private IUserManager&MockObject $userManager;
	private RoomUserBackend&MockObject $backend;
	private HiddenUserService $svc;

	protected function setUp(): void {
		$this->userManager = $this->createMock(IUserManager::class);
		$this->backend = $this->createMock(RoomUserBackend::class);
		$this->svc = new HiddenUserService($this->userManager, $this->backend);
	}

	public function testProvisionUidIsPrefixed(): void {
		$this->assertSame('_room_boardroom-a', $this->svc->uidFor('boardroom-a'));
	}

	public function testProvisionIsNoop(): void {
		// provision() is intentionally a no-op now — the room "exists" via
		// RoomUserBackend::userExists() the moment the room row is in the DB.
		// We don't call createUserFromBackend (NC requires ICreateUserBackend
		// for that, which doesn't fit a virtual lookup-only backend).
		$this->userManager->expects($this->never())->method('createUserFromBackend');
		$this->svc->provision('boardroom-a');
		$this->assertTrue(true);
	}

	public function testDeprovisionDeletesUser(): void {
		$user = $this->createMock(IUser::class);
		$user->expects($this->once())->method('delete')->willReturn(true);
		$this->userManager->method('get')->with('_room_boardroom-a')->willReturn($user);

		$this->svc->deprovision('boardroom-a');
	}

	public function testDeprovisionIsNoopIfMissing(): void {
		$this->userManager->method('get')->willReturn(null);
		$this->svc->deprovision('boardroom-a');
		$this->assertTrue(true);
	}
}
