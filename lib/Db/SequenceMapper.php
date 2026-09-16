<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Db;

use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Portable monotonic counter for instances with no distributed cache: insert
 * a row, read the autoincrement id — works identically on MySQL, PostgreSQL
 * and SQLite, with no row lock on the DAV write path.
 *
 * A configured offset is added to every id so the sequence can be lifted
 * above the ledger's high-water mark after a permanent cache loss
 * (`occ sendentsynchroniser:cn-setup --reseed`).
 */
class SequenceMapper {

	public const TABLE = 'sndntsync_seq';

	public function __construct(
		private IDBConnection $db,
		private ChangeNotificationConfig $config,
		private ITimeFactory $time,
	) {}

	public function next(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'stamp' => $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT),
		]);
		$qb->executeStatement();

		return $this->config->seqOffset() + (int)$qb->getLastInsertId();
	}

	/**
	 * Lifts the emitted sequence above $target without rewriting autoincrement state.
	 *
	 * Deliberately not atomic: two racing callers each consume their own id via
	 * next() and compute the offset delta from that id, so whichever write lands
	 * last still guarantees offset + any future id > target for its own consumed
	 * value — a lost update can only produce a smaller-than-optimal (never
	 * insufficient) lift. Do not "fix" this with a lock; it does not need one.
	 */
	public function reseedAbove(int $target): void {
		$current = $this->next();
		if ($current <= $target) {
			$this->config->setSeqOffset($this->config->seqOffset() + ($target - $current) + 1);
		}
	}

	/** Keeps the table from growing without bound. Called by the flush sweeper. */
	public function prune(int $keep = 1000): int {
		$keep = max(0, $keep);
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('id'))->from(self::TABLE);
		$result = $qb->executeQuery();
		$max = $result->fetchOne();
		$result->closeCursor();

		if (!is_numeric($max) || (int)$max <= $keep) {
			return 0;
		}

		$delete = $this->db->getQueryBuilder();
		$delete->delete(self::TABLE)
			->where($delete->expr()->lt('id', $delete->createNamedParameter((int)$max - $keep, IQueryBuilder::PARAM_INT)));

		return $delete->executeStatement();
	}
}
