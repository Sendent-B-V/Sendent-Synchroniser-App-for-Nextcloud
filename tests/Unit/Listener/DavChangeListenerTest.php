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
use OCA\SendentSynchroniser\Service\ChangeNotification\PostCommitQueue;
use OCA\SendentSynchroniser\Service\ChangeNotification\SyncScope;
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

	/** @var PostCommitQueue&MockObject */
	private $postCommit;

	private DavChangeListener $listener;

	protected function setUp(): void {
		parent::setUp();
		if (!class_exists(\OCP\Calendar\Events\CalendarObjectCreatedEvent::class)) {
			$this->markTestSkipped('Change notifications require Nextcloud 32+');
		}
		$this->ledger = $this->createMock(ChangeLedgerService::class);
		$this->postCommit = $this->createMock(PostCommitQueue::class);
		$this->listener = $this->listenerWithScope(static fn (): bool => true);
	}

	/** @param callable(CollectionReference): bool $includes */
	private function listenerWithScope(callable $includes): DavChangeListener {
		$scope = $this->createMock(SyncScope::class);
		$scope->method('includes')->willReturnCallback($includes);

		return new DavChangeListener(
			$this->ledger,
			$this->postCommit,
			new DavEventReferenceExtractor(),
			$scope,
			new NullLogger(),
		);
	}

	/**
	 * @param array<string, mixed> $calendarData
	 * @param array<string, mixed> $objectData
	 */
	private function calendarObjectCreatedEvent(int $calendarId, array $calendarData, array $shares, array $objectData): Event {
		return new \OCP\Calendar\Events\CalendarObjectCreatedEvent($calendarId, $calendarData, $shares, $objectData);
	}

	/**
	 * @param array<string, mixed> $sourceCalendarData
	 * @param array<string, mixed> $targetCalendarData
	 * @param array<string, mixed> $objectData
	 */
	private function calendarObjectMovedEvent(int $sourceId, array $sourceCalendarData, int $targetId, array $targetCalendarData, array $sourceShares, array $targetShares, array $objectData): Event {
		return new \OCP\Calendar\Events\CalendarObjectMovedEvent($sourceId, $sourceCalendarData, $targetId, $targetCalendarData, $sourceShares, $targetShares, $objectData);
	}

	/** @return CollectionReference[] */
	private function captureRecordedRefs(Event $event): array {
		$captured = [];
		$this->ledger->method('record')->willReturnCallback(
			function (array $refs) use (&$captured): array {
				$captured = $refs;
				return [];
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

	public function testChangesOfUsersWhoDoNotSyncAreNeverRecorded(): void {
		$listener = $this->listenerWithScope(static fn (): bool => false);
		$this->ledger->expects($this->never())->method('record');
		$this->postCommit->expects($this->never())->method('add');

		$listener->handle($this->calendarObjectCreatedEvent(42, self::CALENDAR, [], ['uri' => 'a.ics']));
	}

	public function testOnlyTheSyncedSideOfAMoveIsRecorded(): void {
		$this->listener = $this->listenerWithScope(
			static fn (CollectionReference $ref): bool => $ref->collectionUri === 'work'
		);

		$refs = $this->captureRecordedRefs($this->calendarObjectMovedEvent(
			42,
			self::CALENDAR,
			43,
			self::OTHER_CALENDAR,
			[],
			[],
			['uri' => 'a.ics']
		));

		$this->assertSame(['work'], array_map(static fn (CollectionReference $r) => $r->collectionUri, $refs));
	}

	public function testAPublicLinkToggleIsNotAChange(): void {
		// Publishing a calendar changes nothing the Connector mirrors.
		$this->ledger->expects($this->never())->method('record');

		$this->listener->handle(new \OCA\DAV\Events\CalendarPublishedEvent(42, self::CALENDAR, 'https://cloud.example.com/p/abc'));
		$this->listener->handle(new \OCA\DAV\Events\CalendarUnpublishedEvent(42, self::CALENDAR));
	}

	public function testUnrelatedEventsAreIgnored(): void {
		$this->ledger->expects($this->never())->method('record');
		$this->postCommit->expects($this->never())->method('add');

		$this->listener->handle(new class extends Event {
		});
	}

	public function testTheStampsWaitInThePostCommitQueue(): void {
		// The listener runs inside the DAV transaction. A hint sent from here
		// would reach the Connector before the change is visible, so the
		// stamps wait in the queue until the write has committed.
		$ref = new CollectionReference('principals/users/alice', 'caldav', 'personal', false);
		$stamps = [['ref' => $ref, 'seq' => 17]];
		$this->ledger->method('record')->willReturn($stamps);
		$this->postCommit->expects($this->once())->method('add')->with($stamps);

		$this->listener->handle($this->calendarObjectCreatedEvent(42, self::CALENDAR, [], ['uri' => 'a.ics']));
	}

	public function testAFailingLedgerNeverBreaksTheDavWrite(): void {
		// The listener runs inside CalDavBackend's still-open transaction; a
		// throw here would roll back the user's own calendar write.
		$this->ledger->method('record')->willThrowException(new \RuntimeException('db down'));
		$this->postCommit->expects($this->never())->method('add');

		$this->listener->handle($this->calendarObjectCreatedEvent(42, self::CALENDAR, [], ['uri' => 'a.ics']));
	}

	public function testAFailingQueueNeverBreaksTheDavWrite(): void {
		$this->ledger->method('record')->willReturn([]);
		$this->postCommit->method('add')->willThrowException(new \RuntimeException('redis down'));

		$this->listener->handle($this->calendarObjectCreatedEvent(42, self::CALENDAR, [], ['uri' => 'a.ics']));

		$this->addToAssertionCount(1);
	}
}
