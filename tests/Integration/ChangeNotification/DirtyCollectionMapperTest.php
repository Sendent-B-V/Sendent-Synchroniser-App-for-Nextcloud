<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Integration\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Db\DirtyCollectionMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The ledger upsert against the real database. GREATEST() follows each
 * engine's own comparison rules, which only a real database shows: compared
 * with a quoted number, MySQL compares as strings ('999' > '1000') and SQLite
 * ranks any text above any integer.
 */
class DirtyCollectionMapperTest extends TestCase {

	private const PRINCIPAL = 'principals/users/cn-mapper-integration';

	private IDBConnection $db;
	private DirtyCollectionMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->db = \OCP\Server::get(IDBConnection::class);
		$this->mapper = \OCP\Server::get(DirtyCollectionMapper::class);
	}

	protected function tearDown(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(DirtyCollectionMapper::TABLE)
			->where($qb->expr()->eq('principal_uri', $qb->createNamedParameter(self::PRINCIPAL)))
			->executeStatement();
		parent::tearDown();
	}

	/** @return array<string, array{int, int, int}> */
	public static function sequences(): array {
		return [
			'a higher number with more digits' => [999, 1000, 1000],
			'a higher number that sorts lower as text' => [2000, 10000, 10000],
			'a lower number never moves the row back' => [5000, 1000, 5000],
		];
	}

	#[DataProvider('sequences')]
	public function testChangeSeqOnlyEverRises(int $first, int $second, int $expected): void {
		$ref = $this->ref(false);

		$this->mapper->record($ref, $first, 1);
		$this->mapper->record($ref, $second, 2);

		$this->assertSame($expected, $this->row()['change_seq']);
	}

	#[DataProvider('sequences')]
	public function testStructuralSeqOnlyEverRises(int $first, int $second, int $expected): void {
		$ref = $this->ref(true);

		$this->mapper->record($ref, $first, 1);
		$this->mapper->record($ref, $second, 2);

		$this->assertSame($expected, $this->row()['structural_seq']);
	}

	private function ref(bool $collectionChanged): CollectionReference {
		return new CollectionReference(self::PRINCIPAL, 'caldav', 'personal', $collectionChanged);
	}

	/** @return array{change_seq: int, structural_seq: int} */
	private function row(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('change_seq', 'structural_seq')
			->from(DirtyCollectionMapper::TABLE)
			->where($qb->expr()->eq('principal_uri', $qb->createNamedParameter(self::PRINCIPAL)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return ['change_seq' => (int)$row['change_seq'], 'structural_seq' => (int)$row['structural_seq']];
	}
}
