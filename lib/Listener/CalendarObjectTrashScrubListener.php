<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Listener;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\SendentSynchroniser\Service\TrashbinScrubService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Strips Sendent custom properties (X-SENDENT*) from a calendar event the
 * moment it is soft-deleted into the calendar trash bin, so a later restore
 * from the trash bin yields the event without them. Restore only renames the
 * URI back and clears deleted_at — it never touches calendardata — so the
 * strip persists through it.
 *
 * Registered for both flavours of the move-to-trash event:
 *  - \OCA\DAV\Events\CalendarObjectMovedToTrashEvent   (NC 22-31; removed in NC 32)
 *  - \OCP\Calendar\Events\CalendarObjectMovedToTrashEvent (since NC 31.0.2)
 * On NC 31 BOTH are dispatched for one delete; the per-request guard makes
 * the second dispatch a no-op. instanceof against the class missing on the
 * running NC version safely evaluates to false.
 *
 * Gates: admin toggle (Constants::TRASHBIN_SCRUB_KEY) AND the event's origin
 * calendar is the personal calendar (calendar URI 'personal').
 *
 * The event fires inside CalDavBackend's still-open atomic() transaction:
 * this listener must never throw, or the whole move-to-trash rolls back.
 * The write-back uses updateCalendarObject() addressed at the renamed
 * '<name>-deleted.<ext>' URI (its UPDATE has no deleted_at filter, so it
 * reaches trashed rows) which recomputes etag/size/occurrences — at the cost
 * of one extra sync-token bump and a CalendarObjectUpdatedEvent, neither of
 * which this app listens to.
 */
class CalendarObjectTrashScrubListener implements IEventListener {

	/**
	 * Objects already scrubbed during this request, keyed calendarId|uri.
	 * Collapses NC 31's double dispatch (OCP + legacy OCA event) into one write.
	 *
	 * @var array<string, true>
	 */
	private array $handled = [];

	public function __construct(
		private TrashbinScrubService $scrubService,
		private CalDavBackend $calDavBackend,
		private LoggerInterface $logger,
	) {}

	public function handle(Event $event): void {
		if (!$event instanceof \OCA\DAV\Events\CalendarObjectMovedToTrashEvent
			&& !$event instanceof \OCP\Calendar\Events\CalendarObjectMovedToTrashEvent) {
			return;
		}

		try {
			$this->scrub($event->getCalendarId(), $event->getCalendarData(), $event->getObjectData());
		} catch (\Throwable $e) {
			// We are inside the soft-delete's DB transaction — a throw here
			// would roll back the user's delete. Log and move on.
			$this->logger->error('Failed to scrub Sendent properties from trashed calendar object: ' . $e->getMessage(), [
				'exception' => $e,
				'app' => 'sendentsynchroniser',
			]);
		}
	}

	/**
	 * @param array<string, mixed> $calendarRow the calendar's row (event payload)
	 * @param array<string, mixed> $objectRow   the object's PRE-delete row: original
	 *                                          uri (before the '-deleted' rename) and
	 *                                          full calendardata
	 */
	private function scrub(int $calendarId, array $calendarRow, array $objectRow): void {
		if (!$this->scrubService->isEnabled()) {
			return;
		}

		if (($calendarRow['uri'] ?? null) !== CalDavBackend::PERSONAL_CALENDAR_URI) {
			return;
		}

		$originalUri = $objectRow['uri'] ?? null;
		if (!is_string($originalUri) || $originalUri === '') {
			return;
		}

		$guardKey = $calendarId . '|' . $originalUri;
		if (isset($this->handled[$guardKey])) {
			return;
		}
		$this->handled[$guardKey] = true;

		$calendarData = $objectRow['calendardata'] ?? null;
		if (is_resource($calendarData)) {
			$calendarData = stream_get_contents($calendarData);
		}
		if (!is_string($calendarData) || $calendarData === '') {
			return;
		}

		$stripped = $this->scrubService->stripSendentProperties($calendarData);
		if ($stripped === null) {
			return;
		}

		// The DB row was already renamed by the soft delete; the event payload
		// still carries the pre-rename URI. Mirror CalDavBackend's rename to
		// address the trashed row.
		$this->calDavBackend->updateCalendarObject($calendarId, $this->deriveTrashedUri($originalUri), $stripped);

		$this->logger->debug('Scrubbed Sendent properties from trashed calendar object {uri} in calendar {calendarId}', [
			'uri' => $originalUri,
			'calendarId' => $calendarId,
			'app' => 'sendentsynchroniser',
		]);
	}

	/**
	 * Mirrors CalDavBackend::deleteCalendarObject's trash rename:
	 * event.ics -> event-deleted.ics (no-extension URIs get a bare '-deleted').
	 */
	private function deriveTrashedUri(string $uri): string {
		$pathInfo = pathinfo($uri);
		if (!empty($pathInfo['extension'])) {
			return sprintf('%s-deleted.%s', $pathInfo['filename'], $pathInfo['extension']);
		}
		return sprintf('%s-deleted', $pathInfo['filename']);
	}
}
