<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Integration\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\ChangeNotification\EventChange;
use OCA\SendentSynchroniser\ChangeNotification\EventChangeKind;
use OCA\SendentSynchroniser\Db\DirtyCollectionMapper;
use OCA\SendentSynchroniser\Db\EventChangeRowMapper;
use OCA\SendentSynchroniser\Db\SequenceMapper;
use OCA\SendentSynchroniser\Service\ChangeNotification\AfterCommit;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\CursorService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A counter that is lost while the per-event table holds the highest number,
 * against the real database. Only a real database has the unique index that
 * refuses a number a per-event row already holds.
 */
class EventRowCounterTest extends TestCase {

	private const PRINCIPAL = 'principals/users/cn-counter-integration';

	private IDBConnection $db;
	private EventChangeRowMapper $eventRows;
	private DirtyCollectionMapper $dirty;
	private CursorService $cursor;

	/** The number of the row that is in the per-event table, the highest of the two tables. */
	private int $highest;

	protected function setUp(): void {
		parent::setUp();
		$this->db = \OCP\Server::get(IDBConnection::class);
		$this->eventRows = \OCP\Server::get(EventChangeRowMapper::class);
		$this->dirty = \OCP\Server::get(DirtyCollectionMapper::class);

		// The settings stay in this test: nothing is written to app config.
		$offset = 0;
		$config = $this->createMock(ChangeNotificationConfig::class);
		$config->method('seqFloor')->willReturn(0);
		$config->method('seqOffset')->willReturnCallback(static function () use (&$offset): int {
			return $offset;
		});
		$config->method('raiseSeqOffset')->willReturnCallback(static function (int $raised) use (&$offset): void {
			$offset = max($offset, $raised);
		});
		$afterCommit = new AfterCommit($this->db, new NullLogger(), static function (callable $work): void {
		});
		$sequence = new SequenceMapper($this->db, $config, \OCP\Server::get(ITimeFactory::class), $afterCommit);

		// The lost counter: the database sequence gives, as its next number,
		// the number that a per-event row already holds.
		$lastId = $sequence->next();
		$this->highest = max($this->eventRows->maxSeq(), $this->dirty->maxSeq(), $lastId) + 1000;
		$offset = $this->highest - ($lastId + 1);
		$this->eventRows->append(self::PRINCIPAL, 'personal', 'one.ics', 'created', $this->highest, 1);

		// No distributed cache, so the database sequence is the counter.
		$serverConfig = $this->createMock(IConfig::class);
		$serverConfig->method('getSystemValue')->willReturn(null);
		$this->cursor = new CursorService(
			$this->createMock(ICacheFactory::class),
			$serverConfig,
			$sequence,
			$this->dirty,
			$config,
			$afterCommit,
			$this->eventRows,
		);
	}

	protected function tearDown(): void {
		while ($this->db->inTransaction()) {
			$this->db->rollBack();
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete(EventChangeRowMapper::TABLE)
			->where($qb->expr()->eq('principal_uri', $qb->createNamedParameter(self::PRINCIPAL)))
			->executeStatement();
		parent::tearDown();
	}

	/** @return array<string, int> file name => sequence number, for this test's rows */
	private function eventNumbers(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('event_file_name', 'sequence_number')
			->from(EventChangeRowMapper::TABLE)
			->where($qb->expr()->eq('principal_uri', $qb->createNamedParameter(self::PRINCIPAL)));
		$result = $qb->executeQuery();
		$numbers = [];
		while (($row = $result->fetch()) !== false) {
			$numbers[(string)$row['event_file_name']] = (int)$row['sequence_number'];
		}
		$result->closeCursor();

		return $numbers;
	}

	public function testTheNextNumberIsAboveTheHighestPerEventRow(): void {
		$this->assertGreaterThan($this->highest, $this->cursor->next());
	}

	public function testANewPerEventRowIsNotRefusedByTheUniqueIndex(): void {
		$ledger = new ChangeLedgerService(
			$this->dirty,
			$this->cursor,
			\OCP\Server::get(ITimeFactory::class),
			$this->db,
			$this->eventRows,
		);
		$calendar = new CollectionReference(self::PRINCIPAL, 'caldav', 'personal', false);

		// No per-calendar reference: only the per-event table is written.
		$ledger->record([], [new EventChange($calendar, 'two.ics', EventChangeKind::Created)]);

		$numbers = $this->eventNumbers();
		$this->assertSame(['one.ics', 'two.ics'], array_keys($numbers));
		$this->assertSame($this->highest, $numbers['one.ics']);
		$this->assertGreaterThan($this->highest, $numbers['two.ics']);
	}
}
