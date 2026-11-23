<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\ChangeNotification\EventChange;
use OCA\SendentSynchroniser\ChangeNotification\EventChangeKind;
use OCA\SendentSynchroniser\Constants;

/**
 * Turns a CalDAV/CardDAV collection row from a DAV event into a
 * CollectionReference, and the rows of a calendar object event into
 * EventChanges. Free of Nextcloud types, so it is unit-testable without a
 * server.
 *
 * The principal comes from the collection row, never the acting session
 * user: a shared calendar edited by Bob changes Alice's collection.
 */
class DavEventReferenceExtractor {

	/** Only user principals map to a mailbox. */
	private const USER_PRINCIPAL_PREFIX = 'principals/users/';

	/**
	 * Key in a calendar object row that holds the time the file went into the
	 * trash bin: an int when it is in the trash, null when it is live.
	 */
	private const DELETED_AT_KEY = '{http://nextcloud.com/ns}deleted-at';

	/** @param array<string, mixed>|null $row */
	public function fromCalendarRow(?array $row, bool $structural): ?CollectionReference {
		return $this->fromRow($row, Constants::COLLECTION_TYPE_CALDAV, $structural);
	}

	/** @param array<string, mixed>|null $row */
	public function fromAddressBookRow(?array $row, bool $structural): ?CollectionReference {
		return $this->fromRow($row, Constants::COLLECTION_TYPE_CARDDAV, $structural);
	}

	// ── Event level ─────────────────────────────────────────────────────
	//
	// Each method below takes the rows one Nextcloud calendar object event
	// carries (getCalendarData() and getObjectData()) and returns the changes
	// a per-event feed needs: none, one, or two for a move.
	//
	// Guarantees shared by all of them:
	// - The calendar of every result follows the same rules as
	//   fromCalendarRow(): only calendars of user principals, never null or
	//   incomplete rows. It is an object-level reference (collectionChanged
	//   is false).
	// - An object row that is null, or has no usable file name ('uri' missing,
	//   not a string, or empty), yields nothing.
	// - They never throw on bad input; the caller runs inside the user's own
	//   DAV transaction.
	// - Nothing here looks at which calendar is synced.
	//
	// Subscription events (the CachedCalendarObject* classes) and contact
	// events must not be passed to these methods.

	/**
	 * The change of one event file in one calendar.
	 *
	 * @param array<string, mixed>|null $calendarRow
	 * @param array<string, mixed>|null $objectRow
	 * @return list<EventChange> one change of the given kind, or nothing when
	 *                           the calendar does not count or the object row
	 *                           has no usable file name
	 */
	private function changeFor(?array $calendarRow, ?array $objectRow, EventChangeKind $kind): array {
		$calendarReference = $this->fromRow($calendarRow, Constants::COLLECTION_TYPE_CALDAV, false);
		if (!is_array($objectRow)) {
			return [];
		}
		if (!is_string($objectRow['uri'] ?? null) || $objectRow['uri'] === '') {
			return [];
		}
		if ($calendarReference !== null) {
			return [new EventChange($calendarReference, $objectRow['uri'], $kind)];
		}
		return [];
	}

	/**
	 * A new event file.
	 *
	 * @param array<string, mixed>|null $calendarRow
	 * @param array<string, mixed>|null $objectRow
	 * @return list<EventChange> one Created change, or nothing
	 */
	public function eventCreated(?array $calendarRow, ?array $objectRow): array {
		return $this->changeFor($calendarRow, $objectRow, EventChangeKind::Created);
	}

	/**
	 * A changed event file. A changed occurrence of a series arrives here too:
	 * the series is one file.
	 *
	 * @param array<string, mixed>|null $calendarRow
	 * @param array<string, mixed>|null $objectRow
	 * @return list<EventChange> one Updated change, or nothing
	 */
	public function eventUpdated(?array $calendarRow, ?array $objectRow): array {
		return $this->changeFor($calendarRow, $objectRow, EventChangeKind::Updated);
	}

	/**
	 * An event file that is gone for good. Nextcloud fires this in two
	 * situations, and the object row is the row as it was just before the
	 * delete:
	 * - the file was live (the trash bin is switched off): the Connector must
	 *   hear about it;
	 * - the file was already in the trash (purged by the user, by retention,
	 *   or replaced by a new file with the same UID): the Connector heard
	 *   "deleted" when it went into the trash, and the row carries the trash
	 *   name, so this yields nothing.
	 *
	 * @param array<string, mixed>|null $calendarRow
	 * @param array<string, mixed>|null $objectRow
	 * @return list<EventChange> one Deleted change, or nothing
	 */
	public function eventDeleted(?array $calendarRow, ?array $objectRow): array {
		if (($objectRow[self::DELETED_AT_KEY] ?? null) !== null) {
			return [];
		}
		return $this->changeFor($calendarRow, $objectRow, EventChangeKind::Deleted);
	}

	/**
	 * An event file moved to the trash bin. The object row was read before
	 * Nextcloud renamed the file, so its 'uri' is the original file name.
	 *
	 * @param array<string, mixed>|null $calendarRow
	 * @param array<string, mixed>|null $objectRow
	 * @return list<EventChange> one Deleted change with the original file name, or nothing
	 */
	public function eventMovedToTrash(?array $calendarRow, ?array $objectRow): array {
		return $this->changeFor($calendarRow, $objectRow, EventChangeKind::Deleted);
	}

	/**
	 * An event file restored from the trash bin. The object row was read after
	 * Nextcloud renamed the file back, so its 'uri' is the name the file has
	 * in the calendar now. The row is a short one (no id, no deleted-at).
	 *
	 * @param array<string, mixed>|null $calendarRow
	 * @param array<string, mixed>|null $objectRow
	 * @return list<EventChange> one Created change with the restored file name, or nothing
	 */
	public function eventRestored(?array $calendarRow, ?array $objectRow): array {
		return $this->changeFor($calendarRow, $objectRow, EventChangeKind::Created);
	}

	/**
	 * An event file moved from one calendar to another. The object row is the
	 * row after the move; the old file name is not in the event.
	 *
	 * Each side is judged on its own by the shared calendar rules: a side that
	 * is not a user's calendar is left out, the other side still counts.
	 *
	 * @param array<string, mixed>|null $sourceCalendarRow
	 * @param array<string, mixed>|null $targetCalendarRow
	 * @param array<string, mixed>|null $objectRow
	 * @return list<EventChange> Deleted for the source calendar, then Created
	 *                           for the target calendar, both with the file
	 *                           name as it is after the move
	 */
	public function eventMoved(?array $sourceCalendarRow, ?array $targetCalendarRow, ?array $objectRow): array {
		$changes = [];
		$changes = array_merge($changes, $this->changeFor($sourceCalendarRow, $objectRow, EventChangeKind::Deleted));
		$changes = array_merge($changes, $this->changeFor($targetCalendarRow, $objectRow, EventChangeKind::Created));
		return $changes;
	}

	/** @param array<string, mixed>|null $row */
	private function fromRow(?array $row, string $type, bool $structural): ?CollectionReference {
		if (!is_array($row)) {
			return null;
		}

		$principal = $row['principaluri'] ?? null;
		$uri = $row['uri'] ?? null;

		if (!is_string($principal) || !is_string($uri) || $uri === '') {
			return null;
		}

		if (!str_starts_with($principal, self::USER_PRINCIPAL_PREFIX)
			|| $principal === self::USER_PRINCIPAL_PREFIX) {
			return null;
		}

		return new CollectionReference($principal, $type, $uri, $structural);
	}
}
