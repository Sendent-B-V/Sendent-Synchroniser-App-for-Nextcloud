<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\EventChange;
use OCA\SendentSynchroniser\ChangeNotification\EventChangeKind;
use OCA\SendentSynchroniser\Service\ChangeNotification\DavEventReferenceExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rows used here have the shape Nextcloud's CalDavBackend puts in its
 * calendar object events (getCalendarById(), rowToCalendarObject()).
 */
class DavEventChangeExtractionTest extends TestCase {

	private const DELETED_AT = '{http://nextcloud.com/ns}deleted-at';

	private DavEventReferenceExtractor $extractor;

	protected function setUp(): void {
		parent::setUp();
		$this->extractor = new DavEventReferenceExtractor();
	}

	/** @return array<string, mixed> */
	private static function calendar(string $principal = 'principals/users/alice', string $uri = 'personal', int $id = 42): array {
		return [
			'id' => $id,
			'uri' => $uri,
			'principaluri' => $principal,
			'{http://sabredav.org/ns}sync-token' => 9651,
		];
	}

	/** @return array<string, mixed> */
	private static function object(string $uri = 'meeting.ics', ?int $deletedAt = null, int $calendarId = 42): array {
		return [
			'id' => 1001,
			'uri' => $uri,
			'uid' => 'b7c1f0e2-meeting',
			'lastmodified' => 1763500000,
			'etag' => '"5d41402abc4b2a76b9719d911017c592"',
			'calendarid' => $calendarId,
			'size' => 512,
			'calendardata' => "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n",
			'component' => 'vevent',
			'classification' => 0,
			self::DELETED_AT => $deletedAt,
		];
	}

	private function assertChange(EventChange $change, string $principal, string $calendarUri, string $fileName, EventChangeKind $kind): void {
		$this->assertSame($principal, $change->calendar->principalUri);
		$this->assertSame('caldav', $change->calendar->collectionType);
		$this->assertSame($calendarUri, $change->calendar->collectionUri);
		$this->assertFalse($change->calendar->collectionChanged, 'an event change is never a change of the calendar itself');
		$this->assertSame($fileName, $change->fileName);
		$this->assertSame($kind, $change->kind);
	}

	// ── Wire values ─────────────────────────────────────────────────────

	public function testKindsHaveTheWireValuesOfTheFeed(): void {
		$this->assertSame('created', EventChangeKind::Created->value);
		$this->assertSame('updated', EventChangeKind::Updated->value);
		$this->assertSame('deleted', EventChangeKind::Deleted->value);
		$this->assertCount(3, EventChangeKind::cases());
	}

	// ── Created / updated ───────────────────────────────────────────────

	public function testCreatedEventYieldsOneCreatedChange(): void {
		$changes = $this->extractor->eventCreated(self::calendar(), self::object());

		$this->assertCount(1, $changes);
		$this->assertChange($changes[0], 'principals/users/alice', 'personal', 'meeting.ics', EventChangeKind::Created);
	}

	public function testUpdatedEventYieldsOneUpdatedChange(): void {
		$changes = $this->extractor->eventUpdated(self::calendar(), self::object());

		$this->assertCount(1, $changes);
		$this->assertChange($changes[0], 'principals/users/alice', 'personal', 'meeting.ics', EventChangeKind::Updated);
	}

	public function testFileNameWithoutIcsExtensionIsKeptAsIs(): void {
		$changes = $this->extractor->eventCreated(self::calendar(), self::object('A1B2C3D4'));

		$this->assertCount(1, $changes);
		$this->assertSame('A1B2C3D4', $changes[0]->fileName);
	}

	public function testTheCalendarOwnerCountsNotWhoMadeTheChange(): void {
		// Bob edits an event in Alice's shared calendar: the event carries
		// Alice's calendar row.
		$changes = $this->extractor->eventUpdated(self::calendar('principals/users/alice', 'team'), self::object());

		$this->assertCount(1, $changes);
		$this->assertSame('principals/users/alice', $changes[0]->calendar->principalUri);
		$this->assertSame('team', $changes[0]->calendar->collectionUri);
	}

