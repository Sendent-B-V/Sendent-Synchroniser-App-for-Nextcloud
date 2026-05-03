<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Calendar\Resource;

use OCA\SendentSynchroniser\Calendar\Resource\RoomBackend;
use OCA\SendentSynchroniser\Db\Room as RoomEntity;
use OCA\SendentSynchroniser\Db\RoomMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RoomBackendTest extends TestCase {

	private RoomMapper&MockObject $mapper;
	private RoomBackend $backend;

	protected function setUp(): void {
		$this->mapper = $this->createMock(RoomMapper::class);
		$this->backend = new RoomBackend($this->mapper);
	}

	public function testGetBackendIdentifierIsAppId(): void {
		$this->assertSame('sendentsynchroniser', $this->backend->getBackendIdentifier());
	}

	public function testGetAllRoomsListsActiveRooms(): void {
		$a = new RoomEntity(); $a->setId('a'); $a->setName('A'); $a->setActive(true);
		$b = new RoomEntity(); $b->setId('b'); $b->setName('B'); $b->setActive(false);
		$this->mapper->method('findAll')->willReturn([$a, $b]);

		$rooms = $this->backend->getAllRooms();
		$this->assertCount(1, $rooms);
		$this->assertSame('a', $rooms[0]->getId());
	}

	public function testGetRoomReturnsNullForUnknown(): void {
		$this->mapper->method('findById')->willThrowException(new DoesNotExistException('x'));
		$this->assertNull($this->backend->getRoom('x'));
	}

	public function testGetRoomReturnsRoomWithBackendThreaded(): void {
		$entity = new RoomEntity(); $entity->setId('a'); $entity->setName('A'); $entity->setActive(true);
		$this->mapper->method('findById')->with('a')->willReturn($entity);

		$room = $this->backend->getRoom('a');
		$this->assertNotNull($room);
		$this->assertSame($this->backend, $room->getBackend());
	}
}
