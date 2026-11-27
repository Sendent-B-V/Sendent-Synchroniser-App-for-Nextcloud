<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\DAV\CalDAV\CalDavBackend;
use Psr\Log\LoggerInterface;

/**
 * Reads the UID of an event that was restored from the trash bin. The
 * restore event of Nextcloud does not hold the UID, so it is read from the
 * calendar backend, inside the transaction of the restore.
 *
 * This is the only class that calls the backend for the UID. The backend is
 * not public Nextcloud API.
 *
 * A UID is extra data, so this class never throws: a failed read gives no
 * UID and the row is still written. This does not protect the restore from
 * a failed query. On PostgreSQL a failed query stops the transaction also
 * when the error is caught.
 */
class RestoredEventUidReader {

	public function __construct(
		private CalDavBackend $backend,
		private LoggerInterface $logger,
	) {}

	/**
	 * @param int $calendarId the id of the calendar the event is in
	 * @param array<string, mixed>|null $objectRow the object row of the restore event
	 * @return string|null the UID, or null when the row has no usable file
	 *                     name, the backend finds no event, the event has no
	 *                     usable UID, or the read fails. A failed read is
	 *                     logged.
	 */
	public function uidFor(int $calendarId, ?array $objectRow): ?string {
		$uri = $objectRow['uri'] ?? null;
		if (!is_string($uri) || $uri === '') {
			return null;
		}

		// The row says which kind of calendar the event is in. A database
		// can give the number as text; the backend accepts an int only.
		$type = $objectRow['calendartype'] ?? null;
		$calendarType = is_numeric($type) ? (int)$type : CalDavBackend::CALENDAR_TYPE_CALENDAR;

		try {
			$event = $this->backend->getCalendarObject($calendarId, $uri, $calendarType);
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to read event from backend', ['exception' => $e, 'app' => 'sendentsynchroniser']);
			return null;
		}

		$uid = $event['uid'] ?? null;
		if (!is_string($uid) || $uid === '') {
			return null;
		}
		return $uid;
	}
}
