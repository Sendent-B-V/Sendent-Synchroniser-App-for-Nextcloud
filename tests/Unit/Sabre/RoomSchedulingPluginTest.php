<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Sabre;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\SendentSynchroniser\Db\Room;
use OCA\SendentSynchroniser\Db\RoomBinding;
use OCA\SendentSynchroniser\Db\RoomBindingMapper;
use OCA\SendentSynchroniser\Db\RoomMapper;
use OCA\SendentSynchroniser\Sabre\RoomSchedulingPlugin;
use OCA\SendentSynchroniser\UserBackend\RoomUserBackend;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sabre\VObject\ITip\Message;

class RoomSchedulingPluginTest extends TestCase {

	private RoomMapper&MockObject $rooms;
	private RoomBindingMapper&MockObject $bindings;
	private CalDavBackend&MockObject $calDav;
	private RoomSchedulingPlugin $plugin;

	protected function setUp(): void {
		$this->rooms = $this->createMock(RoomMapper::class);
		$this->bindings = $this->createMock(RoomBindingMapper::class);
		$this->calDav = $this->createMock(CalDavBackend::class);
		$this->plugin = new RoomSchedulingPlugin($this->rooms, $this->bindings, new NullLogger(), $this->calDav);
	}

	public function testIgnoresMessagesAddressedToNonRoomPrincipals(): void {
		$msg = $this->buildMessage('mailto:alice@contoso.com');
		$this->rooms->expects($this->never())->method('findById');
		$this->plugin->onSchedule($msg);
		$this->assertNull($msg->scheduleStatus);
	}

	public function testIgnoresMessagesForUnknownRoom(): void {
		$this->rooms->method('findById')->willThrowException(new DoesNotExistException('x'));
		$msg = $this->buildMessage('principal:principals/users/' . RoomUserBackend::PREFIX . 'unknown');
		$this->plugin->onSchedule($msg);
		$this->assertNull($msg->scheduleStatus);
	}

	public function testNoOpForBoundRoom(): void {
		$room = new Room(); $room->setId('boardroom-a');
		$binding = new RoomBinding(); $binding->setRoomId('boardroom-a');
		$this->rooms->method('findById')->with('boardroom-a')->willReturn($room);
		$this->bindings->method('findByRoomIdOrNull')->with('boardroom-a')->willReturn($binding);

		$msg = $this->buildMessage('principal:principals/users/' . RoomUserBackend::PREFIX . 'boardroom-a');
		$this->plugin->onSchedule($msg);
		$this->assertNull($msg->scheduleStatus);
	}

	public function testAcceptsWhenNoCalendarOverlap(): void {
		$room = new Room();
		$room->setId('boardroom-a');
		$room->setBackingPrincipalUri('principals/users/_room_boardroom-a');
		$this->rooms->method('findById')->willReturn($room);
		$this->bindings->method('findByRoomIdOrNull')->willReturn(null);

		$this->calDav->method('getCalendarByUri')->willReturn(['id' => 42]);
		$this->calDav->method('getCalendarObjects')->with(42)->willReturn([
			['uri' => 'other.ics'],
		]);
		$this->calDav->method('getCalendarObject')->with(42, 'other.ics')->willReturn([
			'uri' => 'other.ics',
			'calendardata' => "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:other\r\nDTSTART:20260502T140000Z\r\nDTEND:20260502T150000Z\r\nEND:VEVENT\r\nEND:VCALENDAR",
		]);

		$msg = $this->buildMessage('principal:principals/users/' . RoomUserBackend::PREFIX . 'boardroom-a');
		$this->plugin->onSchedule($msg);
		$this->assertSame('2.0;Success', $msg->scheduleStatus);
	}

	public function testDeclinesWhenCalendarOverlap(): void {
		$room = new Room();
		$room->setId('boardroom-a');
		$room->setBackingPrincipalUri('principals/users/_room_boardroom-a');
		$this->rooms->method('findById')->willReturn($room);
		$this->bindings->method('findByRoomIdOrNull')->willReturn(null);

		$this->calDav->method('getCalendarByUri')->willReturn(['id' => 42]);
		$this->calDav->method('getCalendarObjects')->with(42)->willReturn([
			['uri' => 'other.ics'],
		]);
		$this->calDav->method('getCalendarObject')->with(42, 'other.ics')->willReturn([
			'uri' => 'other.ics',
			'calendardata' => "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:other\r\nDTSTART:20260502T100000Z\r\nDTEND:20260502T110000Z\r\nEND:VEVENT\r\nEND:VCALENDAR",
		]);

		$msg = $this->buildMessage('principal:principals/users/' . RoomUserBackend::PREFIX . 'boardroom-a');
		$this->plugin->onSchedule($msg);
		$this->assertSame('5.3;Conflict', $msg->scheduleStatus);
	}

	private function buildMessage(string $recipient): Message {
		$msg = new Message();
		$msg->method = 'REQUEST';
		$msg->recipient = $recipient;
		$msg->sender = 'mailto:alice@contoso.com';
		$msg->message = new \Sabre\VObject\Component\VCalendar([
			'VEVENT' => [
				'UID' => 'evt-1',
				'DTSTART' => '20260502T100000Z',
				'DTEND' => '20260502T110000Z',
				'SUMMARY' => 'meeting',
			],
		]);
		return $msg;
	}
}
