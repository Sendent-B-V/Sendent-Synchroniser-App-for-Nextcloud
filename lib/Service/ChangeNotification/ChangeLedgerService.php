<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Db\DirtyCollection;
use OCA\SendentSynchroniser\Db\DirtyCollectionMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * The durable half of the design: every change lands here before any
 * transport is considered, so a lost or duplicated signal only ever costs a
 * reader one extra idempotent read. Cost: one cursor op plus one upsert per
 * collection reference (a DAV event yields one ref, or two for a
 * cross-calendar move).
 *
 * record() runs inside the DAV backend's open transaction, so the ledger row
 * commits or rolls back together with the user's write. confirm() runs after
 * that commit; see there.
 *
 * @psalm-type Stamp = array{ref: CollectionReference, seq: int}
 */
class ChangeLedgerService {

	public function __construct(
		private DirtyCollectionMapper $mapper,
		private CursorService $cursor,
		private ITimeFactory $time,
		private IDBConnection $db,
	) {}

	/**
	 * Wrapped in a nested transaction: inside the DAV backend's transaction
	 * Nextcloud makes this a SAVEPOINT, so any ledger failure rolls back to it
	 * and leaves the user's transaction usable. Without the savepoint, one
	 * failed statement on PostgreSQL aborts the whole transaction, and the
	 * backend's commit then rolls back the user's calendar or contact write.
	 *
	 * @param CollectionReference[] $refs
	 * @return list<Stamp> one per distinct collection, with the sequence it got
	 */
	public function record(array $refs): array {
		$refs = $this->dedupe($refs);
		if ($refs === []) {
			return [];
		}

		$now = $this->time->getTime();
		$stamps = [];

		$this->db->beginTransaction();
		try {
			foreach ($refs as $ref) {
				$seq = $this->cursor->next();
				$this->mapper->record($ref, $seq, $now);
				$stamps[] = ['ref' => $ref, 'seq' => $seq];
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $stamps;
	}

	/**
	 * Must run after the transaction that recorded $stamps has committed.
	 *
	 * A flush raises the fence to the counter's value before it reads the
	 * ledger, then never advances its watermark past the fence. A row stamped
	 * at or below the fence that was still uncommitted when the flush read
	 * would therefore end up below the watermark, invisible to every later
	 * flush. Its writer can tell: after its own commit it sees the fence at or
	 * above its stamp. It then stamps the row again with a fresh number, which
	 * is above the fence and so above any watermark that flush could set.
	 *
	 * A re-stamp can be unnecessary (the flush did see the row): that costs a
	 * duplicate ref, which readers absorb by design.
	 *
	 * @param list<Stamp> $stamps
	 * @return int how many collections were re-stamped
	 */
	public function confirm(array $stamps): int {
		if ($stamps === []) {
			return 0;
		}

		$fence = $this->cursor->fence();
		$overtaken = [];
		foreach ($stamps as $stamp) {
			if ($stamp['seq'] <= $fence) {
				$overtaken[] = $stamp['ref'];
			}
		}

		if ($overtaken === []) {
			return 0;
		}

		return count($this->record($overtaken));
	}

	/** @return DirtyCollection[] */
	public function rows(int $since, int $limit): array {
		return $this->mapper->page($since, $limit);
	}

	/** @param DirtyCollection[] $rows */
	public function highestSeqOf(array $rows): int {
		$highest = 0;
		foreach ($rows as $row) {
			$highest = max($highest, (int)$row->getChangeSeq());
		}

		return $highest;
	}

	public function highWaterMark(): int {
		return $this->mapper->maxSeq();
	}

	public function countCollections(): int {
		return $this->mapper->countAll();
	}

	/**
	 * Collapses references to the same collection, keeping the last sync token and OR-ing the structural flag.
	 * @param CollectionReference[] $refs
	 * @return CollectionReference[]
	 */
	private function dedupe(array $refs): array {
		/** @var array<string, CollectionReference> $byKey */
		$byKey = [];

		foreach ($refs as $ref) {
			$key = $ref->key();
			$structural = $ref->collectionChanged
				|| (isset($byKey[$key]) && $byKey[$key]->collectionChanged);
			$byKey[$key] = $ref->withCollectionChanged($structural);
		}

		return array_values($byKey);
	}
}
