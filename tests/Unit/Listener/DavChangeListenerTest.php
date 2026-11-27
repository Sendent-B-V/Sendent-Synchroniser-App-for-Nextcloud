<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Listener;

use OCA\DAV\Events\AddressBookDeletedEvent;
use OCA\DAV\Events\CalendarCreatedEvent;
use OCA\DAV\Events\CardUpdatedEvent;
use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\ChangeNotification\EventChange;
use OCA\SendentSynchroniser\Db\DirtyCollectionMapper;
use OCA\SendentSynchroniser\Db\EventChangeRowMapper;
use OCA\SendentSynchroniser\Listener\DavChangeListener;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\CursorService;
use OCA\SendentSynchroniser\Service\ChangeNotification\DavEventReferenceExtractor;
use OCA\SendentSynchroniser\Service\ChangeNotification\PostCommitQueue;
use OCA\SendentSynchroniser\Service\ChangeNotification\RestoredEventUidReader;
use OCA\SendentSynchroniser\Service\ChangeNotification\SyncScope;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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

	/** The key of a calendar object row that tells the file is in the trash. */
	private const DELETED_AT = '{http://nextcloud.com/ns}deleted-at';

	/** @var ChangeLedgerService&MockObject */
	private $ledger;

	/** @var PostCommitQueue&MockObject */
	private $postCommit;

	/** @var RestoredEventUidReader&MockObject */
	private $restoredUid;

	private DavChangeListener $listener;

	protected function setUp(): void {
		parent::setUp();
		if (!class_exists(\OCP\Calendar\Events\CalendarObjectCreatedEvent::class)) {
			$this->markTestSkipped('Change notifications require Nextcloud 32+');
		}
		$this->ledger = $this->createMock(ChangeLedgerService::class);
		$this->postCommit = $this->createMock(PostCommitQueue::class);
		$this->restoredUid = $this->createMock(RestoredEventUidReader::class);
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
			$this->restoredUid,
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

	// ── Per-event changes ───────────────────────────────────────────────

	/**
	 * @param class-string<Event> $class one of the calendar object events with the four-argument constructor
	 * @param array<string, mixed> $objectData
	 */
	private function calendarObjectEvent(string $class, array $objectData): Event {
		return new $class(42, self::CALENDAR, [], $objectData);
	}

	/**
	 * Handles the event and expects one call to the ledger.
	 *
	 * @return list<array{string, string, string}> calendar, file name and kind of each change the ledger got
	 */
	private function captureRecordedChanges(Event $event): array {
		$captured = [];
		$this->ledger->expects($this->once())->method('record')->willReturnCallback(
			function (array $refs, array $eventChanges = []) use (&$captured): array {
				foreach ($eventChanges as $change) {
					$this->assertInstanceOf(EventChange::class, $change);
					$captured[] = [$change->calendar->collectionUri, $change->fileName, $change->kind->value];
				}
				return [];
			}
		);
		$this->listener->handle($event);

		return $captured;
	}

	public function testACreatedEventGivesACreatedChange(): void {
		$changes = $this->captureRecordedChanges($this->calendarObjectEvent(
			\OCP\Calendar\Events\CalendarObjectCreatedEvent::class,
			['uri' => 'a.ics'],
		));

		$this->assertSame([['personal', 'a.ics', 'created']], $changes);
	}

	public function testAnUpdatedEventGivesAnUpdatedChange(): void {
		$changes = $this->captureRecordedChanges($this->calendarObjectEvent(
			\OCP\Calendar\Events\CalendarObjectUpdatedEvent::class,
			['uri' => 'a.ics'],
		));

		$this->assertSame([['personal', 'a.ics', 'updated']], $changes);
	}

	public function testADeletedEventOfALiveFileGivesADeletedChange(): void {
		$changes = $this->captureRecordedChanges($this->calendarObjectEvent(
			\OCP\Calendar\Events\CalendarObjectDeletedEvent::class,
			['uri' => 'a.ics', self::DELETED_AT => null],
		));

		$this->assertSame([['personal', 'a.ics', 'deleted']], $changes);
	}

	public function testAPurgeFromTheTrashGivesNoChangeButStillOneCallToTheLedger(): void {
		// The trash step sent "deleted" before. The per-calendar row stays.
		$changes = $this->captureRecordedChanges($this->calendarObjectEvent(
			\OCP\Calendar\Events\CalendarObjectDeletedEvent::class,
			['uri' => 'a-deleted.ics', self::DELETED_AT => 1755676800],
		));

		$this->assertSame([], $changes);
	}

	public function testAMovedToTrashEventGivesADeletedChange(): void {
		$changes = $this->captureRecordedChanges($this->calendarObjectEvent(
			\OCP\Calendar\Events\CalendarObjectMovedToTrashEvent::class,
			['uri' => 'a.ics'],
		));

		$this->assertSame([['personal', 'a.ics', 'deleted']], $changes);
	}

	public function testARestoredEventGivesACreatedChange(): void {
		$changes = $this->captureRecordedChanges($this->calendarObjectEvent(
			\OCP\Calendar\Events\CalendarObjectRestoredEvent::class,
			['uri' => 'a.ics'],
		));

		$this->assertSame([['personal', 'a.ics', 'created']], $changes);
	}

	// ── The UID of the event ────────────────────────────────────────────

	/**
	 * Handles the event and expects one call to the ledger.
	 *
	 * @return list<string|null> the UID of each change the ledger got
	 */
	private function captureRecordedUids(Event $event): array {
		$captured = [];
		$this->ledger->expects($this->once())->method('record')->willReturnCallback(
			function (array $refs, array $eventChanges = []) use (&$captured): array {
				foreach ($eventChanges as $change) {
					$captured[] = $change->uid;
				}
				return [];
			}
		);
		$this->listener->handle($event);

		return $captured;
	}

	public function testARestoredEventGetsItsUidFromTheReader(): void {
		// The restore event of Nextcloud has no uid in its object data.
		$objectData = ['uri' => 'a.ics', 'calendarid' => 42, 'calendartype' => 0];
		$this->restoredUid->expects($this->once())
			->method('uidFor')
			->with(42, $objectData)
			->willReturn('uid-of-a');

		$uids = $this->captureRecordedUids($this->calendarObjectEvent(
			\OCP\Calendar\Events\CalendarObjectRestoredEvent::class,
			$objectData,
		));

		$this->assertSame(['uid-of-a'], $uids);
	}

	public function testARestoredEventWithoutAUidIsStillRecorded(): void {
		$this->restoredUid->method('uidFor')->willReturn(null);

		$uids = $this->captureRecordedUids($this->calendarObjectEvent(
			\OCP\Calendar\Events\CalendarObjectRestoredEvent::class,
			['uri' => 'a.ics'],
		));

		$this->assertSame([null], $uids);
	}

	public function testARestoreOutOfScopeDoesNotReadTheUid(): void {
		$this->listener = $this->listenerWithScope(static fn (): bool => false);
		$this->restoredUid->expects($this->never())->method('uidFor');
		$this->ledger->expects($this->never())->method('record');

		$this->listener->handle($this->calendarObjectEvent(
			\OCP\Calendar\Events\CalendarObjectRestoredEvent::class,
			['uri' => 'a.ics'],
		));
	}

	public function testTheOtherKindsTakeTheUidFromTheEventAndDoNotUseTheReader(): void {
		$this->restoredUid->expects($this->never())->method('uidFor');
		$classes = [
			\OCP\Calendar\Events\CalendarObjectCreatedEvent::class,
			\OCP\Calendar\Events\CalendarObjectUpdatedEvent::class,
			\OCP\Calendar\Events\CalendarObjectDeletedEvent::class,
			\OCP\Calendar\Events\CalendarObjectMovedToTrashEvent::class,
		];

		$uids = [];
		$this->ledger->method('record')->willReturnCallback(
			function (array $refs, array $eventChanges = []) use (&$uids): array {
				foreach ($eventChanges as $change) {
					$uids[] = $change->uid;
				}
				return [];
			}
		);
		foreach ($classes as $class) {
			$this->listener->handle($this->calendarObjectEvent($class, ['uri' => 'a.ics', 'uid' => 'uid-of-a']));
		}

		$this->assertSame(['uid-of-a', 'uid-of-a', 'uid-of-a', 'uid-of-a'], $uids);
	}

	public function testBothChangesOfAMoveHoldTheUid(): void {
		$this->restoredUid->expects($this->never())->method('uidFor');

		$uids = $this->captureRecordedUids($this->calendarObjectMovedEvent(
			42,
			self::CALENDAR,
			43,
			self::OTHER_CALENDAR,
			[],
			[],
			['uri' => 'a.ics', 'uid' => 'uid-of-a']
		));

		$this->assertSame(['uid-of-a', 'uid-of-a'], $uids);
	}

	public function testAMovedEventGivesTwoChangesInOneCallToTheLedger(): void {
		$changes = $this->captureRecordedChanges($this->calendarObjectMovedEvent(
			42,
			self::CALENDAR,
			43,
			self::OTHER_CALENDAR,
			[],
			[],
			['uri' => 'a.ics']
		));

		$this->assertSame([['personal', 'a.ics', 'deleted'], ['work', 'a.ics', 'created']], $changes);
	}

	public function testOnlyTheSideOfAMoveInScopeGivesAChange(): void {
		$this->listener = $this->listenerWithScope(
			static fn (CollectionReference $ref): bool => $ref->collectionUri === 'work'
		);

		$changes = $this->captureRecordedChanges($this->calendarObjectMovedEvent(
			42,
			self::CALENDAR,
			43,
			self::OTHER_CALENDAR,
			[],
			[],
			['uri' => 'a.ics']
		));

		$this->assertSame([['work', 'a.ics', 'created']], $changes);
	}

	public function testAContactEventGivesNoPerEventChange(): void {
		$changes = $this->captureRecordedChanges(
			new CardUpdatedEvent(7, self::ADDRESS_BOOK, [], ['uri' => 'c.vcf'])
		);

		$this->assertSame([], $changes);
	}

	public function testACalendarLevelEventGivesNoPerEventChange(): void {
		$changes = $this->captureRecordedChanges(new CalendarCreatedEvent(42, self::CALENDAR));

		$this->assertSame([], $changes);
	}

	public function testAFailedPerEventWriteIsLoggedOnceAndNeverBreaksTheDavWrite(): void {
		// The real ledger, with a per-event table that fails on each write.
		$eventRows = $this->createMock(EventChangeRowMapper::class);
		$eventRows->method('append')->willThrowException(new \RuntimeException('db down'));
		$next = 9;
		$cursor = $this->createMock(CursorService::class);
		$cursor->method('next')->willReturnCallback(static function () use (&$next): int {
			return ++$next;
		});
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('rollBack');
		$db->expects($this->never())->method('commit');
		$ledger = new ChangeLedgerService(
			$this->createMock(DirtyCollectionMapper::class),
			$cursor,
			$this->createMock(ITimeFactory::class),
			$db,
			$eventRows,
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');
		$logger->expects($this->never())->method('warning');
		$this->postCommit->expects($this->never())->method('add');
		$scope = $this->createMock(SyncScope::class);
		$scope->method('includes')->willReturn(true);
		$listener = new DavChangeListener($ledger, $this->postCommit, new DavEventReferenceExtractor(), $scope, $logger, $this->restoredUid);

		$listener->handle($this->calendarObjectCreatedEvent(42, self::CALENDAR, [], ['uri' => 'a.ics']));
	}

	public function testSubscriptionEventsNeverReachTheEventLevelCode(): void {
		// The app does not register these events. The id is the id of a
		// user calendar on purpose: the two tables have separate id ranges.
		$extractor = $this->createMock(DavEventReferenceExtractor::class);
		$extractor->expects($this->never())->method($this->anything());
		$this->ledger->expects($this->never())->method('record');
		$this->postCommit->expects($this->never())->method('add');
		$scope = $this->createMock(SyncScope::class);
		$scope->method('includes')->willReturn(true);
		$listener = new DavChangeListener($this->ledger, $this->postCommit, $extractor, $scope, new NullLogger(), $this->restoredUid);

		$subscription = ['id' => 42, 'uri' => 'holidays', 'principaluri' => 'principals/users/alice'];
		$classes = [
			\OCA\DAV\Events\CachedCalendarObjectCreatedEvent::class,
			\OCA\DAV\Events\CachedCalendarObjectUpdatedEvent::class,
			\OCA\DAV\Events\CachedCalendarObjectDeletedEvent::class,
		];
		foreach ($classes as $class) {
			$listener->handle(new $class(42, $subscription, [], ['uri' => 'a.ics']));
		}
	}
}
