<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Db\DirtyCollection;
use OCA\SendentSynchroniser\Db\DirtyCollectionMapper;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\CursorService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ChangeLedgerServiceTest extends TestCase {

	/** @var DirtyCollectionMapper&MockObject */
	private $mapper;

	/** @var CursorService&MockObject */
	private $cursor;

	/** @var ITimeFactory&MockObject */
	private $time;

	private ChangeLedgerService $ledger;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(DirtyCollectionMapper::class);
		$this->cursor = $this->createMock(CursorService::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(1755676800);
		$this->ledger = new ChangeLedgerService($this->mapper, $this->cursor, $this->time);
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

		$highest = $this->ledger->record([$this->ref('personal'), $this->ref('work')]);

		$this->assertSame([['personal', 10, 1755676800], ['work', 11, 1755676800]], $seen);
		$this->assertSame(11, $highest);
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

		$this->assertSame(0, $this->ledger->record([]));
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
