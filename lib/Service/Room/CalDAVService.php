<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.vandebroek@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Service\Room;

use OCA\DAV\CalDAV\CalDavBackend;
use Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp;

class CalDAVService {
	/**
	 * Calendar URI under each room's principal. Avoid 'personal' — NC's dav
	 * app may auto-create that for newly provisioned users via its CalDAV
	 * resource listener, which would collide. 'room' is unique within the
	 * principal namespace and unambiguous.
	 */
	public const CALENDAR_URI = 'room';

	public function __construct(private CalDavBackend $calDav) {}

	public function provision(string $principalUri, string $displayName): void {
		$this->calDav->createCalendar($principalUri, self::CALENDAR_URI, [
			'{DAV:}displayname' => $displayName,
			'{http://apple.com/ns/ical/}calendar-color' => '#1976d2',
			// `opaque` = events on this calendar count as busy time. Required
			// for NC to treat the calendar as schedulable; without it the
			// Calendar app's resource picker can't compute
			// scheduleDefaultCalendarUrl and crashes the Vue render.
			'{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp' => new ScheduleCalendarTransp('opaque'),
		]);
	}

	public function deprovision(string $principalUri): void {
		$cal = $this->calDav->getCalendarByUri($principalUri, self::CALENDAR_URI);
		if ($cal !== null && isset($cal['id'])) {
			$this->calDav->deleteCalendar((int) $cal['id']);
		}
	}

	public function calendarUriFor(string $uid): string {
		return '/remote.php/dav/calendars/' . $uid . '/' . self::CALENDAR_URI . '/';
	}
}
