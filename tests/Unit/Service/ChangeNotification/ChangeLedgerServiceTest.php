<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\ChangeNotification\EventChange;
use OCA\SendentSynchroniser\ChangeNotification\EventChangeKind;
use OCA\SendentSynchroniser\ChangeNotification\EventStamp;
use OCA\SendentSynchroniser\Db\DirtyCollectionMapper;
use OCA\SendentSynchroniser\Db\EventChangeRowMapper;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\CursorService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ChangeLedgerServiceTest extends TestCase {

	/** @var DirtyCollectionMapper&MockObject */
	private $mapper;

	/** @var CursorService&MockObject */
	private $cursor;

	/** @var ITimeFactory&MockObject */
	private $time;

	/** @var IDBConnection&MockObject */
	private $db;

	/** @var EventChangeRowMapper&MockObject */
	private $eventRows;

	private ChangeLedgerService $ledger;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(DirtyCollectionMapper::class);
		$this->cursor = $this->createMock(CursorService::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(1755676800);
		$this->db = $this->createMock(IDBConnection::class);
		$this->eventRows = $this->createMock(EventChangeRowMapper::class);
		$this->ledger = new ChangeLedgerService($this->mapper, $this->cursor, $this->time, $this->db, $this->eventRows);
	}

	private function change(string $calendarUri, string $fileName, EventChangeKind $kind): EventChange {
		return new EventChange($this->ref($calendarUri), $fileName, $kind);
	}

	/**
	 * Makes the two mappers and the connection write each call to one list.
	 *
	 * @param list<array<string, mixed>> $log
	 */
	private function logTheWrites(array &$log): void {
		$this->db->method('beginTransaction')->willReturnCallback(function () use (&$log): void {
			$log[] = ['step' => 'begin'];
		});
		$this->db->method('commit')->willReturnCallback(function () use (&$log): void {
			$log[] = ['step' => 'commit'];
		});
		$this->eventRows->method('append')->willReturnCallback(
			function (string $principalUri, string $calendarUri, string $eventFileName, string $kind, int $seq, int $time) use (&$log): void {
				$log[] = [
					'step' => 'event',
					'principal' => $principalUri,
					'calendar' => $calendarUri,
					'file' => $eventFileName,
					'kind' => $kind,
					'seq' => $seq,
					'time' => $time,
				];
			}
		);
		$this->mapper->method('record')->willReturnCallback(
			function (CollectionReference $ref, int $seq) use (&$log): void {
				$log[] = ['step' => 'calendar', 'calendar' => $ref->collectionUri, 'seq' => $seq];
			}
		);
	}

	/**
	 * @param list<array<string, mixed>> $log
	 * @return list<array<string, mixed>>
	 */
	private function steps(array $log, string $step): array {
		return array_values(array_filter($log, static fn (array $entry): bool => $entry['step'] === $step));
	}

	/**
	 * @param list<mixed> $stamps
	 * @return list<EventStamp>
	 */
	private function eventStamps(array $stamps): array {
		return array_values(array_filter($stamps, static fn ($stamp): bool => $stamp instanceof EventStamp));
	}

	/**
	 * @param list<mixed> $stamps
	 * @return list<array{ref: CollectionReference, seq: int}>
	 */
	private function calendarStamps(array $stamps): array {
		return array_values(array_filter($stamps, static fn ($stamp): bool => is_array($stamp)));
	}

	private function eventStamp(string $calendarUri, string $fileName, EventChangeKind $kind, int $seq): EventStamp {
		return new EventStamp($this->change($calendarUri, $fileName, $kind), $seq);
	}

	private function ref(string $uri, bool $structural = false): CollectionReference {
		return new CollectionReference('principals/users/alice', 'caldav', $uri, $structural);
	}

	public function testRecordStampsEachReferenceWithItsOwnSequence(): void {
		$this->cursor->method('next')->willReturnOnConsecutiveCalls(10, 11);

		$seen = [];
		$this->mapper->expects($this->exactly(2))
			->method('record')
			->willReturnCallback(function (CollectionReference $ref, int $seq, int $now) use (&$seen): void {
				$seen[] = [$ref->collectionUri, $seq, $now];
			});

		$stamps = $this->ledger->record([$this->ref('personal'), $this->ref('work')]);

		$this->assertSame([['personal', 10, 1755676800], ['work', 11, 1755676800]], $seen);
		$this->assertSame([10, 11], array_column($stamps, 'seq'));
		$this->assertSame('personal', $stamps[0]['ref']->collectionUri);
	}

	public function testRecordOfNothingTouchesNothing(): void {
		$this->cursor->expects($this->never())->method('next');
		$this->mapper->expects($this->never())->method('record');
		$this->db->expects($this->never())->method('beginTransaction');

		$this->assertSame([], $this->ledger->record([]));
	}

	public function testRecordRunsInItsOwnNestedTransaction(): void {
		// Inside the DAV backend's transaction Nextcloud turns this into a
		// SAVEPOINT; that is what keeps a ledger failure from poisoning the
		// user's transaction on PostgreSQL.
		$this->cursor->method('next')->willReturn(10);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$this->ledger->record([$this->ref('personal')]);
	}

	public function testAFailedRecordRollsBackOnlyItsOwnSavepointAndRethrows(): void {
		$this->cursor->method('next')->willReturn(10);
		$this->mapper->method('record')->willThrowException(new \RuntimeException('db error'));
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->never())->method('commit');
		$this->db->expects($this->once())->method('rollBack');

		$this->expectException(\RuntimeException::class);
		$this->ledger->record([$this->ref('personal')]);
	}

	public function testConfirmLeavesStampsAboveTheFenceAlone(): void {
		$this->cursor->method('fence')->willReturn(100);
		$this->cursor->expects($this->never())->method('next');
		$this->mapper->expects($this->never())->method('record');

		$restamped = $this->ledger->confirm([['ref' => $this->ref('personal'), 'seq' => 101]]);

		$this->assertSame(0, $restamped);
	}

	public function testConfirmRestampsARowAReaderMayHaveReadPast(): void {
		// We stamped 'personal' with 100 inside our transaction. A /changes
		// read raised the fence to 120 and read before we committed, so it may
		// have returned a cursor past 100 without seeing our row. A fresh
		// number puts the row back above any cursor that read could return.
		$this->cursor->method('fence')->willReturn(120);
		$this->cursor->method('next')->willReturn(121);
		$this->mapper->expects($this->once())
			->method('record')
			->with($this->callback(fn (CollectionReference $r) => $r->collectionUri === 'personal'), 121);

		$restamped = $this->ledger->confirm([
			['ref' => $this->ref('personal'), 'seq' => 100],
			['ref' => $this->ref('work'), 'seq' => 125],
		]);

		$this->assertSame(1, $restamped);
	}

	public function testConfirmWithoutAKnownFenceDoesNothing(): void {
		// No distributed cache: fence() is 0, so no reader can be shown to have
		// read past us; the Connector's overlap re-read is the protection then.
		$this->cursor->method('fence')->willReturn(0);
		$this->mapper->expects($this->never())->method('record');

		$this->assertSame(0, $this->ledger->confirm([['ref' => $this->ref('personal'), 'seq' => 5]]));
	}

	// ── Per-event rows ──────────────────────────────────────────────────

	public function testAnEventChangeAppendsOneRowWithEveryColumn(): void {
		$this->cursor->method('next')->willReturnOnConsecutiveCalls(10, 11);
		$log = [];
		$this->logTheWrites($log);

		$this->ledger->record(
			[$this->ref('personal')],
			[$this->change('personal', 'one.ics', EventChangeKind::Updated)],
		);

		$this->assertSame([[
			'step' => 'event',
			'principal' => 'principals/users/alice',
			'calendar' => 'personal',
			'file' => 'one.ics',
			'kind' => 'updated',
			'seq' => 10,
			'time' => 1755676800,
		]], $this->steps($log, 'event'));
	}

	/** @return array<string, array{EventChangeKind, string}> */
	public static function kinds(): array {
		return [
			'created' => [EventChangeKind::Created, 'created'],
			'updated' => [EventChangeKind::Updated, 'updated'],
			'deleted' => [EventChangeKind::Deleted, 'deleted'],
		];
	}

	#[DataProvider('kinds')]
	public function testTheKindIsWrittenAsItsWireValue(EventChangeKind $kind, string $wireValue): void {
		$this->cursor->method('next')->willReturnOnConsecutiveCalls(10, 11);
		$log = [];
		$this->logTheWrites($log);

		$this->ledger->record([$this->ref('personal')], [$this->change('personal', 'one.ics', $kind)]);

		$this->assertSame([$wireValue], array_column($this->steps($log, 'event'), 'kind'));
	}

	public function testThePerCalendarRowIsStillWrittenAndKeepsItsOneStamp(): void {
		$this->cursor->method('next')->willReturnOnConsecutiveCalls(10, 11);
		$log = [];
		$this->logTheWrites($log);

		$stamps = $this->calendarStamps($this->ledger->record(
			[$this->ref('personal')],
			[$this->change('personal', 'one.ics', EventChangeKind::Created)],
		));

		$calendarRows = $this->steps($log, 'calendar');
		$this->assertCount(1, $calendarRows);
		$this->assertCount(1, $stamps);
		$this->assertSame('personal', $stamps[0]['ref']->collectionUri);
		$this->assertSame($calendarRows[0]['seq'], $stamps[0]['seq']);
	}

	public function testEachPerEventRowGivesAStampWithItsChangeAndItsNumber(): void {
		$this->cursor->method('next')->willReturnOnConsecutiveCalls(10, 11);
		$log = [];
		$this->logTheWrites($log);
		$change = $this->change('personal', 'one.ics', EventChangeKind::Updated);

		$stamps = $this->eventStamps($this->ledger->record([$this->ref('personal')], [$change]));

		$this->assertCount(1, $stamps);
		$this->assertSame($change, $stamps[0]->change);
		$this->assertSame($this->steps($log, 'event')[0]['seq'], $stamps[0]->seq);
	}

	public function testTwoEventsInOneCalendarGiveTwoEventStamps(): void {
		$this->cursor->method('next')->willReturnOnConsecutiveCalls(10, 11, 12);
		$log = [];
		$this->logTheWrites($log);

		$stamps = $this->eventStamps($this->ledger->record(
			[$this->ref('personal')],
			[
				$this->change('personal', 'one.ics', EventChangeKind::Updated),
				$this->change('personal', 'two.ics', EventChangeKind::Created),
			],
		));

		$bySeq = [];
		foreach ($stamps as $stamp) {
			$bySeq[$stamp->seq] = $stamp->change->fileName;
		}
		$this->assertEquals(array_column($this->steps($log, 'event'), 'file', 'seq'), $bySeq);
		$this->assertCount(2, $bySeq);
	}

	public function testPerEventRowsWithoutAReferenceStillGiveTheirStamps(): void {
		$this->cursor->method('next')->willReturn(10);

		$stamps = $this->ledger->record([], [$this->change('personal', 'one.ics', EventChangeKind::Deleted)]);

		$this->assertCount(1, $this->eventStamps($stamps));
		$this->assertSame([], $this->calendarStamps($stamps));
	}

	public function testThePerEventRowTakesItsNumberBeforeThePerCalendarRow(): void {
		$this->cursor->method('next')->willReturnOnConsecutiveCalls(10, 11);
		$log = [];
		$this->logTheWrites($log);

		$this->ledger->record(
			[$this->ref('personal')],
			[$this->change('personal', 'one.ics', EventChangeKind::Updated)],
		);

		$eventSeq = $this->steps($log, 'event')[0]['seq'];
		$calendarSeq = $this->steps($log, 'calendar')[0]['seq'];
		$this->assertLessThan($calendarSeq, $eventSeq);
	}

	public function testAMoveWritesFourRowsEachWithItsOwnNumber(): void {
		$this->cursor->method('next')->willReturnOnConsecutiveCalls(10, 11, 12, 13);
		$log = [];
		$this->logTheWrites($log);

		$stamps = $this->ledger->record(
			[$this->ref('personal'), $this->ref('work')],
			[
				$this->change('personal', 'one.ics', EventChangeKind::Deleted),
				$this->change('work', 'one.ics', EventChangeKind::Created),
			],
		);

		$eventRows = $this->steps($log, 'event');
		$calendarRows = $this->steps($log, 'calendar');
		$this->assertEquals(
			['personal' => 'deleted', 'work' => 'created'],
			array_column($eventRows, 'kind', 'calendar'),
		);
		$this->assertCount(2, $eventRows);
		$this->assertCount(2, $calendarRows);
		$this->assertCount(2, $this->calendarStamps($stamps));
		$this->assertCount(2, $this->eventStamps($stamps));

		$numbers = array_merge(array_column($eventRows, 'seq'), array_column($calendarRows, 'seq'));
		sort($numbers);
		$this->assertSame([10, 11, 12, 13], $numbers);

		// In each calendar the per-event row has the lower number.
		$eventSeq = array_column($eventRows, 'seq', 'calendar');
		$calendarSeq = array_column($calendarRows, 'seq', 'calendar');
		$this->assertLessThan($calendarSeq['personal'], $eventSeq['personal']);
		$this->assertLessThan($calendarSeq['work'], $eventSeq['work']);
	}

	public function testBothKindsOfRowShareOneSavepoint(): void {
		$this->cursor->method('next')->willReturnOnConsecutiveCalls(10, 11);
		$this->db->expects($this->never())->method('rollBack');
		$log = [];
		$this->logTheWrites($log);

		$this->ledger->record(
			[$this->ref('personal')],
			[$this->change('personal', 'one.ics', EventChangeKind::Updated)],
		);

		$order = array_column($log, 'step');
		$this->assertCount(4, $order);
		$this->assertSame('begin', $order[0]);
		$this->assertSame('commit', $order[3]);
		$this->assertContains('event', $order);
		$this->assertContains('calendar', $order);
	}

	public function testAFailedPerEventWriteRollsBackTheSavepointAndRethrows(): void {
		$this->cursor->method('next')->willReturnOnConsecutiveCalls(10, 11);
		$this->eventRows->method('append')->willThrowException(new \RuntimeException('db error'));
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->never())->method('commit');
		$this->db->expects($this->once())->method('rollBack');

		$this->expectException(\RuntimeException::class);
		$this->ledger->record(
			[$this->ref('personal')],
			[$this->change('personal', 'one.ics', EventChangeKind::Updated)],
		);
	}

	public function testAFailedPerCalendarWriteAlsoDropsThePerEventRow(): void {
		$this->cursor->method('next')->willReturnOnConsecutiveCalls(10, 11);
		$this->mapper->method('record')->willThrowException(new \RuntimeException('db error'));
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->never())->method('commit');
		$this->db->expects($this->once())->method('rollBack');

		$this->expectException(\RuntimeException::class);
		$this->ledger->record(
			[$this->ref('personal')],
			[$this->change('personal', 'one.ics', EventChangeKind::Updated)],
		);
	}

	public function testRecordWithoutEventChangesAppendsNoPerEventRow(): void {
		$this->cursor->method('next')->willReturn(10);
		$this->eventRows->expects($this->never())->method('append');

		$this->ledger->record([$this->ref('personal')]);
	}

	public function testConfirmDoesNotTouchThePerEventRows(): void {
		$this->cursor->method('fence')->willReturn(120);
		$this->cursor->method('next')->willReturn(121);
		$this->eventRows->expects($this->never())->method($this->anything());

		$this->assertSame(1, $this->ledger->confirm([['ref' => $this->ref('personal'), 'seq' => 100]]));
	}

	// ── The second row for a per-event row a read has passed ────────────

	public function testAPerEventRowBelowTheFenceGetsASecondRowWithANewNumber(): void {
		// The row got 100 in our transaction. A read raised the fence to 120
		// before we committed, so its cursor can be past 100.
		$this->cursor->method('fence')->willReturn(120);
		$this->cursor->method('next')->willReturn(121);
		$log = [];
		$this->logTheWrites($log);

		$added = $this->ledger->confirmEventRows([
			$this->eventStamp('personal', 'one.ics', EventChangeKind::Updated, 100),
		]);

		$this->assertSame(1, $added);
		$this->assertSame([[
			'step' => 'event',
			'principal' => 'principals/users/alice',
			'calendar' => 'personal',
			'file' => 'one.ics',
			'kind' => 'updated',
			'seq' => 121,
			'time' => 1755676800,
		]], $this->steps($log, 'event'));
	}

	public function testAPerEventRowAtTheFenceGetsASecondRow(): void {
		$this->cursor->method('fence')->willReturn(100);
		$this->cursor->method('next')->willReturn(101);
		$this->eventRows->expects($this->once())->method('append')
			->with('principals/users/alice', 'personal', 'one.ics', 'created', 101, 1755676800);

		$added = $this->ledger->confirmEventRows([
			$this->eventStamp('personal', 'one.ics', EventChangeKind::Created, 100),
		]);

		$this->assertSame(1, $added);
	}

	public function testAPerEventRowAboveTheFenceGetsNoSecondRow(): void {
		$this->cursor->method('fence')->willReturn(100);
		$this->cursor->expects($this->never())->method('next');
		$this->eventRows->expects($this->never())->method('append');

		$added = $this->ledger->confirmEventRows([
			$this->eventStamp('personal', 'one.ics', EventChangeKind::Updated, 101),
		]);

		$this->assertSame(0, $added);
	}

	public function testOnlyTheRowsAtOrBelowTheFenceGetASecondRow(): void {
		$this->cursor->method('fence')->willReturn(120);
		$this->cursor->method('next')->willReturn(121);
		$log = [];
		$this->logTheWrites($log);

		$added = $this->ledger->confirmEventRows([
			$this->eventStamp('personal', 'passed.ics', EventChangeKind::Updated, 100),
			$this->eventStamp('personal', 'not-passed.ics', EventChangeKind::Updated, 125),
		]);

		$this->assertSame(1, $added);
		$this->assertSame(['passed.ics'], array_column($this->steps($log, 'event'), 'file'));
	}

	public function testTwoEventsInOneCalendarGetTwoSecondRowsEachWithItsOwnNumber(): void {
		$this->cursor->method('fence')->willReturn(120);
		$this->cursor->method('next')->willReturnOnConsecutiveCalls(121, 122);
		$log = [];
		$this->logTheWrites($log);

		$added = $this->ledger->confirmEventRows([
			$this->eventStamp('personal', 'one.ics', EventChangeKind::Updated, 100),
			$this->eventStamp('personal', 'two.ics', EventChangeKind::Deleted, 101),
		]);

		$rows = $this->steps($log, 'event');
		$this->assertSame(2, $added);
		$this->assertEquals(
			['one.ics' => 'updated', 'two.ics' => 'deleted'],
			array_column($rows, 'kind', 'file'),
		);
		$numbers = array_column($rows, 'seq');
		sort($numbers);
		$this->assertSame([121, 122], $numbers);
	}

	public function testASecondRowNeverTouchesThePerCalendarRows(): void {
		$this->cursor->method('fence')->willReturn(120);
		$this->cursor->method('next')->willReturn(121);
		$this->mapper->expects($this->never())->method('record');

		$this->ledger->confirmEventRows([
			$this->eventStamp('personal', 'one.ics', EventChangeKind::Updated, 100),
		]);
	}

	public function testWithoutAKnownFenceNoSecondRowIsAdded(): void {
		// No distributed cache: fence() is 0, as for the per-calendar rows.
		$this->cursor->method('fence')->willReturn(0);
		$this->eventRows->expects($this->never())->method('append');

		$added = $this->ledger->confirmEventRows([
			$this->eventStamp('personal', 'one.ics', EventChangeKind::Updated, 5),
		]);

		$this->assertSame(0, $added);
	}

	public function testNoEventStampsMeansNoSecondRow(): void {
		$this->cursor->expects($this->never())->method('next');
		$this->eventRows->expects($this->never())->method('append');

		$this->assertSame(0, $this->ledger->confirmEventRows([]));
	}
}
