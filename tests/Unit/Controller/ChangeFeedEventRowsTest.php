<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Controller;

use OCA\SendentSynchroniser\Controller\ChangeFeedApiController;
use OCA\SendentSynchroniser\Db\DirtyCollection;
use OCA\SendentSynchroniser\Db\DirtyCollectionMapper;
use OCA\SendentSynchroniser\Db\EventChangeRow;
use OCA\SendentSynchroniser\Db\EventChangeRowMapper;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeFeedGuard;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\CursorService;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushAvailability;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The feed read with the two kinds of row. The ledger is the real one, so
 * a test covers the join and the row shape together. Only the two mappers
 * are replaced: each gives its rows above $since, lowest first, up to the limit.
 */
class ChangeFeedEventRowsTest extends TestCase {

	private const ALICE = 'principals/users/alice';

	/** @var list<DirtyCollection> lowest number first */
	private array $calendarRows = [];

	/** @var list<EventChangeRow> lowest number first */
	private array $eventRows = [];

	/** @var CursorService&MockObject */
	private $cursor;

	private ChangeFeedApiController $controller;

	protected function setUp(): void {
		parent::setUp();

		$calendarMapper = $this->createMock(DirtyCollectionMapper::class);
		$calendarMapper->method('page')->willReturnCallback(
			fn (int $since, int $limit): array => self::pageOf(
				$this->calendarRows,
				static fn (DirtyCollection $row): int => $row->getChangeSeq(),
				$since,
				$limit,
			)
		);
		$eventMapper = $this->createMock(EventChangeRowMapper::class);
		$eventMapper->method('page')->willReturnCallback(
			fn (int $since, int $limit): array => self::pageOf(
				$this->eventRows,
				static fn (EventChangeRow $row): int => $row->getSequenceNumber(),
				$since,
				$limit,
			)
		);

		$this->cursor = $this->createMock(CursorService::class);
		$ledger = new ChangeLedgerService(
			$calendarMapper,
			$this->cursor,
			$this->createMock(ITimeFactory::class),
			$this->createMock(IDBConnection::class),
			$eventMapper,
		);

		$guard = $this->createMock(ChangeFeedGuard::class);
		$guard->method('isAllowed')->willReturn(true);
		$serverConfig = $this->createMock(IConfig::class);
		$serverConfig->method('getSystemValueString')->with('instanceid')->willReturn('oc1a2b3c4d5e');

		$this->controller = new ChangeFeedApiController(
			'sendentsynchroniser',
			$this->createMock(IRequest::class),
			$guard,
			$ledger,
			$this->createMock(ChangeNotificationConfig::class),
			$this->cursor,
			$this->createMock(NotifyPushAvailability::class),
			$serverConfig,
		);
	}

	/**
	 * What a mapper's page() gives. $rows must be in number order already.
	 *
	 * @template T
	 * @param list<T> $rows
	 * @param callable(T): int $number
	 * @return list<T>
	 */
	private static function pageOf(array $rows, callable $number, int $since, int $limit): array {
		$above = array_values(array_filter($rows, static fn ($row): bool => $number($row) > $since));

		return array_slice($above, 0, max(1, $limit));
	}

	private function fence(int $fence): void {
		$this->cursor->method('current')->willReturn($fence);
	}

	/** Adds a per-calendar or address-book row. Add rows of one kind in number order. */
	private function calendarRow(int $seq, string $uri = 'personal', string $type = 'caldav', int $structuralSeq = 0): void {
		$row = new DirtyCollection();
		$row->setPrincipalUri(self::ALICE);
		$row->setCollectionType($type);
		$row->setCollectionUri($uri);
		$row->setChangeSeq($seq);
		$row->setStructuralSeq($structuralSeq);
		$row->setUpdatedAt(1);
		$this->calendarRows[] = $row;
	}

