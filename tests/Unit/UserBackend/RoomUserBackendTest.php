<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\UserBackend;

use OC\User\Backend;
use OCA\SendentSynchroniser\Db\Room;
use OCA\SendentSynchroniser\Db\RoomMapper;
use OCA\SendentSynchroniser\UserBackend\RoomUserBackend;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RoomUserBackendTest extends TestCase {

	private RoomMapper&MockObject $mapper;
	private RoomUserBackend $backend;

	protected function setUp(): void {
		$this->mapper = $this->createMock(RoomMapper::class);
		$this->backend = new RoomUserBackend($this->mapper);
	}

	public function testUserExistsTrueForKnownRoom(): void {
		$room = new Room();
		$room->setId('boardroom-a');
		$room->setName('Boardroom A');
		$this->mapper->method('findById')->with('boardroom-a')->willReturn($room);

		$this->assertTrue($this->backend->userExists(RoomUserBackend::PREFIX . 'boardroom-a'));
	}

	public function testUserExistsFalseForUnknown(): void {
		$this->mapper->method('findById')
			->willThrowException(new DoesNotExistException('nope'));
		$this->assertFalse($this->backend->userExists(RoomUserBackend::PREFIX . 'nope'));
	}

	public function testUserExistsFalseForNonRoomPrefix(): void {
		$this->assertFalse($this->backend->userExists('alice'));
	}

	public function testGetDisplayNameReturnsRoomName(): void {
		$room = new Room();
		$room->setId('boardroom-a');
		$room->setName('Boardroom A');
		$this->mapper->method('findById')->with('boardroom-a')->willReturn($room);

		$this->assertSame('Boardroom A', $this->backend->getDisplayName(RoomUserBackend::PREFIX . 'boardroom-a'));
	}

	public function testGetUsersReturnsEmptyToHideFromPickers(): void {
		$this->assertSame([], $this->backend->getUsers());
		$this->assertSame([], $this->backend->getUsers('search'));
	}

	public function testGetDisplayNamesReturnsEmpty(): void {
		$this->assertSame([], $this->backend->getDisplayNames());
		$this->assertSame([], $this->backend->getDisplayNames('search'));
	}

	public function testCheckPasswordAlwaysFalse(): void {
		$this->assertFalse($this->backend->checkPassword(RoomUserBackend::PREFIX . 'boardroom-a', 'anything'));
	}

	public function testCountUsersZero(): void {
		$this->assertSame(0, $this->backend->countUsers());
	}

	public function testImplementsActionsExposesPasswordAndDisplayName(): void {
		$this->assertTrue($this->backend->implementsActions(Backend::CHECK_PASSWORD));
		$this->assertTrue($this->backend->implementsActions(Backend::GET_DISPLAYNAME));
		$this->assertTrue($this->backend->implementsActions(Backend::COUNT_USERS));
		$this->assertFalse($this->backend->implementsActions(Backend::CREATE_USER));
	}
}
