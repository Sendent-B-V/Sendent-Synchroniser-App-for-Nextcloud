<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\Room;

use OCA\SendentSynchroniser\Db\RoomGroupMapper;
use OCA\SendentSynchroniser\Db\RoomMapper;
use OCA\SendentSynchroniser\Db\RoomPermissionMapper;
use OCA\SendentSynchroniser\Service\Room\RoomGroupService;
use OCA\SendentSynchroniser\Service\Room\RoomValidationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RoomGroupServiceTest extends TestCase {

	private RoomGroupMapper&MockObject $groups;
	private RoomMapper&MockObject $rooms;
	private RoomPermissionMapper&MockObject $perms;
	private RoomGroupService $svc;

	protected function setUp(): void {
		$this->groups = $this->createMock(RoomGroupMapper::class);
		$this->rooms = $this->createMock(RoomMapper::class);
		$this->perms = $this->createMock(RoomPermissionMapper::class);
		$this->svc = new RoomGroupService($this->groups, $this->rooms, $this->perms);
	}

	public function testCreateRejectsInvalidId(): void {
		$this->expectException(RoomValidationException::class);
		$this->svc->create(['id' => '', 'name' => 'x']);
	}

	public function testCreateInsertsGroup(): void {
		$this->groups->expects($this->once())->method('insert');
		$this->svc->create(['id' => 'exec', 'name' => 'Executive Floor']);
	}

	public function testDeleteUnassignsRoomsAndDropsPermissions(): void {
		$room = new \OCA\SendentSynchroniser\Db\Room();
		$room->setId('boardroom-a');
		$room->setGroupId('exec');
		$this->rooms->method('findByGroupId')->with('exec')->willReturn([$room]);

		$this->rooms->expects($this->once())->method('update')->with($this->callback(
			fn ($r) => $r->getGroupId() === null
		));
		$this->perms->expects($this->once())->method('deleteByGroupId')->with('exec');
		$this->groups->expects($this->once())->method('deleteById')->with('exec');

		$this->svc->delete('exec');
	}
}
