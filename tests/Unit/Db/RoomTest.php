<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Db;

use OCA\SendentSynchroniser\Db\Room;
use PHPUnit\Framework\TestCase;

class RoomTest extends TestCase {

	public function testJsonSerializeIncludesCoreFields(): void {
		$room = new Room();
		$room->setId('boardroom-a');
		$room->setName('Boardroom A');
		$room->setEmail('boardroom-a@contoso.com');
		$room->setCapacity(12);
		$room->setRoomType('meeting-room');
		$room->setBackingPrincipalUri('principals/users/_room_boardroom-a');
		$room->setBackingCalendarUri('/remote.php/dav/calendars/_room_boardroom-a/room/');
		$room->setActive(true);

		$json = $room->jsonSerialize();

		$this->assertSame('boardroom-a', $json['id']);
		$this->assertSame('Boardroom A', $json['name']);
		$this->assertSame('boardroom-a@contoso.com', $json['email']);
		$this->assertSame(12, $json['capacity']);
		$this->assertSame('meeting-room', $json['roomType']);
		$this->assertSame('principals/users/_room_boardroom-a', $json['backingPrincipalUri']);
		$this->assertTrue($json['active']);
	}

	public function testActiveDefaultsToTrueWhenSetTrue(): void {
		$room = new Room();
		$room->setActive(true);
		$this->assertTrue($room->getActive());
	}
}
