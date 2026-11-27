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
 * A UID is extra data. A failed read must not stop the row or the user's
 * restore, so this class never throws.
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

		try {
			$event = $this->backend->getCalendarObject($calendarId, $uri);
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
