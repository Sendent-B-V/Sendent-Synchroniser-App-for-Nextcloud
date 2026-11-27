<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Integration\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\ChangeNotification\EventChange;
use OCA\SendentSynchroniser\ChangeNotification\EventChangeKind;
use OCA\SendentSynchroniser\Db\DirtyCollectionMapper;
use OCA\SendentSynchroniser\Db\EventChangeRowMapper;
use OCA\SendentSynchroniser\Listener\DavChangeListener;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\CursorService;
use OCA\SendentSynchroniser\Service\ChangeNotification\DavEventReferenceExtractor;
use OCA\SendentSynchroniser\Service\ChangeNotification\PostCommitQueue;
use OCA\SendentSynchroniser\Service\ChangeNotification\SyncScope;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A per-event row with a number that is already in the table, against the
 * real database. Only a real database shows that the failed insert stays
 * inside the savepoint: on PostgreSQL a failed statement stops the whole
 * transaction unless the code rolls back to a savepoint.
 */
class EventChangeDuplicateNumberTest extends TestCase {

	private const PRINCIPAL = 'principals/users/cn-duplicate-integration';

	private IDBConnection $db;
	private EventChangeRowMapper $eventRows;
	private DirtyCollectionMapper $dirty;

	/** A number above every row in the two tables. */
	private int $free;

	protected function setUp(): void {
		parent::setUp();
		$this->db = \OCP\Server::get(IDBConnection::class);
		$this->eventRows = \OCP\Server::get(EventChangeRowMapper::class);
		$this->dirty = \OCP\Server::get(DirtyCollectionMapper::class);
		$this->free = max($this->eventRows->maxSeq(), $this->dirty->maxSeq()) + 1000;
	}

	protected function tearDown(): void {
		while ($this->db->inTransaction()) {
			$this->db->rollBack();
		}
		foreach ([EventChangeRowMapper::TABLE, DirtyCollectionMapper::TABLE] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->eq('principal_uri', $qb->createNamedParameter(self::PRINCIPAL)))
				->executeStatement();
		}
		parent::tearDown();
	}

	/** The real ledger and mappers, with a cursor that gives the given numbers. */
	private function ledgerWithNumbers(int ...$numbers): ChangeLedgerService {
		$cursor = $this->createMock(CursorService::class);
		$cursor->method('next')->willReturnOnConsecutiveCalls(...$numbers);

		return new ChangeLedgerService(
			$this->dirty,
			$cursor,
			\OCP\Server::get(ITimeFactory::class),
			$this->db,
			$this->eventRows,
		);
	}

	private function calendar(): CollectionReference {
		return new CollectionReference(self::PRINCIPAL, 'caldav', 'personal', false);
	}

	/** @return list<string> the file names of this test's per-event rows */
	private function eventFileNames(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('event_file_name')
			->from(EventChangeRowMapper::TABLE)
			->where($qb->expr()->eq('principal_uri', $qb->createNamedParameter(self::PRINCIPAL)))
			->orderBy('sequence_number', 'ASC');
		$result = $qb->executeQuery();
		$names = [];
		while (($row = $result->fetch()) !== false) {
			$names[] = (string)$row['event_file_name'];
		}
		$result->closeCursor();

		return $names;
	}

	private function countDirtyRows(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from(DirtyCollectionMapper::TABLE)
			->where($qb->expr()->eq('principal_uri', $qb->createNamedParameter(self::PRINCIPAL)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	public function testADuplicateNumberRollsBackOnlyTheSavepoint(): void {
		// The outer transaction stands for the DAV backend's transaction, and
		// the first row for the user's own write.
		$this->db->beginTransaction();
		$this->eventRows->append(self::PRINCIPAL, 'personal', 'before.ics', 'created', $this->free, 1);

		// The first change gets a free number, the second the number in use.
		$ledger = $this->ledgerWithNumbers($this->free + 1, $this->free, $this->free + 2);
		$failure = null;
		try {
			$ledger->record([$this->calendar()], [
				new EventChange($this->calendar(), 'one.ics', EventChangeKind::Created),
				new EventChange($this->calendar(), 'two.ics', EventChangeKind::Created),
			]);
		} catch (\Throwable $e) {
			$failure = $e;
		}

		$this->assertInstanceOf(\OCP\DB\Exception::class, $failure);
		$this->assertSame(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION, $failure->getReason());

		// The outer transaction still takes a write and commits.
		$this->eventRows->append(self::PRINCIPAL, 'personal', 'after.ics', 'created', $this->free + 3, 1);
		$this->db->commit();

		$this->assertSame(['before.ics', 'after.ics'], $this->eventFileNames());
		$this->assertSame(0, $this->countDirtyRows());
	}

	public function testADuplicateNumberNeverReachesTheUsersWrite(): void {
		if (!class_exists(\OCP\Calendar\Events\CalendarObjectCreatedEvent::class)) {
			$this->markTestSkipped('Change notifications require Nextcloud 32+');
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');
		$queue = $this->createMock(PostCommitQueue::class);
		$queue->expects($this->never())->method('add');
		$scope = $this->createMock(SyncScope::class);
		$scope->method('includes')->willReturn(true);
		$listener = new DavChangeListener(
			$this->ledgerWithNumbers($this->free, $this->free + 1),
			$queue,
			new DavEventReferenceExtractor(),
			$scope,
			$logger,
			$this->createMock(\OCA\SendentSynchroniser\Service\ChangeNotification\RestoredEventUidReader::class),
		);

		$this->db->beginTransaction();
		$this->eventRows->append(self::PRINCIPAL, 'personal', 'before.ics', 'created', $this->free, 1);

		$listener->handle(new \OCP\Calendar\Events\CalendarObjectCreatedEvent(
			42,
			['id' => 42, 'uri' => 'personal', 'principaluri' => self::PRINCIPAL],
			[],
			['uri' => 'one.ics'],
		));

		$this->eventRows->append(self::PRINCIPAL, 'personal', 'after.ics', 'created', $this->free + 2, 1);
		$this->db->commit();

		$this->assertSame(['before.ics', 'after.ics'], $this->eventFileNames());
		$this->assertSame(0, $this->countDirtyRows());
	}
}
