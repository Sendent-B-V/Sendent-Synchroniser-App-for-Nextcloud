<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\SendentSynchroniser\Db\SyncUser;
use OCA\SendentSynchroniser\Db\SyncUserMapper;
use OCA\SendentSynchroniser\Service\CalendarResetService;
use OCA\SendentSynchroniser\Service\CollectionService;
use OCA\SendentSynchroniser\Service\SyncUserService;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CalendarResetServiceTest extends TestCase {

	private CalDavBackend&MockObject $calDav;
	private CollectionService&MockObject $collections;
	private SyncUserMapper&MockObject $syncUsers;
	private SyncUserService&MockObject $syncUserService;
	private IConfig&MockObject $config;
	private CalendarResetService $svc;

	protected function setUp(): void {
		$this->calDav = $this->createMock(CalDavBackend::class);
		$this->collections = $this->createMock(CollectionService::class);
		$this->syncUsers = $this->createMock(SyncUserMapper::class);
		$this->syncUserService = $this->createMock(SyncUserService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->svc = new CalendarResetService(
			$this->calDav,
			$this->collections,
			$this->syncUsers,
			$this->syncUserService,
			$this->config,
			new NullLogger()
		);
	}

	private function givenLegacyToken(bool $has): void {
		$this->syncUserService->method('hasLegacyToken')->with('alice')->willReturn($has);
	}

	private function givenTargetCalendar(string $uri = 'personal'): void {
		$syncUser = new SyncUser();
		$syncUser->setUid('alice');
		$syncUser->setCalendar($uri);
		$this->syncUsers->method('findByUid')->with('alice')->willReturn([$syncUser]);
	}

	public function testShouldOfferFalseWithoutLegacyToken(): void {
		$this->givenLegacyToken(false);
		$this->calDav->expects($this->never())->method('getCalendarsForUser');
		$this->assertFalse($this->svc->shouldOffer('alice'));
	}

	public function testShouldOfferFalseWhenCalendarMissing(): void {
		$this->givenLegacyToken(true);
		$this->givenTargetCalendar();
		$this->calDav->method('getCalendarsForUser')->willReturn([]);
		$this->assertFalse($this->svc->shouldOffer('alice'));
	}

	public function testShouldOfferFalseWhenNoSendentMarker(): void {
		$this->givenLegacyToken(true);
		$this->givenTargetCalendar();
		$this->calDav->method('getCalendarsForUser')->willReturn([
			['id' => 7, 'uri' => 'personal', '{DAV:}displayname' => 'Persoonlijk'],
		]);
		$this->calDav->method('getCalendarObjects')->with(7)->willReturn([
			['uri' => 'a.ics'],
		]);
		$this->calDav->method('getMultipleCalendarObjects')->with(7, ['a.ics'])->willReturn([
			['uri' => 'a.ics', 'calendardata' => "BEGIN:VEVENT\r\nUID:a\r\nEND:VEVENT"],
		]);
		$this->assertFalse($this->svc->shouldOffer('alice'));
	}

	public function testShouldOfferTrueWhenMarkerFound(): void {
		$this->givenLegacyToken(true);
		$this->givenTargetCalendar();
		$this->calDav->method('getCalendarsForUser')->willReturn([
			['id' => 7, 'uri' => 'personal', '{DAV:}displayname' => 'Persoonlijk'],
		]);
		$this->calDav->method('getCalendarObjects')->with(7)->willReturn([
			['uri' => 'a.ics'],
		]);
		$this->calDav->method('getMultipleCalendarObjects')->with(7, ['a.ics'])->willReturn([
			['uri' => 'a.ics', 'calendardata' => "BEGIN:VEVENT\r\nUID:a\r\nX-SENDENT-ID:123\r\nEND:VEVENT"],
		]);
		$this->assertTrue($this->svc->shouldOffer('alice'));
	}

	public function testShouldOfferScansTrashbinnedCalendar(): void {
		// A calendar deleted via the web UI sits in the trashbin and still
		// occupies the URI — it must be scanned (and later purged) too.
		$this->givenLegacyToken(true);
		$this->givenTargetCalendar();
		$this->calDav->method('getCalendarsForUser')->willReturn([
			['id' => 7, 'uri' => 'personal', '{http://nextcloud.com/ns}deleted-at' => 1750000000],
		]);
		$this->calDav->method('getCalendarObjects')->with(7)->willReturn([
			['uri' => 'a.ics'],
		]);
		$this->calDav->method('getMultipleCalendarObjects')->with(7, ['a.ics'])->willReturn([
			['uri' => 'a.ics', 'calendardata' => "BEGIN:VEVENT\r\nUID:a\r\nX-SENDENT-ID:123\r\nEND:VEVENT"],
		]);
		$this->assertTrue($this->svc->shouldOffer('alice'));
	}

	public function testShouldOfferPrefersLiveCalendarOverTrashbinnedTwin(): void {
		// Unique index on (principaluri, uri) means live+trashed twins cannot
		// coexist at the same URI, but different URIs can — the live target wins.
		$this->givenLegacyToken(true);
		$this->givenTargetCalendar();
		$this->calDav->method('getCalendarsForUser')->willReturn([
			['id' => 5, 'uri' => 'other', '{http://nextcloud.com/ns}deleted-at' => 1750000000],
			['id' => 7, 'uri' => 'personal', '{DAV:}displayname' => 'Persoonlijk'],
		]);
		$this->calDav->method('getCalendarObjects')->with(7)->willReturn([]);
		$this->assertFalse($this->svc->shouldOffer('alice'));
	}

	public function testTargetCalendarFallsBackToAdminDefault(): void {
		$this->syncUsers->method('findByUid')->with('bob')->willReturn([]);
		$this->collections->method('getDefaultCalendar')->willReturn('personal');
		$this->assertSame('personal', $this->svc->targetCalendarUri('bob'));
	}
}
