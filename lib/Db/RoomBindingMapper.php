<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<RoomBinding>
 */
class RoomBindingMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'sndntsync_room_bindings', RoomBinding::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findByRoomId(string $roomId): RoomBinding {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId)));
		return $this->findEntity($qb);
	}

	public function findByRoomIdOrNull(string $roomId): ?RoomBinding {
		try {
			return $this->findByRoomId($roomId);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return RoomBinding[] */
	public function findAll(?string $kind = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());
		if ($kind !== null) {
			$qb->where($qb->expr()->eq('kind', $qb->createNamedParameter($kind)));
		}
		return $this->findEntities($qb);
	}

	public function deleteByRoomId(string $roomId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId)));
		$qb->executeStatement();
	}
}
