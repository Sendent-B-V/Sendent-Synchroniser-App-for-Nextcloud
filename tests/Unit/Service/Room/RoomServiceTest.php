<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\Room;

use OCA\SendentSynchroniser\Db\Room;
use OCA\SendentSynchroniser\Db\RoomBindingMapper;
use OCA\SendentSynchroniser\Db\RoomFacilityMapper;
use OCA\SendentSynchroniser\Db\RoomMapper;
use OCA\SendentSynchroniser\Db\RoomPermissionMapper;
use OCA\SendentSynchroniser\Service\Room\CalDAVService;
use OCA\SendentSynchroniser\Service\Room\HiddenUserService;
use OCA\SendentSynchroniser\Service\Room\RoomService;
use OCA\SendentSynchroniser\Service\Room\RoomValidationException;
use OCP\Calendar\Room\IManager as IRoomManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RoomServiceTest extends TestCase {

	private RoomMapper&MockObject $rooms;
	private RoomFacilityMapper&MockObject $facilities;
	private RoomPermissionMapper&MockObject $permissions;
	private RoomBindingMapper&MockObject $bindings;
	private HiddenUserService&MockObject $hiddenUsers;
	private CalDAVService&MockObject $calDav;
	private RoomService $svc;

	protected function setUp(): void {
		$this->rooms = $this->createMock(RoomMapper::class);
		$this->facilities = $this->createMock(RoomFacilityMapper::class);
		$this->permissions = $this->createMock(RoomPermissionMapper::class);
		$this->bindings = $this->createMock(RoomBindingMapper::class);
		$this->hiddenUsers = $this->createMock(HiddenUserService::class);
		$this->calDav = $this->createMock(CalDAVService::class);
		$this->svc = new RoomService(
			$this->rooms, $this->facilities, $this->permissions,
			$this->bindings, $this->hiddenUsers, $this->calDav,
			$this->createMock(IRoomManager::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testCreateInsertsRoomBeforeProvisioningCalendar(): void {
		$this->hiddenUsers->method('uidFor')->with('boardroom-a')->willReturn('_room_boardroom-a');
		$this->hiddenUsers->method('principalUriFor')->with('boardroom-a')->willReturn('principals/users/_room_boardroom-a');
		$this->calDav->method('calendarUriFor')->with('_room_boardroom-a')->willReturn('/remote.php/dav/calendars/_room_boardroom-a/room/');

		// Order: insert FIRST, then provision calendar. Verifies the bug fix
		// where provisionCalendar previously ran before the room row existed,
		// which broke userExists() lookups.
		$callOrder = [];
		$this->rooms->expects($this->once())
			->method('insert')
			->willReturnCallback(function ($room) use (&$callOrder) {
				$callOrder[] = 'insert';
				return $room;
			});
		$this->calDav->expects($this->once())
			->method('provision')
			->with('principals/users/_room_boardroom-a', 'Boardroom A')
			->willReturnCallback(function () use (&$callOrder) { $callOrder[] = 'provision'; });

		$this->svc->create([
			'id' => 'boardroom-a',
			'name' => 'Boardroom A',
			'capacity' => 12,
		]);

		$this->assertSame(['insert', 'provision'], $callOrder);
	}

	public function testCreateRollsBackInsertIfCalendarProvisioningFails(): void {
		$this->hiddenUsers->method('uidFor')->willReturn('_room_boardroom-a');
		$this->hiddenUsers->method('principalUriFor')->willReturn('principals/users/_room_boardroom-a');
		$this->calDav->method('calendarUriFor')->willReturn('/remote.php/dav/calendars/_room_boardroom-a/room/');

		$this->rooms->expects($this->once())->method('insert');
		$this->calDav->expects($this->once())
			->method('provision')
			->willThrowException(new \RuntimeException('CalDAV failed'));
		$this->rooms->expects($this->once())->method('deleteById')->with('boardroom-a');

		$this->expectException(\RuntimeException::class);
		$this->svc->create([
			'id' => 'boardroom-a',
			'name' => 'Boardroom A',
		]);
	}

	public function testCreateRejectsInvalidId(): void {
		$this->expectException(RoomValidationException::class);
		$this->svc->create(['id' => 'BAD ID', 'name' => 'x']);
	}

	public function testCreateRejectsEmptyName(): void {
		$this->expectException(RoomValidationException::class);
		$this->svc->create(['id' => 'r1', 'name' => '']);
	}

	public function testDeleteCascadesAndDeprovisions(): void {
		$room = new Room();
		$room->setId('boardroom-a');
		$room->setBackingPrincipalUri('principals/users/_room_boardroom-a');
		$this->rooms->method('findById')->with('boardroom-a')->willReturn($room);

		$this->bindings->expects($this->once())->method('deleteByRoomId')->with('boardroom-a');
		$this->permissions->expects($this->once())->method('deleteByRoomId')->with('boardroom-a');
		$this->facilities->expects($this->once())->method('deleteByRoomId')->with('boardroom-a');
		$this->calDav->expects($this->once())->method('deprovision')->with('principals/users/_room_boardroom-a');
		$this->hiddenUsers->expects($this->once())->method('deprovision')->with('boardroom-a');
		$this->rooms->expects($this->once())->method('deleteById')->with('boardroom-a');

		$this->svc->delete('boardroom-a');
	}
}