	/** Adds a per-event row. Add rows of one kind in number order. */
	private function eventRow(int $seq, string $fileName = 'one.ics', string $kind = 'updated', string $calendar = 'personal', ?string $uid = null): void {
		$row = new EventChangeRow();
		$row->setEventUid($uid);
		$row->setPrincipalUri(self::ALICE);
		$row->setCalendarUri($calendar);
		$row->setEventFileName($fileName);
		$row->setKind($kind);
		$row->setSequenceNumber($seq);
		$row->setWrittenTime(1);
		$this->eventRows[] = $row;
	}

	/** @return array<string, mixed> the response body */
	private function read(int $since, int $limit = 100): array {
		return $this->controller->changes($since, $limit)->getData();
	}

	/**
	 * @param array<string, mixed> $data
	 * @return list<int> the q of each row, in response order
	 */
	private static function numbers(array $data): array {
		return array_column($data['refs'], 'q');
	}

	/**
	 * The order of keys in a row carries no meaning, so sort them away.
	 *
	 * @param list<array<string, mixed>> $refs
	 * @return list<array<string, mixed>>
	 */
	private static function keysSorted(array $refs): array {
		return array_map(static function (array $ref): array {
			ksort($ref);
			return $ref;
		}, $refs);
	}

	public function testTheSharedTestVectorOfTheContract(): void {
		// Alice saved a contact, then changed one.ics in her calendar "personal".
		$this->fence(1052);
		$this->calendarRow(1049, 'contacts', 'carddav');
		$this->eventRow(1051, 'one.ics', 'updated', 'personal', '5f2c9a1e-7b3d-4c60-9a41-2d8e6f0b1c77');
		$this->calendarRow(1052, 'personal', 'caldav');

		$data = $this->read(1000);

		$this->assertSame(1, $data['v']);
		$this->assertSame('oc1a2b3c4d5e', $data['instance']);
		$this->assertSame(1052, $data['cursor']);
		$this->assertFalse($data['has_more']);
		$this->assertSame(['v', 'instance', 'cursor', 'refs', 'has_more'], array_keys($data));
		$this->assertSame(self::keysSorted([
			['p' => self::ALICE, 't' => 'carddav', 'u' => 'contacts', 'c' => false, 'q' => 1049],
			['p' => self::ALICE, 't' => 'caldav', 'u' => 'personal', 'c' => false, 'q' => 1051, 'o' => 'one.ics', 'k' => 'updated', 'i' => '5f2c9a1e-7b3d-4c60-9a41-2d8e6f0b1c77'],
			['p' => self::ALICE, 't' => 'caldav', 'u' => 'personal', 'c' => false, 'q' => 1052],
		]), self::keysSorted($data['refs']));
	}

	// ── The UID of the event ────────────────────────────────────────────

	public function testAPerEventRowWithAUidHasTheUidField(): void {
		$this->fence(2000);
		$this->eventRow(1001, 'one.ics', 'created', 'personal', 'uid-of-one');

		$refs = $this->read(1000)['refs'];

		$this->assertSame('uid-of-one', $refs[0]['i'] ?? null);
		$this->assertSame(['c', 'i', 'k', 'o', 'p', 'q', 't', 'u'], array_keys(self::keysSorted($refs)[0]));
	}

	public function testAPerEventRowWithoutAUidHasNoUidField(): void {
		// A row written before the UID column was there reads back as null.
		$this->fence(2000);
		$this->eventRow(1001, 'one.ics', 'created', 'personal', null);

		$refs = $this->read(1000)['refs'];

		$this->assertArrayNotHasKey('i', $refs[0]);
		$this->assertSame(['c', 'k', 'o', 'p', 'q', 't', 'u'], array_keys(self::keysSorted($refs)[0]));
	}

	public function testAnEmptyUidInTheRowIsLeftOut(): void {
		// The write path never stores '', but the response must not send it.
		$this->fence(2000);
		$this->eventRow(1001, 'one.ics', 'created', 'personal', '');

		$this->assertArrayNotHasKey('i', $this->read(1000)['refs'][0]);
	}

	public function testRowsWithAndWithoutAUidInOneResponse(): void {
		$this->fence(2000);
		$this->eventRow(1001, 'old.ics', 'updated', 'personal', null);
		$this->eventRow(1002, 'new.ics', 'updated', 'personal', 'uid-of-new');

		$refs = $this->read(1000)['refs'];

		$this->assertArrayNotHasKey('i', $refs[0]);
		$this->assertSame('uid-of-new', $refs[1]['i'] ?? null);
	}

