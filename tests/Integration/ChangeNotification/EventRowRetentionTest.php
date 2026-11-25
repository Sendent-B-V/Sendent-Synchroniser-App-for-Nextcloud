<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Integration\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Cron\ChangeNotificationMaintenance;
use OCA\SendentSynchroniser\Db\DirtyCollectionMapper;
use OCA\SendentSynchroniser\Db\EventChangeRowMapper;
use OCA\SendentSynchroniser\Db\SequenceMapper;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushTransport;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The maintenance job against the real database: which per-event rows it
 * removes at the 3-day limit, and that it leaves the per-calendar table alone.
 */
class EventRowRetentionTest extends TestCase {

	private const PRINCIPAL = 'principals/users/cn-retention-integration';

	private const THREE_DAYS = 259200;

	// The limit is at 1000 seconds. Rows of other tests and of a real user
	// have a time of today, far above it, so the job does not remove them.
	private const LIMIT = 1000;
	private const NOW = self::LIMIT + self::THREE_DAYS;

	private IDBConnection $db;
	private EventChangeRowMapper $eventRows;
	private DirtyCollectionMapper $dirty;
	private ChangeNotificationMaintenance $job;

	protected function setUp(): void {
		parent::setUp();
		$this->db = \OCP\Server::get(IDBConnection::class);
		$this->eventRows = \OCP\Server::get(EventChangeRowMapper::class);
		$this->dirty = \OCP\Server::get(DirtyCollectionMapper::class);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		// The hint and the prune are not under test. Only the mapper is real.
		$this->job = new ChangeNotificationMaintenance(
			$time,
			$this->createMock(NotifyPushTransport::class),
			$this->createMock(SequenceMapper::class),
			$this->eventRows,
			new NullLogger(),
		);
	}

	protected function tearDown(): void {
		foreach ([EventChangeRowMapper::TABLE, DirtyCollectionMapper::TABLE] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->eq('principal_uri', $qb->createNamedParameter(self::PRINCIPAL)))
				->executeStatement();
		}
		parent::tearDown();
	}

	/** @return list<string> the file names of this test's per-event rows, in name order */
	private function keptFileNames(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('event_file_name')
			->from(EventChangeRowMapper::TABLE)
			->where($qb->expr()->eq('principal_uri', $qb->createNamedParameter(self::PRINCIPAL)))
			->orderBy('event_file_name', 'ASC');
		$result = $qb->executeQuery();
		$names = [];
		while (($row = $result->fetch()) !== false) {
			$names[] = (string)$row['event_file_name'];
		}
		$result->closeCursor();

		return $names;
	}

	private function perCalendarRows(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from(DirtyCollectionMapper::TABLE)
			->where($qb->expr()->eq('principal_uri', $qb->createNamedParameter(self::PRINCIPAL)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	public function testOnlyTheRowsWrittenMoreThanThreeDaysAgoAreRemoved(): void {
		// Numbers above each row in the two tables: the unique index refuses a used number.
		$number = max($this->eventRows->maxSeq(), $this->dirty->maxSeq()) + 1000;

		$this->eventRows->append(self::PRINCIPAL, 'personal', 'older.ics', 'updated', $number + 1, self::LIMIT - 1);
		$this->eventRows->append(self::PRINCIPAL, 'personal', 'at-the-limit.ics', 'updated', $number + 2, self::LIMIT);
		$this->eventRows->append(self::PRINCIPAL, 'personal', 'newer.ics', 'updated', $number + 3, self::LIMIT + 1);
		// A per-calendar row that is older than each of the three.
		$this->dirty->record(new CollectionReference(self::PRINCIPAL, 'caldav', 'personal', false), $number + 4, 1);

		$this->job->start($this->createMock(IJobList::class));

		// The row that is exactly 3 days old stays: deleteWrittenBefore()
		// removes the rows written before the limit, not the row at the limit.
		$this->assertSame(['at-the-limit.ics', 'newer.ics'], $this->keptFileNames());
		$this->assertSame(1, $this->perCalendarRows());
	}
}
