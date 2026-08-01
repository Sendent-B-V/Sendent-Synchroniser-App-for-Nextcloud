<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\SendentSynchroniser\Db\SyncUserMapper;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * One-time "delete and re-create the synced calendar" offered to users of the
 * legacy sync client. A user qualifies while they still hold a legacy-named
 * app token (i.e. they have not completed the new consent flow yet) AND their
 * sync target calendar contains the X-SENDENT marker the old client wrote
 * into event iCal data. Completing the consent flow swaps the token name,
 * which is what makes the offer one-time — no other state is stored.
 */
class CalendarResetService {

	private const SENDENT_MARKER = 'X-SENDENT';
	private const SCAN_CHUNK_SIZE = 100;

	public function __construct(
		private CalDavBackend $calDav,
		private CollectionService $collectionService,
		private SyncUserMapper $syncUserMapper,
		private SyncUserService $syncUserService,
		private IConfig $config,
		private LoggerInterface $logger,
	) {}

	private function principal(string $userId): string {
		return 'principals/users/' . $userId;
	}

	/**
	 * The calendar the sync client targets for this user: the per-user choice
	 * stored on the SyncUser row, else the admin-configured default.
	 */
	public function targetCalendarUri(string $userId): string {
		$syncUsers = $this->syncUserMapper->findByUid($userId);
		if (!empty($syncUsers) && ($syncUsers[0]->getCalendar() ?? '') !== '') {
			return $syncUsers[0]->getCalendar();
		}
		return $this->collectionService->getDefaultCalendar();
	}

	/**
	 * Whether the one-time reset should be offered: the user must still hold a
	 * legacy-named token (the check runs before activate() invalidates it) and
	 * the target calendar must actually contain legacy data — a prior user
	 * whose calendar holds only Nextcloud-native events must not be offered a
	 * destructive delete.
	 */
	public function shouldOffer(string $userId): bool {
		if (!$this->syncUserService->hasLegacyToken($userId)) {
			return false;
		}

		$cal = $this->findCalendar($userId, $this->targetCalendarUri($userId));
		return $cal !== null && $this->containsSendentData((int)$cal['id']);
	}

	/**
	 * Finds the calendar at the given URI: the live one if it exists, else a
	 * trashbinned one. A trashbinned calendar still occupies the URI —
	 * oc_calendars has a unique index on (principaluri, uri) regardless of
	 * deleted_at — so it must be found and purged before re-creating, or
	 * createCalendar() throws a unique-constraint violation. This happens when
	 * the user previously deleted the calendar via the web UI (web/DAV deletes
	 * always soft-delete into the trashbin).
	 *
	 * @return array|null Full calendar row (all props, possibly with
	 *                    {http://nextcloud.com/ns}deleted-at set) or null
	 */
	private function findCalendar(string $userId, string $uri): ?array {
		$trashed = null;
		foreach ($this->calDav->getCalendarsForUser($this->principal($userId)) as $cal) {
			if ($cal['uri'] !== $uri) {
				continue;
			}
			if (isset($cal['{http://nextcloud.com/ns}deleted-at'])
				&& is_numeric($cal['{http://nextcloud.com/ns}deleted-at'])) {
				$trashed = $cal;
				continue;
			}
			return $cal;
		}
		return $trashed;
	}

	/**
	 * Scans the calendar for the legacy client's X-SENDENT marker.
	 * getCalendarObjects() returns metadata only, so iCal data is fetched in
	 * chunks via getMultipleCalendarObjects(); exits on first match.
	 */
	public function containsSendentData(int $calendarId): bool {
		$uris = array_column($this->calDav->getCalendarObjects($calendarId), 'uri');
		foreach (array_chunk($uris, self::SCAN_CHUNK_SIZE) as $chunk) {
			foreach ($this->calDav->getMultipleCalendarObjects($calendarId, $chunk) as $obj) {
				if (str_contains($obj['calendardata'] ?? '', self::SENDENT_MARKER)) {
					return true;
				}
			}
		}
		return false;
	}
}