	public function testAPerCalendarRowAndAnAddressBookRowHaveNoUidField(): void {
		$this->fence(2000);
		$this->calendarRow(1001, 'contacts', 'carddav');
		$this->eventRow(1002, 'one.ics', 'updated', 'personal', 'uid-of-one');
		$this->calendarRow(1003, 'personal', 'caldav');

		$refs = $this->read(1000)['refs'];

		$this->assertArrayNotHasKey('i', $refs[0]);
		$this->assertArrayHasKey('i', $refs[1]);
		$this->assertArrayNotHasKey('i', $refs[2]);
	}

	public function testOnlyPerEventRows(): void {
		$this->fence(2000);
		$this->eventRow(1001, 'one.ics', 'created');
		$this->eventRow(1002, 'two.ics', 'deleted', 'work');

		$data = $this->read(1000);

		$this->assertSame(self::keysSorted([
			['p' => self::ALICE, 't' => 'caldav', 'u' => 'personal', 'c' => false, 'q' => 1001, 'o' => 'one.ics', 'k' => 'created'],
			['p' => self::ALICE, 't' => 'caldav', 'u' => 'work', 'c' => false, 'q' => 1002, 'o' => 'two.ics', 'k' => 'deleted'],
		]), self::keysSorted($data['refs']));
		$this->assertSame(1002, $data['cursor']);
		$this->assertFalse($data['has_more']);
	}

	public function testOnlyPerCalendarRows(): void {
		$this->fence(2000);
		$this->calendarRow(1001, 'personal');
		$this->calendarRow(1002, 'work');

		$data = $this->read(1000);

		$this->assertSame([1001, 1002], self::numbers($data));
		$this->assertSame(1002, $data['cursor']);
		$this->assertFalse($data['has_more']);
	}

	public function testNoRows(): void {
		$this->fence(2000);

		$data = $this->read(1000);

		$this->assertSame([], $data['refs']);
		$this->assertSame(1000, $data['cursor']);
		$this->assertFalse($data['has_more']);
	}

	public function testRowsOfTheTwoTablesComeOutInNumberOrder(): void {
		$this->fence(2000);
		$this->calendarRow(1001, 'a');
		$this->eventRow(1002);
		$this->eventRow(1003);
		$this->calendarRow(1004, 'b');
		$this->eventRow(1005);
		$this->calendarRow(1006, 'c');

		$data = $this->read(1000);

		$this->assertSame([1001, 1002, 1003, 1004, 1005, 1006], self::numbers($data));
		$this->assertSame(1006, $data['cursor']);
		$this->assertFalse($data['has_more']);
	}

	public function testRowsAtOrBelowSinceAreNotReturned(): void {
		$this->fence(2000);
		$this->eventRow(999);
		$this->eventRow(1000);
		$this->calendarRow(1000, 'a');
		$this->eventRow(1001);
		$this->calendarRow(1002, 'b');

		$this->assertSame([1001, 1002], self::numbers($this->read(1000)));
	}

	public function testAPageThatEndsBetweenAPerEventRowAndItsPerCalendarRow(): void {
		$this->fence(2000);
		$this->calendarRow(1050, 'contacts', 'carddav');
		$this->eventRow(1051);
		$this->calendarRow(1052, 'personal');

		$data = $this->read(1000, 2);

		$this->assertSame([1050, 1051], self::numbers($data));
		$this->assertTrue($data['has_more']);
		// The row that was cut off is 1052. It must stay above the cursor.
		$this->assertSame(1051, $data['cursor']);
	}

	public function testAFullPageOfPerEventRowsBelowAPerCalendarRow(): void {
		$this->fence(2000);
		$this->eventRow(1001);
		$this->eventRow(1002);
		$this->eventRow(1003);
		$this->calendarRow(1004);

		$data = $this->read(1000, 2);

		$this->assertSame([1001, 1002], self::numbers($data));
		$this->assertTrue($data['has_more']);
		$this->assertSame(1002, $data['cursor']);
	}

