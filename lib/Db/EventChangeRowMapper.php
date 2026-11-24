<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<EventChangeRow>
 */
class EventChangeRowMapper extends QBMapper {

	public const TABLE = 'sndntsync_evt_changes';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, EventChangeRow::class);
	}

	/**
	 * Adds one row and sets every column. Never changes a row that is
	 * already there, also not a row for the same event.
	 */
	public function append(
		string $principalUri,
		string $calendarUri,
		string $eventFileName,
		string $kind,
		int $sequenceNumber,
		int $writtenTime,
	): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'principal_uri' => $qb->createNamedParameter($principalUri),
			'calendar_uri' => $qb->createNamedParameter($calendarUri),
			'event_file_name' => $qb->createNamedParameter($eventFileName),
			'kind' => $qb->createNamedParameter($kind),
			'sequence_number' => $qb->createNamedParameter($sequenceNumber, IQueryBuilder::PARAM_INT),
			'written_time' => $qb->createNamedParameter($writtenTime, IQueryBuilder::PARAM_INT),
		]);
		$qb->executeStatement();
	}

	/**
	 * The rows with a sequence number above $since, lowest number first,
	 * at most $limit rows. The row at $since itself is not included.
	 * A limit below 1 is read as 1.
	 *
	 * @return EventChangeRow[]
	 */
	public function page(int $since, int $limit): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('id', 'principal_uri', 'calendar_uri', 'event_file_name', 'kind', 'sequence_number', 'written_time')
			->from(self::TABLE)
			->where($qb->expr()->gt('sequence_number', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
			->orderBy('sequence_number', 'ASC')
			->setMaxResults(max(1, $limit));

		return $this->findEntities($qb);
	}

	/**
	 * The highest sequence number in the table, or 0 when the table is empty.
	 */
	public function maxSeq(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('sequence_number'))->from(self::TABLE);

		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();

		return is_numeric($value) ? (int)$value : 0;
	}

	/**
	 * Removes the rows written before $time. A row written at $time stays.
	 *
	 * @return int the number of rows removed
	 */
	public function deleteWrittenBefore(int $time): int {
		$qb = $this->db->getQueryBuilder();

		$qb->delete(self::TABLE)
			->where($qb->expr()->lt('written_time', $qb->createNamedParameter($time, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * Removes all rows of one principal. Rows of other principals stay.
	 *
	 * @return int the number of rows removed
	 */
	public function deleteForPrincipal(string $principalUri): int {
		$qb = $this->db->getQueryBuilder();

		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('principal_uri', $qb->createNamedParameter($principalUri)));

		return $qb->executeStatement();
	}
}
