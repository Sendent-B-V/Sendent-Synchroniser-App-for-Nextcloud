<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<RoomGroup>
 */
class RoomGroupMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'sndntsync_room_groups', RoomGroup::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findById(string $id): RoomGroup {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		return $this->findEntity($qb);
	}

	/** @return RoomGroup[] */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('name', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return RoomGroup[] */
	public function findPage(int $page, int $perPage, ?string $q): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('name', 'ASC')
			->setMaxResults($perPage)
			->setFirstResult(($page - 1) * $perPage);
		$this->applySearch($qb, $q);
		return $this->findEntities($qb);
	}

	public function countAll(?string $q): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName());
		$this->applySearch($qb, $q);
		$result = $qb->executeQuery();
		$count = (int) $result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	private function applySearch(\OCP\DB\QueryBuilder\IQueryBuilder $qb, ?string $q): void {
		$needle = $q === null ? '' : trim($q);
		if ($needle === '') {
			return;
		}
		$param = $qb->createNamedParameter('%' . strtolower($needle) . '%');
		$qb->where($qb->expr()->orX(
			$qb->expr()->like($qb->func()->lower('name'), $param),
			$qb->expr()->like($qb->func()->lower('id'), $param),
			$qb->expr()->like($qb->func()->lower($qb->func()->coalesce('description', $qb->createNamedParameter(''))), $param),
		));
	}

	public function deleteById(string $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		$qb->executeStatement();
	}
}
