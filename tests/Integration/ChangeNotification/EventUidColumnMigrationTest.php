<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Integration\ChangeNotification;

use OC\DB\Connection;
use OC\DB\SchemaWrapper;
use OCA\SendentSynchroniser\Db\DirtyCollectionMapper;
use OCA\SendentSynchroniser\Db\EventChangeRow;
use OCA\SendentSynchroniser\Db\EventChangeRowMapper;
use OCA\SendentSynchroniser\Migration\Version000005Date20261126;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The UID column against the real database: the migration adds it to a
 * table that has rows, a row from before reads back without a UID, and a
 * new row reads back with its UID.
 *
 * The test removes the column first, so it starts from the table as it was
 * before the migration. It puts the column back at the end.
 */
class EventUidColumnMigrationTest extends TestCase {

	private const PRINCIPAL = 'principals/users/cn-uid-column-integration';
	private const COLUMN = 'event_uid';

	private IDBConnection $db;
	private Connection $connection;
	private EventChangeRowMapper $eventRows;

	protected function setUp(): void {
		parent::setUp();
		$this->db = \OCP\Server::get(IDBConnection::class);
		$this->connection = \OCP\Server::get(Connection::class);
		$this->eventRows = \OCP\Server::get(EventChangeRowMapper::class);
	}

	protected function tearDown(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(EventChangeRowMapper::TABLE)
			->where($qb->expr()->eq('principal_uri', $qb->createNamedParameter(self::PRINCIPAL)))
			->executeStatement();
		// The tests that run after this one need the column.
		$this->runTheMigration();
		parent::tearDown();
	}

	private function hasTheColumn(): bool {
		return (new SchemaWrapper($this->connection))->getTable(EventChangeRowMapper::TABLE)->hasColumn(self::COLUMN);
	}

	private function removeTheColumn(): void {
		$schema = new SchemaWrapper($this->connection);
		$table = $schema->getTable(EventChangeRowMapper::TABLE);
		if ($table->hasColumn(self::COLUMN)) {
			$table->dropColumn(self::COLUMN);
			$this->connection->migrateToSchema($schema->getWrappedSchema());
		}
	}

	private function runTheMigration(): void {
		$schema = new SchemaWrapper($this->connection);
		$changed = (new Version000005Date20261126())->changeSchema(
			$this->createStub(IOutput::class),
			fn () => $schema,
			[],
		);
		if ($changed !== null) {
			$this->connection->migrateToSchema($changed->getWrappedSchema());
		}
	}

	/** Writes a row as the app did before the column was there. */
	private function writeARowWithoutTheColumn(string $fileName, int $number): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(EventChangeRowMapper::TABLE)->values([
			'principal_uri' => $qb->createNamedParameter(self::PRINCIPAL),
			'calendar_uri' => $qb->createNamedParameter('personal'),
			'event_file_name' => $qb->createNamedParameter($fileName),
			'kind' => $qb->createNamedParameter('updated'),
			'sequence_number' => $qb->createNamedParameter($number, IQueryBuilder::PARAM_INT),
			'written_time' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
		]);
		$qb->executeStatement();
	}

	/** @return array<string, string|null> file name => UID, for the rows of this test */
	private function uidsByFileName(int $since): array {
		$uids = [];
		foreach ($this->eventRows->page($since, 100) as $row) {
			/** @var EventChangeRow $row */
			if ($row->getPrincipalUri() === self::PRINCIPAL) {
				$uids[$row->getEventFileName()] = $row->getEventUid();
			}
		}

		return $uids;
	}

	public function testTheMigrationAddsTheColumnToATableWithRows(): void {
		// Above each row in the two tables: the unique index refuses a used number.
		$base = max($this->eventRows->maxSeq(), \OCP\Server::get(DirtyCollectionMapper::class)->maxSeq()) + 1000;

		$this->removeTheColumn();
		$this->assertFalse($this->hasTheColumn(), 'the test must start without the column');
		$this->writeARowWithoutTheColumn('old.ics', $base + 1);

		$this->runTheMigration();

		$this->assertTrue($this->hasTheColumn(), 'the migration must add the column');

		$this->eventRows->append(self::PRINCIPAL, 'personal', 'new.ics', 'created', $base + 2, 1, 'uid-of-new');
		$this->eventRows->append(self::PRINCIPAL, 'personal', 'no-uid.ics', 'created', $base + 3, 1);

		$this->assertSame(
			['old.ics' => null, 'new.ics' => 'uid-of-new', 'no-uid.ics' => null],
			$this->uidsByFileName($base),
		);
	}
}
