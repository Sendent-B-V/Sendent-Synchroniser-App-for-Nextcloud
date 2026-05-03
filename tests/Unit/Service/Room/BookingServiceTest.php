<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\Room;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\SendentSynchroniser\Db\Room;
use OCA\SendentSynchroniser\Db\RoomMapper;
use OCA\SendentSynchroniser\Service\Room\BookingService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BookingServiceTest extends TestCase {

	private CalDavBackend&MockObject $calDav;
	private RoomMapper&MockObject $rooms;
	private BookingService $svc;

	protected function setUp(): void {
		$this->calDav = $this->createMock(CalDavBackend::class);
		$this->rooms = $this->createMock(RoomMapper::class);
		$this->svc = new BookingService($this->calDav, $this->rooms);
	}

	public function testListInRangeReturnsCalendarObjects(): void {
		$r = new Room();
		$r->setId('boardroom-a');
		$r->setBackingPrincipalUri('principals/users/_room_boardroom-a');
		$this->rooms->method('findById')->willReturn($r);
		$this->calDav->method('getCalendarByUri')->willReturn(['id' => 42]);
		$this->calDav->method('getCalendarObjects')->with(42)->willReturn([
			['uri' => 'evt-1.ics'],
		]);
		$this->calDav->method('getCalendarObject')->with(42, 'evt-1.ics')
			->willReturn(['uri' => 'evt-1.ics', 'calendardata' => 'BEGIN:VCALENDAR...']);

		$events = $this->svc->listInRange(
			'boardroom-a',
			new \DateTimeImmutable('2026-05-01T00:00:00Z'),
			new \DateTimeImmutable('2026-06-01T00:00:00Z')
		);
		$this->assertCount(1, $events);
		$this->assertSame('BEGIN:VCALENDAR...', $events[0]['calendardata']);
	}

	public function testDeleteByUidLooksUpAndDeletes(): void {
		$r = new Room();
		$r->setId('boardroom-a');
		$r->setBackingPrincipalUri('principals/users/_room_boardroom-a');
		$this->rooms->method('findById')->willReturn($r);
		$this->calDav->method('getCalendarByUri')->willReturn(['id' => 42]);
		$this->calDav->method('getCalendarObjects')->with(42)->willReturn([
			['uri' => 'evt-1.ics'],
			['uri' => 'other.ics'],
		]);
		$this->calDav->method('getCalendarObject')->willReturnCallback(
			fn ($calId, $uri) => match ($uri) {
				'evt-1.ics' => ['uri' => 'evt-1.ics', 'calendardata' => "BEGIN:VEVENT\r\nUID:evt-1\r\nEND:VEVENT"],
				'other.ics' => ['uri' => 'other.ics', 'calendardata' => "BEGIN:VEVENT\r\nUID:other\r\nEND:VEVENT"],
				default => null,
			}
		);

		$this->calDav->expects($this->once())->method('deleteCalendarObject')->with(42, 'evt-1.ics');
		$this->svc->deleteByUid('boardroom-a', 'evt-1');
	}
}
