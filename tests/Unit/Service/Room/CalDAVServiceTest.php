<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\Room;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\SendentSynchroniser\Service\Room\CalDAVService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CalDAVServiceTest extends TestCase {

	private CalDavBackend&MockObject $calDav;
	private CalDAVService $svc;

	protected function setUp(): void {
		$this->calDav = $this->createMock(CalDavBackend::class);
		$this->svc = new CalDAVService($this->calDav);
	}

	public function testProvisionCreatesCalendarForPrincipal(): void {
		$this->calDav->expects($this->once())
			->method('createCalendar')
			->with('principals/users/_room_boardroom-a', 'room', $this->arrayHasKey('{DAV:}displayname'));
		$this->svc->provision('principals/users/_room_boardroom-a', 'Boardroom A');
	}

	public function testCalendarUriFor(): void {
		$this->assertSame(
			'/remote.php/dav/calendars/_room_boardroom-a/room/',
			$this->svc->calendarUriFor('_room_boardroom-a')
		);
	}

	public function testDeprovisionDeletesCalendarIfPresent(): void {
		$this->calDav->method('getCalendarByUri')
			->with('principals/users/_room_boardroom-a', 'room')
			->willReturn(['id' => 42]);
		$this->calDav->expects($this->once())
			->method('deleteCalendar')
			->with(42);
		$this->svc->deprovision('principals/users/_room_boardroom-a');
	}

	public function testDeprovisionNoopIfMissing(): void {
		$this->calDav->method('getCalendarByUri')->willReturn(null);
		$this->calDav->expects($this->never())->method('deleteCalendar');
		$this->svc->deprovision('principals/users/_room_boardroom-a');
	}
}
