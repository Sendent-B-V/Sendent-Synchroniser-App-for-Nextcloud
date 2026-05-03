<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.vandebroek@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<RoomFacility>
 */
class RoomFacilityMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'sndntsync_room_facilities', RoomFacility::class);
	}

	/** @return RoomFacility[] */
	public function findByRoomId(string $roomId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId)));
		return $this->findEntities($qb);
	}

	public function deleteByRoomId(string $roomId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId)));
		$qb->executeStatement();
	}

	public function replaceForRoom(string $roomId, array $facilities): void {
		$this->deleteByRoomId($roomId);
		foreach (array_unique($facilities) as $facility) {
			$entity = new RoomFacility();
			$entity->setRoomId($roomId);
			$entity->setFacility((string) $facility);
			$this->insert($entity);
		}
	}
}
