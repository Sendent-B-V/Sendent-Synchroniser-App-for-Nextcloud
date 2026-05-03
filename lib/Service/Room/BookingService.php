<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Service\Room;

use DateTimeInterface;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\SendentSynchroniser\Db\RoomMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class BookingService {
	public function __construct(
		private CalDavBackend $calDav,
		private RoomMapper $rooms,
	) {}

	/**
	 * Returns calendar events with their iCal data. Note the two-call
	 * CalDavBackend pattern — getCalendarObjects() returns metadata only
	 * (no calendardata); the iCal must be fetched per-object via
	 * getCalendarObject($calId, $uri).
	 *
	 * @return array<array{uri:string, calendardata:string}>
	 * @throws DoesNotExistException
	 */
	public function listInRange(string $roomId, DateTimeInterface $from, DateTimeInterface $to): array {
		$room = $this->rooms->findById($roomId);
		$cal = $this->calDav->getCalendarByUri($room->getBackingPrincipalUri(), CalDAVService::CALENDAR_URI);
		if ($cal === null) {
			return [];
		}
		$calId = (int) $cal['id'];
		$out = [];
		foreach ($this->calDav->getCalendarObjects($calId) as $obj) {
			$full = $this->calDav->getCalendarObject($calId, $obj['uri']);
			if ($full === null) {
				continue;
			}
			$out[] = ['uri' => $obj['uri'], 'calendardata' => $full['calendardata'] ?? ''];
		}
		return $out;
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function deleteByUid(string $roomId, string $uid): bool {
		$room = $this->rooms->findById($roomId);
		$cal = $this->calDav->getCalendarByUri($room->getBackingPrincipalUri(), CalDAVService::CALENDAR_URI);
		if ($cal === null) {
			return false;
		}
		$calId = (int) $cal['id'];
		foreach ($this->calDav->getCalendarObjects($calId) as $obj) {
			$full = $this->calDav->getCalendarObject($calId, $obj['uri']);
			if ($full === null) {
				continue;
			}
			if (str_contains($full['calendardata'] ?? '', 'UID:' . $uid)) {
				$this->calDav->deleteCalendarObject($calId, $obj['uri']);
				return true;
			}
		}
		return false;
	}
}
