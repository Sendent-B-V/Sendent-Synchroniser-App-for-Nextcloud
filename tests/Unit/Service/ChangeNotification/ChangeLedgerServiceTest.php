<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Db\DirtyCollection;
use OCA\SendentSynchroniser\Db\DirtyCollectionMapper;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\CursorService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
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

	private ChangeLedgerService $ledger;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(DirtyCollectionMapper::class);
		$this->cursor = $this->createMock(CursorService::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(1755676800);
		$this->db = $this->createMock(IDBConnection::class);
		$this->ledger = new ChangeLedgerService($this->mapper, $this->cursor, $this->time, $this->db);
	}

	private function ref(string $uri, int $token = 1, bool $structural = false): CollectionReference {
		return new CollectionReference('principals/users/alice', 'caldav', $uri, $token, $structural);
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

	public function testRecordCollapsesRepeatsOfTheSameCollection(): void {
		// One record() call can carry the same collection twice (e.g. a
		// CalendarObjectMovedEvent whose source and target calendar are the
		// same). One row, one sequence.
		$this->cursor->expects($this->once())->method('next')->willReturn(10);
		$this->mapper->expects($this->once())
			->method('record')
			->with($this->callback(fn (CollectionReference $r) => $r->syncToken === 2));

		$this->ledger->record([$this->ref('personal', 1), $this->ref('personal', 2)]);
	}

	public function testDedupKeepsTheStructuralFlagIfAnyDuplicateHadIt(): void {
		$this->cursor->method('next')->willReturn(10);
		$this->mapper->expects($this->once())
			->method('record')
			->with($this->callback(fn (CollectionReference $r) => $r->collectionChanged === true));

		$this->ledger->record([
			$this->ref('personal', 1, true),
			$this->ref('personal', 2, false),
		]);
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

	public function testConfirmRestampsARowAFlushMayHaveReadPast(): void {
		// We stamped 'personal' with 100 inside our transaction. A flush raised
		// the fence to 120 and read before we committed, so it may have set its
		// watermark past 100 without seeing our row. A fresh number puts the
		// row back above any watermark that flush could have set.
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
		// No distributed cache: fence() is 0 and there is no in-request flush
		// to have raced with; the overlap re-read covers the sweeper.
		$this->cursor->method('fence')->willReturn(0);
		$this->mapper->expects($this->never())->method('record');

		$this->assertSame(0, $this->ledger->confirm([['ref' => $this->ref('personal'), 'seq' => 5]]));
	}

	public function testHighestSeqOfAPageIsTheLastRowsSequence(): void {
		$first = new DirtyCollection();
		$first->setChangeSeq(600);
		$second = new DirtyCollection();
		$second->setChangeSeq(742);

		$this->assertSame(742, $this->ledger->highestSeqOf([$first, $second]));
		$this->assertSame(0, $this->ledger->highestSeqOf([]));
	}
}
