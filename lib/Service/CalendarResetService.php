<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * One-time "delete and re-create the synced calendar" offered to users of the
 * legacy sync client. A user qualifies while they still hold a legacy-named
 * app token (i.e. they have not completed the new consent flow yet) AND one of
 * their calendars contains the X-SENDENT marker the old client wrote into event
 * iCal data. Completing the consent flow swaps the token name, which is what
 * makes the offer one-time — no other state is stored.
 *
 * TARGET SELECTION IS MARKER-DRIVEN, BY DELIBERATE DESIGN.
 *
 * The target is whichever calendar actually holds legacy Sendent events, found
 * by scanning. It is NOT derived from any "default calendar" setting:
 *
 *  - the app's own `defaultCalendar` appconfig belongs to an unshipped feature
 *    whose admin UI is still hidden, so it is not a legitimate source;
 *  - `SyncUser.calendar` has no writer anywhere in the app — always empty;
 *  - Nextcloud's own per-user defaults (`schedule-default-calendar-URL`,
 *    `dav.defaultCalendarId`) are frequently unset, and even when set they say
 *    where invitations land today — not where the legacy client wrote;
 *  - falling back to the literal URI 'personal' is unsafe: it assumes a URI
 *    string that varies across Nextcloud versions and cannot be recovered from
 *    a localised display name ("Persoonlijk", "Persönlich", …).
 *
 * Scanning for the marker sidesteps all of that: no URI string is ever matched,
 * so no locale or version assumption is baked in, and a calendar holding no
 * legacy data can never be selected for a hard delete. The Nextcloud default is
 * still resolved, but purely as a cross-check recorded in the diagnostics.
 */
class CalendarResetService {

	private const SENDENT_MARKER = 'X-SENDENT';
	private const SCAN_CHUNK_SIZE = 100;

	public function __construct(
		private CalDavBackend $calDav,
		private CollectionService $collectionService,
		private SyncUserService $syncUserService,
		private IConfig $config,
		private LoggerInterface $logger,
	) {}

	private function principal(string $userId): string {
		return 'principals/users/' . $userId;
	}

	/**
	 * Whether the one-time reset should be offered: the user must still hold a
	 * legacy-named token (the check runs before activate() invalidates it) and
	 * the target calendar must actually contain legacy data — a prior user
	 * whose calendar holds only Nextcloud-native events must not be offered a
	 * destructive delete.
	 */
	public function shouldOffer(string $userId): bool {
		return $this->diagnose($userId)['offer'];
	}

	/**
	 * The full reset decision: which calendar (if any) should be offered, and
	 * where the decision was declined. shouldOffer() and reset() are both thin
	 * wrappers over this, so the two can never disagree about the target.
	 *
	 * @return array{offer: bool, declinedAt: ?string, targetUri: ?string, ...}
	 */
	public function diagnose(string $userId): array {
		$d = [
			'userId' => $userId,
			'hasLegacyToken' => false,
			'userCalendars' => [],
			'markedUris' => [],
			'targetUri' => null,
			'targetCalendarId' => null,
			// Cross-check only — never used to choose the target.
			'ncDefaultUri' => null,
			'offer' => false,
			'declinedAt' => null,
		];

		$d['hasLegacyToken'] = $this->syncUserService->hasLegacyToken($userId);
		if (!$d['hasLegacyToken']) {
			$d['declinedAt'] = 'hasLegacyToken';
			return $d;
		}

		$d['ncDefaultUri'] = $this->collectionService->detectUserDefaultCalendar($userId);

		// Trashed calendars are skipped: the user already deleted them, so
		// re-creating one is not a clean-up they asked for.
		foreach ($this->calDav->getCalendarsForUser($this->principal($userId)) as $c) {
			if (isset($c['{http://nextcloud.com/ns}deleted-at'])
				&& is_numeric($c['{http://nextcloud.com/ns}deleted-at'])) {
				continue;
			}

			$id = (int)$c['id'];
			$objectUris = array_column($this->calDav->getCalendarObjects($id), 'uri');
			$markerUri = $this->scanForMarker($id, $objectUris);

			$d['userCalendars'][] = [
				'uri' => $c['uri'] ?? null,
				'id' => $id,
				'displayname' => $c['{DAV:}displayname'] ?? null,
				'objectCount' => count($objectUris),
				'marked' => $markerUri !== null,
			];

			if ($markerUri !== null) {
				$d['markedUris'][] = $c['uri'] ?? null;
				$d['targetUri'] = $c['uri'] ?? null;
				$d['targetCalendarId'] = $id;
			}
		}

		if ($d['markedUris'] === []) {
			$d['targetUri'] = null;
			$d['targetCalendarId'] = null;
			$d['declinedAt'] = 'noSendentMarker';
			return $d;
		}

		// More than one marked calendar is an unexpected state: we cannot know
		// which one the user means, and guessing risks an unrecoverable delete
		// of the wrong calendar. Decline and leave a trail for support.
		if (count($d['markedUris']) > 1) {
			$d['targetUri'] = null;
			$d['targetCalendarId'] = null;
			$d['declinedAt'] = 'multipleMarkedCalendars';
			$this->logger->warning('Calendar reset not offered to user "' . $userId
				. '": legacy X-SENDENT data found in ' . count($d['markedUris'])
				. ' calendars (' . implode(', ', $d['markedUris'])
				. '). Manual clean-up required.');
			return $d;
		}

		$d['offer'] = true;
		return $d;
	}

