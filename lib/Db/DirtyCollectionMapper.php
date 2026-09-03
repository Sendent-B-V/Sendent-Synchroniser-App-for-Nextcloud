<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Db;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<DirtyCollection>
 */
class DirtyCollectionMapper extends QBMapper {

	public const TABLE = 'sndntsync_dirty';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, DirtyCollection::class);
	}

	/**
	 * Portable upsert. Update first, because after the first event per
	 * collection every subsequent one is an update — the insert path runs once
	 * per collection in the instance's lifetime.
	 *
	 * IQueryBuilder has no cross-platform ON CONFLICT for arbitrary columns
	 * (insertOrUpdate() keys on the primary key, which we do not know here), so
	 * the unique index is the arbiter and a concurrent insert is caught and
	 * retried as an update.
	 */
	public function record(CollectionReference $ref, int $seq, int $now): void {
		if ($this->touch($ref, $seq, $now) > 0) {
			return;
		}

		try {
			$this->insertRow($ref, $seq, $now);
		} catch (Exception $e) {
			// Accept both the specific and the generic constraint reason:
			// which one a duplicate key maps to differs per DB driver.
			$reason = $e->getReason();
			if ($reason !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION
				&& $reason !== Exception::REASON_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			// Another request inserted the same collection between our UPDATE
			// and our INSERT. Fold into it.
			$this->touch($ref, $seq, $now);
		}
	}

	/**
	 * @return int number of rows updated (0 when the collection is new)
	 *
	 * change_seq/structural_seq clamp with GREATEST so a slow writer can never
	 * regress the row below an already-published watermark.
	 *
	 * MySQL note: updated_at always changes, so this returns >= 1 for an
	 * existing row except a rare same-second lower-seq call, which returns 0
	 * and record() falls through to the insert/conflict path harmlessly.
	 */
	private function touch(CollectionReference $ref, int $seq, int $now): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('sync_token', $qb->createNamedParameter($ref->syncToken, IQueryBuilder::PARAM_INT))
			->set('change_seq', $qb->func()->greatest('change_seq', $qb->expr()->literal($seq, IQueryBuilder::PARAM_INT)))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT));

		if ($ref->collectionChanged) {
			$qb->set('structural_seq', $qb->func()->greatest('structural_seq', $qb->expr()->literal($seq, IQueryBuilder::PARAM_INT)));
		}

		$qb->where($qb->expr()->eq('principal_uri', $qb->createNamedParameter($ref->principalUri)))
			->andWhere($qb->expr()->eq('collection_type', $qb->createNamedParameter($ref->collectionType)))
			->andWhere($qb->expr()->eq('collection_uri', $qb->createNamedParameter($ref->collectionUri)));

		return $qb->executeStatement();
	}

	private function insertRow(CollectionReference $ref, int $seq, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'principal_uri' => $qb->createNamedParameter($ref->principalUri),
			'collection_type' => $qb->createNamedParameter($ref->collectionType),
			'collection_uri' => $qb->createNamedParameter($ref->collectionUri),
			'sync_token' => $qb->createNamedParameter($ref->syncToken, IQueryBuilder::PARAM_INT),
			'change_seq' => $qb->createNamedParameter($seq, IQueryBuilder::PARAM_INT),
			'structural_seq' => $qb->createNamedParameter($ref->collectionChanged ? $seq : 0, IQueryBuilder::PARAM_INT),
			'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		]);
		$qb->executeStatement();
	}

	/**
	 * One indexed range scan over sndntsync_dirty_seq_ix.
	 *
	 * @return DirtyCollection[]
	 */
	public function page(int $since, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->gt('change_seq', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
			->orderBy('change_seq', 'ASC')
			->setMaxResults(max(1, $limit));

		return $this->findEntities($qb);
	}

	public function maxSeq(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('change_seq'))->from(self::TABLE);

		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();

		return is_numeric($value) ? (int)$value : 0;
	}

	public function countAll(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))->from(self::TABLE);

		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();

		return is_numeric($value) ? (int)$value : 0;
	}
}
