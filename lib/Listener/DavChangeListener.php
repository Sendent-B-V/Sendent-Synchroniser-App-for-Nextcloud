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
 * instanceof against a class that does not exist on the running Nextcloud
 * version evaluates to false, which is what lets one listener cover NC 28-34
 * (the move-to-trash and restore events changed namespace in NC 31.0.2/32).
 * On NC 31 both flavours are dispatched for one delete, arriving as two
 * separate handle() calls; the ledger's upsert makes the second one a
 * harmless re-touch of the same row.
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
		// ── CalDAV, object level ────────────────────────────────────────
		// NC 32 removed the whole OCA\DAV object-level family in favour of
		// OCP\Calendar\Events (@since 32.0.0, identical constructors/getters);
		// both flavours are matched so one listener covers NC 28-34.
		if ($event instanceof \OCA\DAV\Events\CalendarObjectCreatedEvent
			|| $event instanceof \OCA\DAV\Events\CalendarObjectUpdatedEvent
			|| $event instanceof \OCA\DAV\Events\CalendarObjectDeletedEvent
			|| $event instanceof \OCA\DAV\Events\CalendarObjectMovedToTrashEvent
			|| $event instanceof \OCA\DAV\Events\CalendarObjectRestoredEvent
			|| $event instanceof \OCP\Calendar\Events\CalendarObjectCreatedEvent
			|| $event instanceof \OCP\Calendar\Events\CalendarObjectUpdatedEvent
			|| $event instanceof \OCP\Calendar\Events\CalendarObjectDeletedEvent
			|| $event instanceof \OCP\Calendar\Events\CalendarObjectMovedToTrashEvent
			|| $event instanceof \OCP\Calendar\Events\CalendarObjectRestoredEvent) {
			return $this->one($this->extractor->fromCalendarRow($event->getCalendarData(), false));
		}

		if ($event instanceof \OCA\DAV\Events\CalendarObjectMovedEvent
			|| $event instanceof \OCP\Calendar\Events\CalendarObjectMovedEvent) {
			return array_merge(
				$this->one($this->extractor->fromCalendarRow($event->getSourceCalendarData(), false)),
				$this->one($this->extractor->fromCalendarRow($event->getTargetCalendarData(), false)),
			);
		}

		// ── CalDAV, collection level ────────────────────────────────────
		if ($event instanceof \OCA\DAV\Events\CalendarCreatedEvent
			|| $event instanceof \OCA\DAV\Events\CalendarUpdatedEvent
			|| $event instanceof \OCA\DAV\Events\CalendarDeletedEvent
			|| $event instanceof \OCA\DAV\Events\CalendarMovedToTrashEvent
			|| $event instanceof \OCA\DAV\Events\CalendarRestoredEvent
			|| $event instanceof \OCP\Calendar\Events\CalendarMovedToTrashEvent
			|| $event instanceof \OCP\Calendar\Events\CalendarRestoredEvent
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