	/**
	 * Deletes the sync target calendar (hard delete — objects, shares and
	 * scheduling invitations are removed atomically and nothing lands in the
	 * trashbin; a calendar already sitting in the trashbin from an earlier
	 * web-UI delete is purged the same way, freeing the URI it still occupies)
	 * and re-creates it with the same URI, display name, color, sort order and
	 * timezone, preserving the user's locale-specific naming. Re-points the
	 * user's Nextcloud default-calendar preference when it referenced the old
	 * calendar. Guarded by the legacy token: once activate() has swapped the
	 * token name, this is a no-op — that is the once-only guarantee.
	 *
	 * Re-runs the full shouldOffer() decision rather than trusting the caller:
	 * this is an unrecoverable hard delete, so the endpoint must not be able to
	 * destroy a calendar the offer would never have proposed.
	 */
	public function reset(string $userId): bool {
		$d = $this->diagnose($userId);
		if (!$d['offer']) {
			return false;
		}

		$uri = $d['targetUri'];
		if ($uri === null) {
			return false;
		}

		$cal = $this->findCalendar($userId, $uri);
		if ($cal === null) {
			return false;
		}
		$calId = (int)$cal['id'];

		$props = ['components' => 'VEVENT'];
		foreach ([
			'{DAV:}displayname',
			'{http://apple.com/ns/ical/}calendar-color',
			'{http://apple.com/ns/ical/}calendar-order',
			'{urn:ietf:params:xml:ns:caldav}calendar-timezone',
		] as $prop) {
			if (isset($cal[$prop]) && $cal[$prop] !== null && $cal[$prop] !== '') {
				$props[$prop] = $cal[$prop];
			}
		}

		$wasNcDefault = $this->config->getUserValue($userId, 'dav', 'defaultCalendarId', '') === (string)$calId;

		// Shares are bound to the internal resource id and are destroyed by the
		// hard delete; log them so support can help users re-share afterwards.
		$shares = $this->calDav->getShares($calId);
		if (!empty($shares)) {
			$this->logger->warning('Calendar reset for user "' . $userId . '" removes ' . count($shares) . ' share(s) on calendar "' . $uri . '": ' . json_encode(array_column($shares, 'href')));
		}

		$this->logger->info('Resetting legacy sync calendar "' . $uri . '" (id ' . $calId . ') for user "' . $userId . '"');
		$this->calDav->deleteCalendar($calId, true);
		$newId = $this->calDav->createCalendar($this->principal($userId), $uri, $props);

		if ($wasNcDefault && $newId) {
			$this->config->setUserValue($userId, 'dav', 'defaultCalendarId', (string)$newId);
		}

		return true;
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
	 * @param array|null $calendars Pre-fetched calendar rows, to avoid a second
	 *                              getCalendarsForUser() query when the caller
	 *                              already has them
	 * @return array|null Full calendar row (all props, possibly with
	 *                    {http://nextcloud.com/ns}deleted-at set) or null
	 */
	private function findCalendar(string $userId, string $uri, ?array $calendars = null): ?array {
		$calendars ??= $this->calDav->getCalendarsForUser($this->principal($userId));
		$trashed = null;
		foreach ($calendars as $cal) {
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
	 * URI of the first object carrying the legacy client's X-SENDENT marker, or
	 * null if the calendar holds none. getCalendarObjects() returns metadata
	 * only, so iCal data is fetched in chunks via getMultipleCalendarObjects();
	 * exits on the first match, since one marked event is enough to classify
	 * the calendar.
	 *
	 * @param string[] $uris Object URIs already fetched for this calendar
	 */
	private function scanForMarker(int $calendarId, array $uris): ?string {
		foreach (array_chunk($uris, self::SCAN_CHUNK_SIZE) as $chunk) {
			foreach ($this->calDav->getMultipleCalendarObjects($calendarId, $chunk) as $obj) {
				if (str_contains($obj['calendardata'] ?? '', self::SENDENT_MARKER)) {
					return $obj['uri'] ?? '(unknown uri)';
				}
			}
		}
		return null;
	}
}