	public function testAFullPageOfPerCalendarRowsBelowAPerEventRow(): void {
		$this->fence(2000);
		$this->calendarRow(1001, 'a');
		$this->calendarRow(1002, 'b');
		$this->calendarRow(1003, 'c');
		$this->eventRow(1004);

		$data = $this->read(1000, 2);

		$this->assertSame([1001, 1002], self::numbers($data));
		$this->assertTrue($data['has_more']);
		$this->assertSame(1002, $data['cursor']);
	}

	public function testLimitOne(): void {
		$this->fence(2000);
		$this->eventRow(1001);
		$this->calendarRow(1002);

		$data = $this->read(1000, 1);

		$this->assertSame([1001], self::numbers($data));
		$this->assertArrayHasKey('o', $data['refs'][0]);
		$this->assertTrue($data['has_more']);
		$this->assertSame(1001, $data['cursor']);
	}

	public function testALimitEqualToTheNumberOfRows(): void {
		$this->fence(2000);
		$this->eventRow(1001);
		$this->calendarRow(1002, 'a');
		$this->calendarRow(1003, 'b');

		$data = $this->read(1000, 3);

		$this->assertSame([1001, 1002, 1003], self::numbers($data));
		$this->assertFalse($data['has_more']);
		$this->assertSame(1003, $data['cursor']);
	}

	public function testOneRowMoreThanTheLimitOfTheOtherKind(): void {
		// The page is full with one kind; the one row that is left is the other kind.
		$this->fence(2000);
		$this->calendarRow(1001, 'a');
		$this->calendarRow(1002, 'b');
		$this->eventRow(1003);

		$data = $this->read(1000, 2);

		$this->assertSame([1001, 1002], self::numbers($data));
		$this->assertTrue($data['has_more']);
	}

	public function testAPerEventRowAboveTheFenceIsReturnedAndTheCursorStaysAtTheFence(): void {
		$this->fence(1050);
		$this->calendarRow(1040);
		$this->eventRow(1051);

		$data = $this->read(1000);

		$this->assertSame([1040, 1051], self::numbers($data));
		$this->assertSame(1050, $data['cursor']);
	}

	public function testTheCursorIsNeverBelowSince(): void {
		// The fence is below since: the cursor does not go back.
		$this->fence(900);
		$this->eventRow(1001);

		$data = $this->read(1000);

		$this->assertSame([1001], self::numbers($data));
		$this->assertSame(1000, $data['cursor']);
	}

	public function testAPerCalendarRowHasNoFileNameAndNoKind(): void {
		$this->fence(2000);
		$this->eventRow(1001);
		$this->calendarRow(1002);

		$refs = $this->read(1000)['refs'];

		$this->assertSame(['c', 'k', 'o', 'p', 'q', 't', 'u'], array_keys(self::keysSorted($refs)[0]));
		$this->assertSame(['c', 'p', 'q', 't', 'u'], array_keys(self::keysSorted($refs)[1]));
	}

	public function testCIsFalseOnAPerEventRowAndComputedOnAPerCalendarRow(): void {
		// The calendar was created at 1002, after since. Its event row does not say so.
		$this->fence(2000);
		$this->eventRow(1001);
		$this->calendarRow(1002, 'personal', 'caldav', 1002);

		$refs = $this->read(1000)['refs'];

		$this->assertFalse($refs[0]['c']);
		$this->assertTrue($refs[1]['c']);
	}

	public function testASecondRowForTheSameChangeIsANormalRow(): void {
		// A read passed 1001 before it was committed, so the writer added 1003.
		$this->fence(2000);
		$this->eventRow(1001, 'one.ics', 'updated');
		$this->calendarRow(1002);
		$this->eventRow(1003, 'one.ics', 'updated');

		$refs = $this->read(1000)['refs'];

		$this->assertSame([1001, 1002, 1003], array_column($refs, 'q'));
		$this->assertSame('one.ics', $refs[0]['o']);
		$this->assertSame('one.ics', $refs[2]['o']);
	}
}
