<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\ChangeNotification;

/**
 * One change to one event file: what a per-event feed row is built from.
 * Holds no calendar data; the Connector fetches the file itself.
 */
final class EventChange {

	public function __construct(
		/** The calendar the file is in (created, updated) or was in (deleted). Always a CalDAV, object-level reference. */
		public readonly CollectionReference $calendar,
		/** The file name inside that calendar, e.g. "meeting.ics". Never a trash name. */
		public readonly string $fileName,
		public readonly EventChangeKind $kind,
	) {}
}
