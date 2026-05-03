<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.vandebroek@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Sabre;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\SendentSynchroniser\Db\RoomBindingMapper;
use OCA\SendentSynchroniser\Db\RoomMapper;
use OCA\SendentSynchroniser\Service\Room\CalDAVService;
use OCA\SendentSynchroniser\UserBackend\RoomUserBackend;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\VObject\ITip\Message;
use Sabre\VObject\Reader;

/**
 * Implements room booking semantics for unbound rooms:
 *  - Auto-accept iTIP REQUEST when no calendar conflict
 *  - Decline iTIP REQUEST when conflicting event exists
 *
 * For BOUND rooms (any binding kind), returns early. The external service is
 * authoritative; mirror writes arrive via the Connector's CalDAV path, not via
 * iTIP.
 */
class RoomSchedulingPlugin extends ServerPlugin {

	/** Higher than NC's default scheduling so we run first. */
	public const SCHEDULE_PRIORITY = 99;

	public function __construct(
		private RoomMapper $rooms,
		private RoomBindingMapper $bindings,
		private LoggerInterface $logger,
		private CalDavBackend $calDav,
	) {}

	public function initialize(Server $server): void {
		$server->on('schedule', [$this, 'onSchedule'], self::SCHEDULE_PRIORITY);
	}

	public function onSchedule(Message $iTipMessage): void {
		$roomUid = $this->extractRoomUid($iTipMessage->recipient ?? '');
		if ($roomUid === null) {
			return;
		}
		$roomId = substr($roomUid, strlen(RoomUserBackend::PREFIX));

		try {
			$room = $this->rooms->findById($roomId);
		} catch (DoesNotExistException) {
			return;
		}

		if ($this->bindings->findByRoomIdOrNull($roomId) !== null) {
			// Bound: external service is authoritative. Plugin is a no-op.
			return;
		}

		if (($iTipMessage->method ?? '') !== 'REQUEST') {
			return;
		}

		$accepted = !$this->hasConflict($room->getBackingPrincipalUri(), $iTipMessage);
		$iTipMessage->scheduleStatus = $accepted ? '2.0;Success' : '5.3;Conflict';
	}

	/**
	 * Two-call CalDavBackend pattern: getCalendarObjects() returns metadata
	 * only; iCal data must be fetched per-object via getCalendarObject().
	 */
	protected function hasConflict(string $principalUri, Message $message): bool {
		$cal = $this->calDav->getCalendarByUri($principalUri, CalDAVService::CALENDAR_URI);
		if ($cal === null) {
			return false;
		}

		$vevent = $message->message->VEVENT;
		if ($vevent === null) {
			return false;
		}
		$newStart = $vevent->DTSTART->getDateTime();
		$newEnd = $vevent->DTEND->getDateTime();
		$newUid = (string) $vevent->UID;

		$calId = (int) $cal['id'];
		foreach ($this->calDav->getCalendarObjects($calId) as $obj) {
			$full = $this->calDav->getCalendarObject($calId, $obj['uri']);
			if ($full === null || empty($full['calendardata'])) {
				continue;
			}
			$existing = Reader::read($full['calendardata']);
			foreach ($existing->VEVENT ?? [] as $existingEvent) {
				if ((string) $existingEvent->UID === $newUid) {
					continue;
				}
				$existingStart = $existingEvent->DTSTART->getDateTime();
				$existingEnd = $existingEvent->DTEND->getDateTime();
				if ($newStart < $existingEnd && $existingStart < $newEnd) {
					return true;
				}
			}
		}
		return false;
	}

	private function extractRoomUid(string $recipient): ?string {
		if (!str_starts_with($recipient, 'principal:principals/users/')) {
			return null;
		}
		$uid = substr($recipient, strlen('principal:principals/users/'));
		if (!str_starts_with($uid, RoomUserBackend::PREFIX)) {
			return null;
		}
		return $uid;
	}
}
