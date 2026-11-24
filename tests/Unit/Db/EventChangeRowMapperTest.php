<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Db;

use OCA\SendentSynchroniser\Db\EventChangeRow;
use OCA\SendentSynchroniser\Db\EventChangeRowMapper;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/EventChangeTableFake.php';

class EventChangeRowMapperTest extends TestCase {

	private const ALICE = 'principals/users/alice';
	// A name that starts with the other name, to catch a match by prefix.
	private const ALICE2 = 'principals/users/alice2';

	private EventChangeTableFake $table;

	private EventChangeRowMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->table = new EventChangeTableFake(fn (string $class): object => $this->createStub($class));
		$this->mapper = new EventChangeRowMapper($this->table->connection());
	}

	private function seed(int $sequenceNumber, int $writtenTime = 1795000000, string $principalUri = self::ALICE): void {
		$this->table->seed($principalUri, 'personal', 'one.ics', 'updated', $sequenceNumber, $writtenTime);
	}

	/**
	 * @param EventChangeRow[] $rows
	 * @return list<int>
	 */
	private function numbers(array $rows): array {
		return array_values(array_map(fn (EventChangeRow $row): int => $row->getSequenceNumber(), $rows));
	}

	public function testAppendSetsEveryColumn(): void {
		// The fake refuses an insert that leaves a column out, as the table does.
		$this->mapper->append(self::ALICE, 'personal', 'one.ics', 'updated', 1051, 1795000000);

		$this->assertCount(1, $this->table->rows);
		$row = $this->table->rows[0];
		$this->assertSame(self::ALICE, $row['principal_uri']);
		$this->assertSame('personal', $row['calendar_uri']);
		$this->assertSame('one.ics', $row['event_file_name']);
		$this->assertSame('updated', $row['kind']);
		$this->assertSame(1051, $row['sequence_number']);
		$this->assertSame(1795000000, $row['written_time']);
	}

	public function testAppendForTheSameEventAddsASecondRow(): void {
		$this->mapper->append(self::ALICE, 'personal', 'one.ics', 'created', 1051, 1795000000);
		$this->mapper->append(self::ALICE, 'personal', 'one.ics', 'updated', 1053, 1795000060);

		$this->assertSame([1051, 1053], $this->table->sequenceNumbers());
		$this->assertSame(['created', 'updated'], array_column($this->table->rows, 'kind'));
	}

	public function testPageGivesTheRowsAboveSinceAndNotTheRowAtSince(): void {
		$this->seed(10);
		$this->seed(20);
		$this->seed(30);

		$this->assertSame([30], $this->numbers($this->mapper->page(20, 100)));
	}

	public function testPageIsEmptyWhenNoRowIsAboveSince(): void {
		$this->seed(10);

		$this->assertSame([], $this->mapper->page(10, 100));
	}

	public function testPageGivesTheLowestNumberFirst(): void {
		// Written out of order: a row can get a higher number after it is written.
		$this->seed(30);
		$this->seed(10);
		$this->seed(20);

		$this->assertSame([10, 20, 30], $this->numbers($this->mapper->page(0, 100)));
	}

	public function testPageStopsAtTheLimitAndKeepsTheLowestRows(): void {
		$this->seed(40);
		$this->seed(30);
		$this->seed(10);
		$this->seed(20);

		$this->assertSame([10, 20], $this->numbers($this->mapper->page(0, 2)));
	}

	public function testPageReadsALimitBelowOneAsOne(): void {
		$this->seed(20);
		$this->seed(10);

		$this->assertSame([10], $this->numbers($this->mapper->page(0, 0)));
		$this->assertSame([10], $this->numbers($this->mapper->page(0, -5)));
	}

	public function testPageGivesFullRowsWithIntegerFields(): void {
		$this->table->seed(self::ALICE, 'personal', 'one.ics', 'deleted', 1051, 1795000000);

		$rows = $this->mapper->page(1000, 100);

		$this->assertCount(1, $rows);
		$this->assertInstanceOf(EventChangeRow::class, $rows[0]);
		$this->assertSame(self::ALICE, $rows[0]->getPrincipalUri());
		$this->assertSame('personal', $rows[0]->getCalendarUri());
		$this->assertSame('one.ics', $rows[0]->getEventFileName());
		$this->assertSame('deleted', $rows[0]->getKind());
		$this->assertSame(1051, $rows[0]->getSequenceNumber());
		$this->assertSame(1795000000, $rows[0]->getWrittenTime());
	}

	public function testMaxSeqIsZeroOnAnEmptyTable(): void {
		$this->assertSame(0, $this->mapper->maxSeq());
	}

	public function testMaxSeqIsTheHighestNumberAndNotTheLastWritten(): void {
		$this->seed(20);
		$this->seed(30);
		$this->seed(10);

		$this->assertSame(30, $this->mapper->maxSeq());
	}

	public function testDeleteWrittenBeforeLeavesTheRowAtTheTimeAndTheNewerRows(): void {
		$this->seed(1, 999);
		$this->seed(2, 1000);
		$this->seed(3, 1001);
		$this->seed(4, 500);

		$removed = $this->mapper->deleteWrittenBefore(1000);

		$this->assertSame(2, $removed);
		$this->assertSame([2, 3], $this->table->sequenceNumbers());
	}

	public function testDeleteWrittenBeforeRemovesNothingWhenAllRowsAreNewer(): void {
		$this->seed(1, 1000);

		$this->assertSame(0, $this->mapper->deleteWrittenBefore(1000));
		$this->assertSame([1], $this->table->sequenceNumbers());
	}

	public function testDeleteForPrincipalLeavesTheRowsOfOtherUsers(): void {
		$this->seed(1, 1795000000, self::ALICE);
		$this->seed(2, 1795000000, self::ALICE2);
		$this->seed(3, 1795000000, self::ALICE);
		$this->seed(4, 1795000000, 'principals/users/bob');

		$removed = $this->mapper->deleteForPrincipal(self::ALICE);

		$this->assertSame(2, $removed);
		$this->assertSame([2, 4], $this->table->sequenceNumbers());
	}

	public function testDeleteForPrincipalRemovesNothingForAnUnknownUser(): void {
		$this->seed(1);

		$this->assertSame(0, $this->mapper->deleteForPrincipal('principals/users/carol'));
		$this->assertSame([1], $this->table->sequenceNumbers());
	}
}