	// ── Trash ───────────────────────────────────────────────────────────

	public function testMovedToTrashYieldsDeletedWithTheOriginalFileName(): void {
		// Nextcloud hands over the row it read before renaming the file to
		// meeting-deleted.ics.
		$changes = $this->extractor->eventMovedToTrash(self::calendar(), self::object('meeting.ics', null));

		$this->assertCount(1, $changes);
		$this->assertChange($changes[0], 'principals/users/alice', 'personal', 'meeting.ics', EventChangeKind::Deleted);
	}

	public function testRestoredYieldsCreatedWithTheRestoredFileName(): void {
		// The restore event carries a short row, read after the rename back.
		$restoredRow = [
			'calendardata' => "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n",
			'uri' => 'meeting.ics',
			'calendarid' => 42,
			'calendartype' => 0,
			'component' => 'VEVENT',
		];

		$changes = $this->extractor->eventRestored(self::calendar(), $restoredRow);

		$this->assertCount(1, $changes);
		$this->assertChange($changes[0], 'principals/users/alice', 'personal', 'meeting.ics', EventChangeKind::Created);
	}

	public function testPurgedFromTrashYieldsNothing(): void {
		$changes = $this->extractor->eventDeleted(self::calendar(), self::object('meeting-deleted.ics', 1763400000));

		$this->assertSame([], $changes);
	}

	public function testPermanentDeleteOfALiveFileYieldsDeleted(): void {
		// With the trash bin switched off a delete skips the trash.
		$changes = $this->extractor->eventDeleted(self::calendar(), self::object('meeting.ics', null));

		$this->assertCount(1, $changes);
		$this->assertChange($changes[0], 'principals/users/alice', 'personal', 'meeting.ics', EventChangeKind::Deleted);
	}

	public function testALiveFileWhoseNameLooksLikeATrashNameIsStillDeleted(): void {
		// Whether a file was in the trash is a fact in the row, not something
		// to read from its name.
		$changes = $this->extractor->eventDeleted(self::calendar(), self::object('to-be-deleted.ics', null));

		$this->assertCount(1, $changes);
		$this->assertSame('to-be-deleted.ics', $changes[0]->fileName);
		$this->assertSame(EventChangeKind::Deleted, $changes[0]->kind);
	}

	// ── Move between calendars ──────────────────────────────────────────

	public function testMoveYieldsDeletedForTheSourceThenCreatedForTheTarget(): void {
		$changes = $this->extractor->eventMoved(
			self::calendar('principals/users/alice', 'personal', 42),
			self::calendar('principals/users/alice', 'work', 43),
			self::object('meeting.ics', null, 43),
		);

		$this->assertCount(2, $changes);
		$this->assertChange($changes[0], 'principals/users/alice', 'personal', 'meeting.ics', EventChangeKind::Deleted);
		$this->assertChange($changes[1], 'principals/users/alice', 'work', 'meeting.ics', EventChangeKind::Created);
	}

	public function testMoveUsesTheFileNameAfterTheMoveOnBothSides(): void {
		// A move can rename the file; the event only carries the new name.
		$changes = $this->extractor->eventMoved(
			self::calendar('principals/users/alice', 'personal', 42),
			self::calendar('principals/users/alice', 'work', 43),
			self::object('renamed.ics', null, 43),
		);

		$this->assertCount(2, $changes);
		$this->assertSame('renamed.ics', $changes[0]->fileName);
		$this->assertSame('renamed.ics', $changes[1]->fileName);
	}

