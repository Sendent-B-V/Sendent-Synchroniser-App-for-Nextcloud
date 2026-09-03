<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Listener;

use OCA\DAV\Events\AddressBookDeletedEvent;
use OCA\DAV\Events\CalendarCreatedEvent;
use OCA\DAV\Events\CardUpdatedEvent;
use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Listener\DavChangeListener;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\DavEventReferenceExtractor;
use OCA\SendentSynchroniser\Service\ChangeNotification\SignalPublisher;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class DavChangeListenerTest extends TestCase {

	private const CALENDAR = [
		'id' => 42,
		'uri' => 'personal',
		'principaluri' => 'principals/users/alice',
		'synctoken' => 9651,
	];

	private const OTHER_CALENDAR = [
		'id' => 43,
		'uri' => 'work',
		'principaluri' => 'principals/users/alice',
		'synctoken' => 12,
	];

	private const ADDRESS_BOOK = [
		'id' => 7,
		'uri' => 'contacts',
		'principaluri' => 'principals/users/bob',
		'synctoken' => 312,
	];

	/** @var ChangeLedgerService&MockObject */
	private $ledger;

	/** @var SignalPublisher&MockObject */
	private $publisher;

	private DavChangeListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->ledger = $this->createMock(ChangeLedgerService::class);
		$this->publisher = $this->createMock(SignalPublisher::class);
		$this->listener = new DavChangeListener(
			$this->ledger,
			$this->publisher,
			new DavEventReferenceExtractor(),
			new NullLogger(),
		);
	}

	/**
	 * NC 32 removed the OCA\DAV object-level events in favour of the OCP
	 * family (@since 32.0.0, identical constructor and getters); instantiate
	 * whichever flavour exists on the server checkout the tests run against,
	 * so the CI matrix exercises the OCA path on NC <= 31 and the OCP path
	 * on NC 32+, mirroring production.
	 *
	 * @param array<string, mixed> $calendarData
	 * @param array<string, mixed> $objectData
	 */
	private function calendarObjectCreatedEvent(int $calendarId, array $calendarData, array $shares, array $objectData): Event {
		$class = class_exists(\OCA\DAV\Events\CalendarObjectCreatedEvent::class)
			? \OCA\DAV\Events\CalendarObjectCreatedEvent::class
			: \OCP\Calendar\Events\CalendarObjectCreatedEvent::class;

		return new $class($calendarId, $calendarData, $shares, $objectData);
	}

	/**
	 * @param array<string, mixed> $sourceCalendarData
	 * @param array<string, mixed> $targetCalendarData
	 * @param array<string, mixed> $objectData
	 */
	private function calendarObjectMovedEvent(int $sourceId, array $sourceCalendarData, int $targetId, array $targetCalendarData, array $sourceShares, array $targetShares, array $objectData): Event {
		$class = class_exists(\OCA\DAV\Events\CalendarObjectMovedEvent::class)
			? \OCA\DAV\Events\CalendarObjectMovedEvent::class
			: \OCP\Calendar\Events\CalendarObjectMovedEvent::class;

		return new $class($sourceId, $sourceCalendarData, $targetId, $targetCalendarData, $sourceShares, $targetShares, $objectData);
	}

	/** @return CollectionReference[] */
	private function captureRecordedRefs(Event $event): array {
		$captured = [];
		$this->ledger->method('record')->willReturnCallback(
			function (array $refs) use (&$captured): int {
				$captured = $refs;
				return 1;
			}
		);
		$this->listener->handle($event);

		return $captured;
	}

	public function testObjectEventRecordsANonStructuralReference(): void {
		$refs = $this->captureRecordedRefs(
			$this->calendarObjectCreatedEvent(42, self::CALENDAR, [], ['uri' => 'a.ics'])
		);

		$this->assertCount(1, $refs);
		$this->assertSame('personal', $refs[0]->collectionUri);
		$this->assertSame('caldav', $refs[0]->collectionType);
		$this->assertFalse($refs[0]->collectionChanged);
	}

	public function testCollectionEventRecordsAStructuralReference(): void {
		$refs = $this->captureRecordedRefs(new CalendarCreatedEvent(42, self::CALENDAR));

		$this->assertCount(1, $refs);
		$this->assertTrue($refs[0]->collectionChanged);
	}

	public function testMovedEventRecordsBothSourceAndTarget(): void {
		$refs = $this->captureRecordedRefs($this->calendarObjectMovedEvent(
			42,
			self::CALENDAR,
			43,
			self::OTHER_CALENDAR,
			[],
			[],
			['uri' => 'a.ics']
		));

		$uris = array_map(static fn (CollectionReference $r) => $r->collectionUri, $refs);
		sort($uris);
		$this->assertSame(['personal', 'work'], $uris);
	}

	public function testCardEventRecordsACarddavReference(): void {
		$refs = $this->captureRecordedRefs(
			new CardUpdatedEvent(7, self::ADDRESS_BOOK, [], ['uri' => 'c.vcf'])
		);

		$this->assertCount(1, $refs);
		$this->assertSame('carddav', $refs[0]->collectionType);
		$this->assertFalse($refs[0]->collectionChanged);
	}

	public function testAddressBookEventRecordsAStructuralCarddavReference(): void {
		$refs = $this->captureRecordedRefs(new AddressBookDeletedEvent(7, self::ADDRESS_BOOK, []));

		$this->assertCount(1, $refs);
		$this->assertSame('carddav', $refs[0]->collectionType);
		$this->assertTrue($refs[0]->collectionChanged);
	}

	public function testACardMovedEventRecordsBothAddressBooks(): void {
		if (!class_exists(\OCA\DAV\Events\CardMovedEvent::class)) {
			$this->markTestSkipped('CardMovedEvent exists since NC 32');
		}

		$otherBook = ['id' => 8, 'uri' => 'work-contacts', 'principaluri' => 'principals/users/bob', 'synctoken' => 5];
		$refs = $this->captureRecordedRefs(new \OCA\DAV\Events\CardMovedEvent(
			7,
			self::ADDRESS_BOOK,
			8,
			$otherBook,
			[],
			[],
			['uri' => 'c.vcf']
		));

		$uris = array_map(static fn (CollectionReference $r) => $r->collectionUri, $refs);
		sort($uris);
		$this->assertSame(['contacts', 'work-contacts'], $uris);
	}

	public function testUnrelatedEventsAreIgnored(): void {
		$this->ledger->expects($this->never())->method('record');
		$this->publisher->expects($this->never())->method('flushIfDue');

		$this->listener->handle(new class extends Event {
		});
	}

	public function testAFlushIsOfferedAfterRecording(): void {
		$this->ledger->method('record')->willReturn(1);
		$this->publisher->expects($this->once())->method('flushIfDue');

		$this->listener->handle($this->calendarObjectCreatedEvent(42, self::CALENDAR, [], ['uri' => 'a.ics']));
	}

	public function testAFailingLedgerNeverBreaksTheDavWrite(): void {
		// The listener runs inside CalDavBackend's still-open transaction; a
		// throw here would roll back the user's own calendar write.
		$this->ledger->method('record')->willThrowException(new \RuntimeException('db down'));

		$this->listener->handle($this->calendarObjectCreatedEvent(42, self::CALENDAR, [], ['uri' => 'a.ics']));

		$this->addToAssertionCount(1);
	}

	public function testAFailingPublisherNeverBreaksTheDavWrite(): void {
		$this->ledger->method('record')->willReturn(1);
		$this->publisher->method('flushIfDue')->willThrowException(new \RuntimeException('redis down'));

		$this->listener->handle($this->calendarObjectCreatedEvent(42, self::CALENDAR, [], ['uri' => 'a.ics']));

		$this->addToAssertionCount(1);
	}
}
