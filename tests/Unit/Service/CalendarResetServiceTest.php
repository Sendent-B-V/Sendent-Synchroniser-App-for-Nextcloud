<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\SendentSynchroniser\Service\CalendarResetService;
use OCA\SendentSynchroniser\Service\CollectionService;
use OCA\SendentSynchroniser\Service\SyncUserService;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CalendarResetServiceTest extends TestCase {

	private const MARKED = "BEGIN:VEVENT\r\nUID:a\r\nX-SENDENT-ID:123\r\nEND:VEVENT";
	private const CLEAN = "BEGIN:VEVENT\r\nUID:b\r\nEND:VEVENT";

	private CalDavBackend&MockObject $calDav;
	private CollectionService&MockObject $collections;
	private SyncUserService&MockObject $syncUserService;
	private IConfig&MockObject $config;
	private CalendarResetService $svc;

	protected function setUp(): void {
		$this->calDav = $this->createMock(CalDavBackend::class);
		$this->collections = $this->createMock(CollectionService::class);
		$this->syncUserService = $this->createMock(SyncUserService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->svc = new CalendarResetService(
			$this->calDav,
			$this->collections,
			$this->syncUserService,
			$this->config,
			new NullLogger()
		);
	}

	private function givenLegacyToken(bool $has): void {
		$this->syncUserService->method('hasLegacyToken')->with('alice')->willReturn($has);
	}

	/**
	 * @param array $calendars Rows as returned by getCalendarsForUser()
	 * @param array<int, array<string, string>> $contents calendarId => [objectUri => calendardata]
	 */
	private function givenCalendars(array $calendars, array $contents = []): void {
		$this->calDav->method('getCalendarsForUser')->willReturn($calendars);
		$this->calDav->method('getCalendarObjects')->willReturnCallback(
			fn (int $id): array => array_map(
				static fn (string $uri): array => ['uri' => $uri],
				array_keys($contents[$id] ?? [])
			)
		);
		$this->calDav->method('getMultipleCalendarObjects')->willReturnCallback(
			function (int $id, array $uris) use ($contents): array {
				$out = [];
				foreach ($uris as $uri) {
					$out[] = ['uri' => $uri, 'calendardata' => $contents[$id][$uri] ?? ''];
				}
				return $out;
			}
		);
	}

	public function testShouldOfferFalseWithoutLegacyToken(): void {
		$this->givenLegacyToken(false);
		$this->calDav->expects($this->never())->method('getCalendarsForUser');
		$this->assertFalse($this->svc->shouldOffer('alice'));
	}

	public function testShouldOfferFalseWhenNoCalendarHoldsTheMarker(): void {
		$this->givenLegacyToken(true);
		$this->givenCalendars(
			[['id' => 1, 'uri' => 'personal'], ['id' => 11, 'uri' => 'exchange']],
			[1 => ['a.ics' => self::CLEAN], 11 => ['b.ics' => self::CLEAN]]
		);
		$d = $this->svc->diagnose('alice');
		$this->assertFalse($d['offer']);
		$this->assertSame('noSendentMarker', $d['declinedAt']);
		$this->assertNull($d['targetUri']);
	}

	public function testTargetIsTheCalendarHoldingTheMarkerNotTheDefault(): void {
		// The whole point: selection follows the legacy data, not any setting.
		// Here the marker sits in 'personal' while NC's default is 'exchange'.
		$this->givenLegacyToken(true);
		$this->collections->method('detectUserDefaultCalendar')->willReturn('exchange');
		$this->givenCalendars(
			[['id' => 11, 'uri' => 'exchange'], ['id' => 1, 'uri' => 'personal']],
			[11 => ['b.ics' => self::CLEAN], 1 => ['a.ics' => self::MARKED]]
		);

		$d = $this->svc->diagnose('alice');
		$this->assertTrue($d['offer']);
		$this->assertSame('personal', $d['targetUri']);
		$this->assertSame(1, $d['targetCalendarId']);
		// The NC default is recorded, but did not drive the choice.
		$this->assertSame('exchange', $d['ncDefaultUri']);
	}

	public function testSelectionIgnoresUriAndDisplayNameSoLocalesAreSafe(): void {
		// A Dutch user's calendar: neither the URI nor the localised display
		// name is ever matched against a hardcoded string like 'personal'.
		$this->givenLegacyToken(true);
		$this->givenCalendars(
			[['id' => 42, 'uri' => 'agenda-2019', '{DAV:}displayname' => 'Persoonlijk']],
			[42 => ['a.ics' => self::MARKED]]
		);

		$d = $this->svc->diagnose('alice');
		$this->assertTrue($d['offer']);
		$this->assertSame('agenda-2019', $d['targetUri']);
	}

	public function testShouldOfferDeclinesWhenMultipleCalendarsAreMarked(): void {
		$this->givenLegacyToken(true);
		$this->givenCalendars(
			[['id' => 1, 'uri' => 'personal'], ['id' => 11, 'uri' => 'exchange']],
			[1 => ['a.ics' => self::MARKED], 11 => ['b.ics' => self::MARKED]]
		);

		$d = $this->svc->diagnose('alice');
		$this->assertFalse($d['offer']);
		$this->assertSame('multipleMarkedCalendars', $d['declinedAt']);
		$this->assertSame(['personal', 'exchange'], $d['markedUris']);
		$this->assertNull($d['targetUri']);
	}

	public function testTrashbinnedCalendarsAreSkipped(): void {
		// Already deleted by the user — re-creating one is not a clean-up
		// they asked for, so it must not be selected.
		$this->givenLegacyToken(true);
		$this->givenCalendars(
			[['id' => 7, 'uri' => 'personal', '{http://nextcloud.com/ns}deleted-at' => 1750000000]],
			[7 => ['a.ics' => self::MARKED]]
		);

		$d = $this->svc->diagnose('alice');
		$this->assertFalse($d['offer']);
		$this->assertSame('noSendentMarker', $d['declinedAt']);
	}

	public function testDiagnosticsReportEveryCalendarScanned(): void {
		$this->givenLegacyToken(true);
		$this->givenCalendars(
			[['id' => 1, 'uri' => 'personal', '{DAV:}displayname' => 'Personal']],
			[1 => ['a.ics' => self::MARKED, 'b.ics' => self::CLEAN]]
		);

		$d = $this->svc->diagnose('alice');
		$this->assertSame([[
			'uri' => 'personal',
			'id' => 1,
			'displayname' => 'Personal',
			'objectCount' => 2,
			'marked' => true,
		]], $d['userCalendars']);
	}

	public function testResetRecreatesCalendarWithSameProps(): void {
		$this->givenLegacyToken(true);
		$this->givenCalendars(
			[[
				'id' => 7,
				'uri' => 'personal',
				'{DAV:}displayname' => 'Persoonlijk',
				'{http://apple.com/ns/ical/}calendar-color' => '#FF00FF',
				'{urn:ietf:params:xml:ns:caldav}calendar-timezone' => 'BEGIN:VCALENDAR...Europe/Amsterdam...',
			]],
			[7 => ['a.ics' => self::MARKED]]
		);

		$this->calDav->expects($this->once())->method('deleteCalendar')->with(7, true);
		$this->calDav->expects($this->once())->method('createCalendar')
			->with('principals/users/alice', 'personal', [
				'components' => 'VEVENT',
				'{DAV:}displayname' => 'Persoonlijk',
				'{http://apple.com/ns/ical/}calendar-color' => '#FF00FF',
				'{urn:ietf:params:xml:ns:caldav}calendar-timezone' => 'BEGIN:VCALENDAR...Europe/Amsterdam...',
			])
			->willReturn(99);
		$this->config->expects($this->never())->method('setUserValue');

		$this->assertTrue($this->svc->reset('alice'));
	}

	public function testResetRepointsNcDefaultCalendar(): void {
		$this->givenLegacyToken(true);
		$this->config->method('getUserValue')
			->with('alice', 'dav', 'defaultCalendarId', '')
			->willReturn('7');
		$this->givenCalendars(
			[['id' => 7, 'uri' => 'personal', '{DAV:}displayname' => 'Personal']],
			[7 => ['a.ics' => self::MARKED]]
		);
		$this->calDav->method('createCalendar')->willReturn(99);

		$this->config->expects($this->once())->method('setUserValue')
			->with('alice', 'dav', 'defaultCalendarId', '99');
		$this->assertTrue($this->svc->reset('alice'));
	}

	public function testResetRefusesWithoutLegacyToken(): void {
		// Once activate() has swapped the token, a replayed reset must no-op.
		$this->givenLegacyToken(false);
		$this->calDav->expects($this->never())->method('deleteCalendar');
		$this->assertFalse($this->svc->reset('alice'));
	}

	public function testResetRefusesWhenNoCalendarIsMarked(): void {
		// The endpoint is a hard delete, so it re-runs the decision itself
		// instead of trusting that the client asked the status endpoint first.
		$this->givenLegacyToken(true);
		$this->givenCalendars(
			[['id' => 1, 'uri' => 'personal']],
			[1 => ['a.ics' => self::CLEAN]]
		);
		$this->calDav->expects($this->never())->method('deleteCalendar');
		$this->assertFalse($this->svc->reset('alice'));
	}

	public function testResetRefusesWhenMultipleCalendarsAreMarked(): void {
		$this->givenLegacyToken(true);
		$this->givenCalendars(
			[['id' => 1, 'uri' => 'personal'], ['id' => 11, 'uri' => 'exchange']],
			[1 => ['a.ics' => self::MARKED], 11 => ['b.ics' => self::MARKED]]
		);
		$this->calDav->expects($this->never())->method('deleteCalendar');
		$this->assertFalse($this->svc->reset('alice'));
	}
}