	public function testMoveBetweenCalendarsOfDifferentUsersYieldsBothSides(): void {
		$changes = $this->extractor->eventMoved(
			self::calendar('principals/users/alice', 'personal', 42),
			self::calendar('principals/users/bob', 'shared-with-alice', 77),
			self::object('meeting.ics', null, 77),
		);

		$this->assertCount(2, $changes);
		$this->assertChange($changes[0], 'principals/users/alice', 'personal', 'meeting.ics', EventChangeKind::Deleted);
		$this->assertChange($changes[1], 'principals/users/bob', 'shared-with-alice', 'meeting.ics', EventChangeKind::Created);
	}

	public function testMoveOutOfANonUserCalendarOnlyYieldsTheTarget(): void {
		$changes = $this->extractor->eventMoved(
			self::calendar('principals/calendar-rooms/room-1', 'calendar', 5),
			self::calendar('principals/users/alice', 'work', 43),
			self::object('meeting.ics', null, 43),
		);

		$this->assertCount(1, $changes);
		$this->assertChange($changes[0], 'principals/users/alice', 'work', 'meeting.ics', EventChangeKind::Created);
	}

	public function testMoveIntoANonUserCalendarOnlyYieldsTheSource(): void {
		$changes = $this->extractor->eventMoved(
			self::calendar('principals/users/alice', 'personal', 42),
			self::calendar('principals/calendar-rooms/room-1', 'calendar', 5),
			self::object('meeting.ics', null, 5),
		);

		$this->assertCount(1, $changes);
		$this->assertChange($changes[0], 'principals/users/alice', 'personal', 'meeting.ics', EventChangeKind::Deleted);
	}

	public function testMoveWithoutAUsableFileNameYieldsNothing(): void {
		$object = self::object();
		unset($object['uri']);

		$this->assertSame([], $this->extractor->eventMoved(
			self::calendar('principals/users/alice', 'personal', 42),
			self::calendar('principals/users/alice', 'work', 43),
			$object,
		));
		$this->assertSame([], $this->extractor->eventMoved(
			self::calendar('principals/users/alice', 'personal', 42),
			self::calendar('principals/users/alice', 'work', 43),
			null,
		));
	}

	// ── Rows that yield nothing, for every single-calendar event ────────

	/** @return array<string, array{string}> */
	public static function singleCalendarMethods(): array {
		return [
			'created' => ['eventCreated'],
			'updated' => ['eventUpdated'],
			'deleted' => ['eventDeleted'],
			'moved to trash' => ['eventMovedToTrash'],
			'restored' => ['eventRestored'],
		];
	}

	#[DataProvider('singleCalendarMethods')]
	public function testNonUserPrincipalsYieldNothing(string $method): void {
		foreach (['principals/system/system', 'principals/groups/finance', 'principals/calendar-rooms/room-1', 'principals/users/'] as $principal) {
			$this->assertSame(
				[],
				$this->extractor->$method(self::calendar($principal, 'calendar'), self::object()),
				$principal,
			);
		}
	}

	#[DataProvider('singleCalendarMethods')]
	public function testMissingOrIncompleteCalendarRowYieldsNothing(string $method): void {
		$this->assertSame([], $this->extractor->$method(null, self::object()));
		$this->assertSame([], $this->extractor->$method(['principaluri' => 'principals/users/alice'], self::object()));
		$this->assertSame([], $this->extractor->$method(['uri' => 'personal'], self::object()));
	}

	#[DataProvider('singleCalendarMethods')]
	public function testObjectRowWithoutAUsableFileNameYieldsNothing(string $method): void {
		$noUri = self::object();
		unset($noUri['uri']);
		$emptyUri = self::object('');
		$nonStringUri = array_merge(self::object(), ['uri' => 1001]);

		$this->assertSame([], $this->extractor->$method(self::calendar(), null));
		$this->assertSame([], $this->extractor->$method(self::calendar(), $noUri));
		$this->assertSame([], $this->extractor->$method(self::calendar(), $emptyUri));
		$this->assertSame([], $this->extractor->$method(self::calendar(), $nonStringUri));
	}
}
