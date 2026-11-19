<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Service\SyncUserService;
use OCA\SendentSynchroniser\Constants;

/**
 * Which collections the ledger records: only those owned by users the
 * Connector syncs (SyncUserService::isValidUser(), the rule behind the valid
 * users the app hands the Connector). Changes of anyone else never reach the
 * bot account.
 *
 * A calendar shared by a non-syncing owner is therefore not signalled, even
 * when a syncing user edits it; the Connector's periodic reconcile covers it.
 *
 * Two questions are answered here. includes() decides the per-collection
 * rows and covers every calendar and address book of a synced user.
 * isSyncedCalendar() is narrower and decides the per-event rows: only the
 * calendars the Connector syncs for that user.
 */
class SyncScope {

	private const USER_PRINCIPAL_PREFIX = 'principals/users/';

	/**
	 * Per process: one DAV request usually fires many events for one owner.
	 *
	 * Keyed by user id. The value is the user's synced calendar URIs, or null
	 * when the user is not a valid sync user. A null is an answer too and must
	 * not trigger a second lookup.
	 *
	 * @var array<string, list<string>|null>
	 */
	private array $synced = [];

	public function __construct(
		private SyncUserService $syncUsers,
	) {}

	/**
	 * Is the owner of this collection a valid sync user? Per-collection rows
	 * are written when this is true: for every calendar and every address
	 * book of that user, not only the synced calendars.
	 */
	public function includes(CollectionReference $ref): bool {
		$uid = substr($ref->principalUri, strlen(self::USER_PRINCIPAL_PREFIX));

		return $this->syncedCalendarsOf($uid) !== null;
	}

	/**
	 * Is this reference one of the synced calendars of a valid sync user?
	 * Per-event rows are written only when this is true, so the feed never
	 * reports events from a calendar the Connector does not sync.
	 *
	 * Guarantees:
	 * - True only for a calendar. An address book whose URI happens to equal
	 *   a synced calendar's URI is not a match.
	 * - True only when the reference's URI is, exactly, one of the URIs that
	 *   SyncUserService gives for the owner. Any other calendar of the same
	 *   user is not a match.
	 * - With several synced calendars, each of them matches.
	 * - False for an owner who is not a valid sync user.
	 * - When a configured calendar does not exist, nothing matches it. That is
	 *   not an error: no exception and no log line.
	 * - Never true where includes() is false for the same reference.
	 * - Costs no lookup when the owner was already looked up in this process,
	 *   by either question.
	 */
	public function isSyncedCalendar(CollectionReference $ref): bool {
		if ($ref->collectionType !== Constants::COLLECTION_TYPE_CALDAV) {
			return false;
		}

		$uid = substr($ref->principalUri, strlen(self::USER_PRINCIPAL_PREFIX));
		$calendars = $this->syncedCalendarsOf($uid);
		if ($calendars === null) {
			return false;
		}

		// Strict: a URI is free text, and '0' must not match '00'.
		return in_array($ref->collectionUri, $calendars, true);
	}

	/**
	 * The single cached lookup behind both questions, so one user costs one
	 * call to SyncUserService per process however many events fire and
	 * whichever question is asked first.
	 *
	 * @return list<string>|null the user's synced calendar URIs, or null when
	 *                           the user is not a valid sync user
	 */
	private function syncedCalendarsOf(string $uid): ?array {
		// Not ??=: null is a cached answer too, and ??= would look it up again.
		if (!array_key_exists($uid, $this->synced)) {
			$this->synced[$uid] = $this->syncUsers->syncedCalendarsOfValidUser($uid);
		}

		return $this->synced[$uid];
	}
}
