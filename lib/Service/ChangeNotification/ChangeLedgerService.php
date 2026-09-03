<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Db\DirtyCollection;
use OCA\SendentSynchroniser\Db\DirtyCollectionMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * The durable half of the design: every change lands here before any
 * transport is considered, so a lost or duplicated signal only ever costs a
 * reader one extra idempotent read. Cost: one cursor op plus one upsert per
 * collection reference (a DAV event yields one ref, or two for a
 * cross-calendar move).
 */
class ChangeLedgerService {

	public function __construct(
		private DirtyCollectionMapper $mapper,
		private CursorService $cursor,
		private ITimeFactory $time,
	) {}

	/**
	 * @param CollectionReference[] $refs
	 * @return int the highest sequence number written, 0 when nothing was recorded
	 */
	public function record(array $refs): int {
		$now = $this->time->getTime();
		$highest = 0;

		foreach ($this->dedupe($refs) as $ref) {
			$seq = $this->cursor->next();
			$this->mapper->record($ref, $seq, $now);
			$highest = max($highest, $seq);
		}

		return $highest;
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
