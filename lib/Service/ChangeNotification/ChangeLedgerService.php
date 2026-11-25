<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\ChangeNotification\EventChange;
use OCA\SendentSynchroniser\ChangeNotification\EventStamp;
use OCA\SendentSynchroniser\Db\DirtyCollection;
use OCA\SendentSynchroniser\Db\DirtyCollectionMapper;
use OCA\SendentSynchroniser\Db\EventChangeRow;
use OCA\SendentSynchroniser\Db\EventChangeRowMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * The change feed's write side: every change lands here, and the Connector
 * reads it from its own cursor, so a lost or duplicated hint only ever costs
 * one extra idempotent check. Cost: one cursor op plus one upsert per
 * collection reference (a DAV event yields one ref, or two for a
 * cross-calendar move). A calendar event change also appends one per-event
 * row for each change.
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
		private EventChangeRowMapper $eventRows,
	) {}

	/**
	 * Wrapped in a nested transaction: inside the DAV backend's transaction
	 * Nextcloud makes this a SAVEPOINT, so any ledger failure rolls back to it
	 * and leaves the user's transaction usable. Without the savepoint, one
	 * failed statement on PostgreSQL aborts the whole transaction, and the
	 * backend's commit then rolls back the user's calendar or contact write.
	 *
	 * The per-event rows:
	 * - One row for each change, in the same savepoint as the per-calendar
	 *   rows. All rows stay or none stays.
	 * - Each row has its own sequence number. The row of a change gets a
	 *   lower number than the per-calendar row of the same calendar.
	 * - Each row gives one EventStamp with its number. A row that a read has
	 *   passed gets a second row after the commit; see confirmEventRows().
	 *
	 * @param CollectionReference[] $refs
	 * @param list<EventChange> $eventChanges
	 * @return list<Stamp|EventStamp> one Stamp per reference and one
	 *                                EventStamp per per-event row
	 */
	public function record(array $refs, array $eventChanges = []): array {
		if ($refs === [] && $eventChanges === []) {
			return [];
		}

		$now = $this->time->getTime();
		$stamps = [];

		$this->db->beginTransaction();
		try {
			// The per-event rows go first, so they get the lower numbers.
			foreach ($eventChanges as $change) {
				$seq = $this->cursor->next();
				$this->eventRows->append(
					$change->calendar->principalUri,
					$change->calendar->collectionUri,
					$change->fileName,
					$change->kind->value,
					$seq,
					$now,
				);
				$stamps[] = new EventStamp($change, $seq);
			}
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
	 * A /changes read raises the fence to CursorService::current() before it
	 * reads the ledger, then never returns a cursor past the fence. A row stamped at
	 * or below the fence that was still uncommitted during that read would
	 * therefore end up below the Connector's cursor, invisible to every later
	 * read. Its writer can tell: after its own commit it sees the fence at or
	 * above its stamp. It then stamps the row again with a fresh number, which
	 * is above the fence and so above any cursor that read could return.
	 *
	 * A re-stamp can be unnecessary (the read did see the row): that costs a
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

	/**
	 * Must run after the commit, as confirm(). A per-event row at or below
	 * the fence gets a second row for the same change, with a new number.
	 * No row is changed. A row above the fence gets nothing.
	 *
	 * @param list<EventStamp> $eventStamps
	 * @return int how many second rows were added
	 */
	public function confirmEventRows(array $eventStamps): int {
		if ($eventStamps === []) {
			return 0;
		}

		$fence = $this->cursor->fence();
		$overtaken = [];
		foreach ($eventStamps as $stamp) {
			if ($stamp->seq <= $fence) {
				$overtaken[] = $stamp->change;
			}
		}

		if ($overtaken === []) {
			return 0;
		}

		return count($this->record([], $overtaken));
	}

	/**
	 * The rows of the two tables above $since in one list, lowest number
	 * first, at most $limit rows. A row that does not fit is left out.
	 *
	 * @return list<DirtyCollection|EventChangeRow>
	 */
	public function rows(int $since, int $limit): array {
		$rows = $this->mapper->page($since, $limit);
		$eventRows = $this->eventRows->page($since, $limit);

		$rows = array_merge($rows, $eventRows);
		usort($rows, function ($a, $b) {
			return $this->numberOf($a) <=> $this->numberOf($b);
		});

		return array_slice($rows, 0, $limit);
	}

	/** The sequence number of a row of either table. */
	private function numberOf(DirtyCollection|EventChangeRow $row): int {
		if ($row instanceof EventChangeRow) {
			return $row->getSequenceNumber();
		}
		return $row->getChangeSeq();
	}

	public function countCollections(): int {
		return $this->mapper->countAll();
	}
}
