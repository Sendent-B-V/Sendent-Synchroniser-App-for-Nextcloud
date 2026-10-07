<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Db;

use OCA\SendentSynchroniser\Service\ChangeNotification\AfterCommit;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Portable monotonic counter for instances with no distributed cache: insert
 * a row, read the autoincrement id — works identically on MySQL, PostgreSQL
 * and SQLite, with no row lock on the DAV write path.
 *
 * A configured offset is added to every id so CursorService can lift the
 * sequence above the ledger's high-water mark, e.g. after an instance that
 * used a cache counter loses its cache for good.
 */
class SequenceMapper {

	public const TABLE = 'sndntsync_seq';

	/**
	 * The offset this process raised itself. Its durable copy is written only
	 * after commit (see reseedAbove()), and until then the lift must already
	 * apply to every number this process hands out.
	 */
	private int $knownOffset = 0;

	public function __construct(
		private IDBConnection $db,
		private ChangeNotificationConfig $config,
		private ITimeFactory $time,
		private AfterCommit $afterCommit,
	) {}

	public function next(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'stamp' => $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT),
		]);
		$qb->executeStatement();

		return $this->offset() + (int)$qb->getLastInsertId();
	}

	/**
	 * Lifts the emitted sequence above $target without rewriting autoincrement state.
	 *
	 * Runs inside the DAV backend's transaction, so the offset is stored after
	 * commit, like CursorService's floor: an app-config write there would hold
	 * its row lock until the user's write commits, and on PostgreSQL a
	 * concurrent first insert of the key would fail and drop the change. Until
	 * then other processes use the previous offset, find their numbers at or
	 * below the target too, and lift on their own.
	 *
	 * Deliberately not atomic: two racing callers each consume their own id via
	 * next() and compute the offset delta from that id, so either stored value
	 * guarantees offset + any future id > target for its own consumed value.
	 * The store only ever raises the offset, so the larger lift wins. Do not
	 * "fix" this with a lock; it does not need one.
	 */
	public function reseedAbove(int $target): void {
		$current = $this->next();
		if ($current <= $target) {
			$offset = $this->offset() + ($target - $current) + 1;
			$this->knownOffset = $offset;
			$this->afterCommit->run(fn () => $this->config->raiseSeqOffset($offset));
		}
	}

	private function offset(): int {
		return max($this->config->seqOffset(), $this->knownOffset);
	}

	/** Keeps the table from growing without bound. Called by the maintenance job. */
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
