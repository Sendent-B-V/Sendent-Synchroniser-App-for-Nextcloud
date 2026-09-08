<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\SendentSynchroniser\Service\CalendarResetService;
use OCA\SendentSynchroniser\Service\SyncUserService;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;

class CalendarResetServiceTest extends TestCase {

	private const MARKED = "BEGIN:VEVENT\r\nUID:a\r\nX-SENDENT-ID:123\r\nEND:VEVENT";
	private const CLEAN = "BEGIN:VEVENT\r\nUID:b\r\nEND:VEVENT";
	private const ALICE = 'principals/users/alice';
	private const BOB = 'principals/users/bob';
	private const OWNER = '{http://owncloud.org/ns}owner-principal';
	private const SCCS = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';

	private CalDavBackend&MockObject $calDav;
	private SyncUserService&MockObject $syncUserService;
	private IConfig&MockObject $config;
	private IDBConnection&MockObject $db;
	private CalendarResetService $svc;

	protected function setUp(): void {
		$this->calDav = $this->createMock(CalDavBackend::class);
		$this->syncUserService = $this->createMock(SyncUserService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->svc = new CalendarResetService(
			$this->calDav,
			$this->syncUserService,
			$this->config,
			$this->db,
			new NullLogger()
		);
	}

	private function givenOfferPending(bool $pending): void {
		$this->syncUserService->method('hasPendingResetOffer')->with('alice')->willReturn($pending);
	}

	/**
	 * Rows as getCalendarsForUser() returns them. Rows without an explicit
	 * owner-principal are treated as alice's own calendars.
	 *
	 * @param array<int, array<string, string>> $contents calendarId => [objectUri => calendardata]
	 */
	private function givenCalendars(array $calendars, array $contents = []): void {
		$rows = array_map(
			static fn (array $row): array => $row + ['principaluri' => self::ALICE, self::OWNER => self::ALICE],
			$calendars
		);
		$this->calDav->method('getCalendarsForUser')->with(self::ALICE)->willReturn($rows);
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

	/** A calendar owned by bob and shared with alice, as NC lists it for alice. */
	private function sharedByBob(int $id, string $uri): array {
		return [
			'id' => $id,
			'uri' => $uri . '_shared_by_bob',
			'principaluri' => self::ALICE,
			self::OWNER => self::BOB,
			'{http://owncloud.org/ns}read-only' => 0,
		];
	}

	// ---- gate -------------------------------------------------------------

	public function testShouldOfferFalseWithoutPendingOffer(): void {
		// Covers: flag cleared, no row, AND the admin setting disabled — the
		// service does not care which; SyncUserService owns that decision.
		$this->givenOfferPending(false);
		$this->calDav->expects($this->never())->method('getCalendarsForUser');
		$d = $this->svc->diagnose('alice');
		$this->assertFalse($d['offer']);
		$this->assertSame('noPendingResetOffer', $d['declinedAt']);
	}

	// ---- target selection -----------------------------------------------

	public function testShouldOfferFalseWhenNoCalendarHoldsTheMarker(): void {
		$this->givenOfferPending(true);
		$this->givenCalendars(
			[['id' => 1, 'uri' => 'personal'], ['id' => 11, 'uri' => 'exchange']],
			[1 => ['a.ics' => self::CLEAN], 11 => ['b.ics' => self::CLEAN]]
		);
		$d = $this->svc->diagnose('alice');
		$this->assertFalse($d['offer']);
		$this->assertSame('noSendentMarker', $d['declinedAt']);
		$this->assertNull($d['targetUri']);
		$this->assertNull($d['targetCalendar']);
	}

	public function testTargetIsTheCalendarHoldingTheMarker(): void {
		$this->givenOfferPending(true);
		$this->givenCalendars(
			[['id' => 11, 'uri' => 'exchange'], ['id' => 1, 'uri' => 'personal']],
			[11 => ['b.ics' => self::CLEAN], 1 => ['a.ics' => self::MARKED]]
		);

		$d = $this->svc->diagnose('alice');
		$this->assertTrue($d['offer']);
		$this->assertSame('personal', $d['targetUri']);
		$this->assertSame(1, $d['targetCalendarId']);
		$this->assertSame(1, $d['targetCalendar']['id']);
	}

	public function testSelectionIgnoresUriAndDisplayNameSoLocalesAreSafe(): void {
		$this->givenOfferPending(true);
		$this->givenCalendars(
			[['id' => 42, 'uri' => 'agenda-2019', '{DAV:}displayname' => 'Persoonlijk']],
			[42 => ['a.ics' => self::MARKED]]
		);

		$d = $this->svc->diagnose('alice');
		$this->assertTrue($d['offer']);
		$this->assertSame('agenda-2019', $d['targetUri']);
	}

	public function testShouldOfferDeclinesWhenMultipleCalendarsAreMarked(): void {
		$this->givenOfferPending(true);
		$this->givenCalendars(
			[['id' => 1, 'uri' => 'personal'], ['id' => 11, 'uri' => 'exchange']],
			[1 => ['a.ics' => self::MARKED], 11 => ['b.ics' => self::MARKED]]
		);

		$d = $this->svc->diagnose('alice');
		$this->assertFalse($d['offer']);
		$this->assertSame('multipleMarkedCalendars', $d['declinedAt']);
		$this->assertSame(['personal', 'exchange'], $d['markedUris']);
		$this->assertNull($d['targetUri']);
		$this->assertNull($d['targetCalendar']);
	}

	public function testTrashbinnedCalendarsAreSkipped(): void {
		$this->givenOfferPending(true);
		$this->givenCalendars(
			[['id' => 7, 'uri' => 'personal', '{http://nextcloud.com/ns}deleted-at' => 1750000000]],
			[7 => ['a.ics' => self::MARKED]]
		);

		$d = $this->svc->diagnose('alice');
		$this->assertFalse($d['offer']);
		$this->assertSame('noSendentMarker', $d['declinedAt']);
	}

	// ---- ownership ----------------------------------------------------------

	public function testSharedCalendarIsNeitherScannedNorSelected(): void {
		// Bob's synced calendar shared with alice: it carries the marker, but
		// it is bob's. Alice's own calendar is clean, so there is no offer.
		$this->givenOfferPending(true);
		$this->givenCalendars(
			[['id' => 1, 'uri' => 'personal'], $this->sharedByBob(50, 'personal')],
			[1 => ['a.ics' => self::CLEAN], 50 => ['x.ics' => self::MARKED]]
		);

		$d = $this->svc->diagnose('alice');
		$this->assertFalse($d['offer']);
		$this->assertSame('noSendentMarker', $d['declinedAt']);
		// Only alice's own calendar was scanned; bob's shared one was skipped.
		$this->assertSame([1], array_column($d['userCalendars'], 'id'));
		$this->assertSame(1, $d['skippedForeignCalendars']);
	}

	public function testSharedCalendarDoesNotCauseAmbiguity(): void {
		// Alice's own calendar is marked; bob's shared one too. Only alice's counts.
		$this->givenOfferPending(true);
		$this->givenCalendars(
			[['id' => 1, 'uri' => 'personal'], $this->sharedByBob(50, 'personal')],
			[1 => ['a.ics' => self::MARKED], 50 => ['x.ics' => self::MARKED]]
		);

		$d = $this->svc->diagnose('alice');
		$this->assertTrue($d['offer']);
		$this->assertSame(1, $d['targetCalendarId']);
	}

	public function testRowWithoutOwnerPrincipalIsRejected(): void {
		// Fails closed: 'principaluri' is the RECIPIENT on a shared row, so it
		// is never evidence of ownership in front of an unrecoverable delete.
		$this->givenOfferPending(true);
		$rows = [['id' => 1, 'uri' => 'personal', 'principaluri' => self::ALICE]];
		$this->calDav->method('getCalendarsForUser')->willReturn($rows);
		$this->calDav->method('getCalendarObjects')->willReturn([['uri' => 'a.ics']]);
		$this->calDav->method('getMultipleCalendarObjects')->willReturn([['uri' => 'a.ics', 'calendardata' => self::MARKED]]);

		$d = $this->svc->diagnose('alice');
		$this->assertFalse($d['offer']);
		$this->assertSame(1, $d['skippedForeignCalendars']);
	}

	public function testSharedByUriSuffixIsRejectedEvenIfOwnerLooksRight(): void {
		// Belt and braces: a `_shared_by_` URI is never a calendar of our own.
		$this->givenOfferPending(true);
		$this->givenCalendars(
			[['id' => 50, 'uri' => 'personal_shared_by_bob']],
			[50 => ['x.ics' => self::MARKED]]
		);

		$d = $this->svc->diagnose('alice');
		$this->assertFalse($d['offer']);
		$this->assertSame(1, $d['skippedForeignCalendars']);
	}

	public function testDiagnosticsReportEveryOwnCalendarScanned(): void {
		$this->givenOfferPending(true);
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

	// ---- reset() ----------------------------------------------------------

	private function expectTransactionCommitted(): void {
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');
	}

	public function testResetRecreatesCalendarWithSameProps(): void {
		$this->givenOfferPending(true);
		$this->givenCalendars(
			[[
				'id' => 7,
				'uri' => 'personal',
				'{DAV:}displayname' => 'Persoonlijk',
				'{http://apple.com/ns/ical/}calendar-color' => '#FF00FF',
				'{http://apple.com/ns/ical/}calendar-order' => 3,
				'{urn:ietf:params:xml:ns:caldav}calendar-timezone' => 'BEGIN:VCALENDAR...Europe/Amsterdam...',
				'{urn:ietf:params:xml:ns:caldav}calendar-description' => 'Werk',
			]],
			[7 => ['a.ics' => self::MARKED]]
		);
		$this->expectTransactionCommitted();

		$this->calDav->expects($this->once())->method('deleteCalendar')->with(7, true);
		$this->calDav->expects($this->once())->method('createCalendar')
			->with(self::ALICE, 'personal', [
				'components' => 'VEVENT',
				'{DAV:}displayname' => 'Persoonlijk',
				'{http://apple.com/ns/ical/}calendar-color' => '#FF00FF',
				'{http://apple.com/ns/ical/}calendar-order' => 3,
				'{urn:ietf:params:xml:ns:caldav}calendar-timezone' => 'BEGIN:VCALENDAR...Europe/Amsterdam...',
				'{urn:ietf:params:xml:ns:caldav}calendar-description' => 'Werk',
			])
			->willReturn(99);
		$this->config->expects($this->never())->method('setUserValue');

		$this->assertTrue($this->svc->reset('alice'));
	}

	public function testResetPreservesSupportedComponentSet(): void {
		// A calendar that also held tasks must still accept VTODO afterwards.
		$this->givenOfferPending(true);
		$this->givenCalendars(
			[['id' => 7, 'uri' => 'personal', self::SCCS => new SupportedCalendarComponentSet(['VEVENT', 'VTODO'])]],
			[7 => ['a.ics' => self::MARKED]]
		);
		$this->expectTransactionCommitted();

		$this->calDav->expects($this->once())->method('createCalendar')
			->with(self::ALICE, 'personal', ['components' => 'VEVENT,VTODO'])
			->willReturn(99);

		$this->assertTrue($this->svc->reset('alice'));
	}

	public function testComponentsFallBackToEventsWhenSetIsEmpty(): void {
		$this->givenOfferPending(true);
		$this->givenCalendars(
			[['id' => 7, 'uri' => 'personal', self::SCCS => new SupportedCalendarComponentSet([])]],
			[7 => ['a.ics' => self::MARKED]]
		);
		$this->expectTransactionCommitted();

		$this->calDav->expects($this->once())->method('createCalendar')
			->with(self::ALICE, 'personal', ['components' => 'VEVENT'])
			->willReturn(99);

		$this->assertTrue($this->svc->reset('alice'));
	}

	public function testResetRestoresNcDefaultCalendarPreference(): void {
		// Core's CalendarDeletionDefaultUpdaterListener drops dav/defaultCalendar
		// when the deleted calendar was the default; the reset puts it back.
		$this->givenOfferPending(true);
		$this->config->method('getUserValue')
			->with('alice', 'dav', 'defaultCalendar', '')
			->willReturn('personal');
		$this->givenCalendars(
			[['id' => 7, 'uri' => 'personal', '{DAV:}displayname' => 'Personal']],
			[7 => ['a.ics' => self::MARKED]]
		);
		$this->calDav->method('createCalendar')->willReturn(99);

		$this->config->expects($this->once())->method('setUserValue')
			->with('alice', 'dav', 'defaultCalendar', 'personal');
		$this->assertTrue($this->svc->reset('alice'));
	}

	public function testResetRollsBackAndRethrowsWhenCreateFails(): void {
		$this->givenOfferPending(true);
		$this->givenCalendars(
			[['id' => 7, 'uri' => 'personal']],
			[7 => ['a.ics' => self::MARKED]]
		);
		$this->calDav->method('deleteCalendar');
		$this->calDav->method('createCalendar')->willThrowException(new \RuntimeException('unique constraint'));

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->config->expects($this->never())->method('setUserValue');

		$this->expectException(\RuntimeException::class);
		$this->svc->reset('alice');
	}

	public function testResetUsesTheDiagnosedRowAndFetchesCalendarsOnce(): void {
		// No second getCalendarsForUser() between decision and delete: the
		// row chosen by diagnose() is the row that is deleted.
		$this->givenOfferPending(true);
		$this->givenCalendars(
			[['id' => 7, 'uri' => 'personal']],
			[7 => ['a.ics' => self::MARKED]]
		);
		$this->calDav->expects($this->once())->method('getCalendarsForUser');
		$this->calDav->expects($this->once())->method('deleteCalendar')->with(7, true);
		$this->calDav->method('createCalendar')->willReturn(99);

		$this->assertTrue($this->svc->reset('alice'));
	}

	public function testResetRefusesWithoutPendingOffer(): void {
		$this->givenOfferPending(false);
		$this->calDav->expects($this->never())->method('deleteCalendar');
		$this->db->expects($this->never())->method('beginTransaction');
		$this->assertFalse($this->svc->reset('alice'));
	}

	public function testResetRefusesWhenNoCalendarIsMarked(): void {
		$this->givenOfferPending(true);
		$this->givenCalendars(
			[['id' => 1, 'uri' => 'personal']],
			[1 => ['a.ics' => self::CLEAN]]
		);
		$this->calDav->expects($this->never())->method('deleteCalendar');
		$this->assertFalse($this->svc->reset('alice'));
	}

	public function testResetRefusesWhenMultipleCalendarsAreMarked(): void {
		$this->givenOfferPending(true);
		$this->givenCalendars(
			[['id' => 1, 'uri' => 'personal'], ['id' => 11, 'uri' => 'exchange']],
			[1 => ['a.ics' => self::MARKED], 11 => ['b.ics' => self::MARKED]]
		);
		$this->calDav->expects($this->never())->method('deleteCalendar');
		$this->assertFalse($this->svc->reset('alice'));
	}

	public function testResetNeverDeletesASharedCalendar(): void {
		// The one scenario this whole rework exists for.
		$this->givenOfferPending(true);
		$this->givenCalendars(
			[['id' => 1, 'uri' => 'personal'], $this->sharedByBob(50, 'personal')],
			[1 => ['a.ics' => self::CLEAN], 50 => ['x.ics' => self::MARKED]]
		);
		$this->calDav->expects($this->never())->method('deleteCalendar');
		$this->assertFalse($this->svc->reset('alice'));
	}
}
