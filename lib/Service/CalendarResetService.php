<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;

/**
 * One-time delete-and-recreate of the calendar that still holds legacy
 * X-SENDENT events. Only calendars the user owns are considered:
 * getCalendarsForUser() also lists calendars shared with the user, keyed on
 * the owner's calendar id, and deleteCalendar() would purge those.
 */
class CalendarResetService {

	private const SENDENT_MARKER = 'X-SENDENT';
	private const SCAN_CHUNK_SIZE = 100;
	private const OWNER_PRINCIPAL = '{http://owncloud.org/ns}owner-principal';
	private const DELETED_AT = '{http://nextcloud.com/ns}deleted-at';
	private const COMPONENT_SET = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';

	public function __construct(
		private CalDavBackend $calDav,
		private SyncUserService $syncUserService,
		private IConfig $config,
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {}

	private function principal(string $userId): string {
		return 'principals/users/' . $userId;
	}

	public function shouldOffer(string $userId): bool {
		return $this->diagnose($userId)['offer'];
	}

	/**
	 * Picks the owned calendar to offer, if any; reset() deletes exactly this row.
	 *
	 * @return array{offer: bool, declinedAt: ?string, targetUri: ?string, targetCalendarId: ?int, targetCalendar: ?array, ...}
	 */
	public function diagnose(string $userId): array {
		$d = [
			'userId' => $userId,
			'pendingResetOffer' => false,
			'userCalendars' => [],
			'skippedForeignCalendars' => 0,
			'markedUris' => [],
			'targetUri' => null,
			'targetCalendarId' => null,
			'targetCalendar' => null,
			'offer' => false,
			'declinedAt' => null,
		];

		$d['pendingResetOffer'] = $this->syncUserService->hasPendingResetOffer($userId);
		if (!$d['pendingResetOffer']) {
			$d['declinedAt'] = 'noPendingResetOffer';
			return $d;
		}

		$principal = $this->principal($userId);
		foreach ($this->calDav->getCalendarsForUser($principal) as $c) {
			if (!$this->isOwnedBy($c, $principal)) {
				$d['skippedForeignCalendars']++;
				continue;
			}
			if (isset($c[self::DELETED_AT]) && is_numeric($c[self::DELETED_AT])) {
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
				$d['markedUris'][] = (string)($c['uri'] ?? $id);
				$d['targetUri'] = $c['uri'] ?? null;
				$d['targetCalendarId'] = $id;
				$d['targetCalendar'] = $c;
			}
		}

		if ($d['markedUris'] === []) {
			$d['targetUri'] = null;
			$d['targetCalendarId'] = null;
			$d['targetCalendar'] = null;
			$d['declinedAt'] = 'noSendentMarker';
			return $d;
		}

		if (count($d['markedUris']) > 1) {
			$d['targetUri'] = null;
			$d['targetCalendarId'] = null;
			$d['targetCalendar'] = null;
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

	/** A missing owner-principal fails closed: this sits in front of a hard delete. */
	private function isOwnedBy(array $c, string $principal): bool {
		$owner = $c[self::OWNER_PRINCIPAL] ?? null;
		if ($owner === null || $owner !== $principal) {
			return false;
		}
		return !str_contains((string)($c['uri'] ?? ''), '_shared_by_');
	}

	/**
	 * Hard-deletes and re-creates the diagnosed calendar in one transaction,
	 * keeping its URI and properties. Outgoing shares are lost (logged only).
	 *
	 * @return bool false when the guard refuses
	 * @throws \Throwable from the CalDAV backend, after rollback
	 */
	public function reset(string $userId): bool {
		$d = $this->diagnose($userId);
		if (!$d['offer'] || $d['targetCalendar'] === null) {
			return false;
		}

		$cal = $d['targetCalendar'];
		$principal = $this->principal($userId);
		if (!$this->isOwnedBy($cal, $principal)) {
			$this->logger->error('Calendar reset refused for user "' . $userId . '": selected calendar is not owned by the user.');
			return false;
		}

		$calId = (int)$cal['id'];
		$uri = (string)$cal['uri'];

		$props = ['components' => $this->componentsOf($cal)];
		foreach ([
			'{DAV:}displayname',
			'{http://apple.com/ns/ical/}calendar-color',
			'{http://apple.com/ns/ical/}calendar-order',
			'{urn:ietf:params:xml:ns:caldav}calendar-timezone',
			'{urn:ietf:params:xml:ns:caldav}calendar-description',
		] as $prop) {
			if (isset($cal[$prop]) && $cal[$prop] !== '') {
				$props[$prop] = $cal[$prop];
			}
		}

		$transp = $cal['{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp'] ?? null;
		if ($transp instanceof ScheduleCalendarTransp) {
			$props['{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp'] = $transp;
		}

		$wasNcDefault = $this->config->getUserValue($userId, 'dav', 'defaultCalendar', '') === $uri;

		$shares = $this->calDav->getShares($calId);
		if (!empty($shares)) {
			$this->logger->warning('Calendar reset for user "' . $userId . '" removes ' . count($shares) . ' share(s) on calendar "' . $uri . '": ' . json_encode(array_column($shares, 'href')));
		}

		$this->logger->info('Resetting legacy sync calendar "' . $uri . '" (id ' . $calId . ') for user "' . $userId . '"');
		$this->db->beginTransaction();
		try {
			$this->calDav->deleteCalendar($calId, true);
			$newId = $this->calDav->createCalendar($principal, $uri, $props);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			$this->logger->error('Calendar reset failed for user "' . $userId . '" on calendar "' . $uri
				. '" (id ' . $calId . '); rolled back. Props: ' . json_encode(array_map(
					static fn ($v) => is_object($v) ? get_class($v) : $v, $props
				)), ['exception' => $e]);
			throw $e;
		}
		$this->logger->info('Re-created calendar "' . $uri . '" for user "' . $userId . '" (new id ' . $newId . ')');

		// Core's CalendarDeletionDefaultUpdaterListener dropped this on delete.
		if ($wasNcDefault) {
			$this->config->setUserValue($userId, 'dav', 'defaultCalendar', $uri);
		}

		return true;
	}

	private function componentsOf(array $cal): string {
		$set = $cal[self::COMPONENT_SET] ?? null;
		if ($set instanceof SupportedCalendarComponentSet) {
			$values = $set->getValue();
			if (!empty($values)) {
				return implode(',', $values);
			}
		} elseif (is_string($set) && $set !== '') {
			return $set;
		}
		return 'VEVENT';
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
