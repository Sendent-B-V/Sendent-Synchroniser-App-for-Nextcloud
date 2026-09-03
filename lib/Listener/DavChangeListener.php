<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Listener;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\DavEventReferenceExtractor;
use OCA\SendentSynchroniser\Service\ChangeNotification\SignalPublisher;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Runs in-request, inside the DAV backend's still-open atomic() transaction,
 * so it must never throw: a throw here rolls back the user's own calendar or
 * contact write. Every failure is caught and logged.
 *
 * Change notifications require Nextcloud 32+: object-level events are matched
 * only in their OCP\Calendar\Events form (@since 32.0.0). On older servers
 * those classes do not exist, instanceof evaluates to false, and the feature
 * is simply inert — the app itself still runs. Collection-level and CardDAV
 * events remain in OCA\DAV\Events on every version.
 */
class DavChangeListener implements IEventListener {

	/** True once this request already logged a ledger/publisher failure. */
	private bool $failureLogged = false;

	public function __construct(
		private ChangeLedgerService $ledger,
		private SignalPublisher $publisher,
		private DavEventReferenceExtractor $extractor,
		private LoggerInterface $logger,
	) {}

	public function handle(Event $event): void {
		try {
			$refs = $this->references($event);
			if ($refs === []) {
				return;
			}

			$this->ledger->record($refs);
			$this->publisher->flushIfDue();
		} catch (\Throwable $e) {
			// Log the first failure per request at ERROR with the trace; any
			// further events in the same request degrade to a terse warning so
			// a dead DB cannot flood the log with duplicate stack traces. A
			// cross-request throttle is deliberately absent: it would add a
			// failable dependency (cache) to this never-throw path.
			if ($this->failureLogged) {
				$this->logger->warning('Further DAV change-recording failure in this request: ' . $e->getMessage(), [
					'app' => 'sendentsynchroniser',
				]);
				return;
			}
			$this->failureLogged = true;
			$this->logger->error('Failed to record a DAV change for the Connector: ' . $e->getMessage(), [
				'exception' => $e,
				'app' => 'sendentsynchroniser',
			]);
		}
	}

	/** @return CollectionReference[] */
	private function references(Event $event): array {
		// ── CalDAV, object level (OCP, @since 32.0.0) ───────────────────
		if ($event instanceof \OCP\Calendar\Events\CalendarObjectCreatedEvent
			|| $event instanceof \OCP\Calendar\Events\CalendarObjectUpdatedEvent
			|| $event instanceof \OCP\Calendar\Events\CalendarObjectDeletedEvent
			|| $event instanceof \OCP\Calendar\Events\CalendarObjectMovedToTrashEvent
			|| $event instanceof \OCP\Calendar\Events\CalendarObjectRestoredEvent) {
			return $this->one($this->extractor->fromCalendarRow($event->getCalendarData(), false));
		}

		if ($event instanceof \OCP\Calendar\Events\CalendarObjectMovedEvent) {
			return array_merge(
				$this->one($this->extractor->fromCalendarRow($event->getSourceCalendarData(), false)),
				$this->one($this->extractor->fromCalendarRow($event->getTargetCalendarData(), false)),
			);
		}

		// ── CalDAV, collection level (OCA on every version, NC 33 included) ─
		if ($event instanceof \OCA\DAV\Events\CalendarCreatedEvent
			|| $event instanceof \OCA\DAV\Events\CalendarUpdatedEvent
			|| $event instanceof \OCA\DAV\Events\CalendarDeletedEvent
			|| $event instanceof \OCA\DAV\Events\CalendarMovedToTrashEvent
			|| $event instanceof \OCA\DAV\Events\CalendarRestoredEvent
			|| $event instanceof \OCA\DAV\Events\CalendarShareUpdatedEvent
			|| $event instanceof \OCA\DAV\Events\CalendarPublishedEvent
			|| $event instanceof \OCA\DAV\Events\CalendarUnpublishedEvent) {
			return $this->one($this->extractor->fromCalendarRow($event->getCalendarData(), true));
		}

		// ── CardDAV, object level ───────────────────────────────────────
		if ($event instanceof \OCA\DAV\Events\CardCreatedEvent
			|| $event instanceof \OCA\DAV\Events\CardUpdatedEvent
			|| $event instanceof \OCA\DAV\Events\CardDeletedEvent) {
			return $this->one($this->extractor->fromAddressBookRow($event->getAddressBookData(), false));
		}

		// CardMovedEvent exists since NC 32.
		if ($event instanceof \OCA\DAV\Events\CardMovedEvent) {
			return array_merge(
				$this->one($this->extractor->fromAddressBookRow($event->getSourceAddressBookData(), false)),
				$this->one($this->extractor->fromAddressBookRow($event->getTargetAddressBookData(), false)),
			);
		}

		// ── CardDAV, collection level ───────────────────────────────────
		if ($event instanceof \OCA\DAV\Events\AddressBookCreatedEvent
			|| $event instanceof \OCA\DAV\Events\AddressBookUpdatedEvent
			|| $event instanceof \OCA\DAV\Events\AddressBookDeletedEvent
			|| $event instanceof \OCA\DAV\Events\AddressBookShareUpdatedEvent) {
			return $this->one($this->extractor->fromAddressBookRow($event->getAddressBookData(), true));
		}

		return [];
	}

	/**
	 * @return CollectionReference[]
	 */
	private function one(?CollectionReference $ref): array {
		return $ref === null ? [] : [$ref];
	}
}
