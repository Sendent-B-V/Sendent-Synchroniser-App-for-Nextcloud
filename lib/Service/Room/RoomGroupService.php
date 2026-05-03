<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Service\Room;

use DateTime;
use OCA\SendentSynchroniser\Db\RoomGroup;
use OCA\SendentSynchroniser\Db\RoomGroupMapper;
use OCA\SendentSynchroniser\Db\RoomMapper;
use OCA\SendentSynchroniser\Db\RoomPermissionMapper;

class RoomGroupService {
	private const ID_PATTERN = '/^[a-z0-9][a-z0-9-]{0,62}[a-z0-9]$/';

	public function __construct(
		private RoomGroupMapper $groups,
		private RoomMapper $rooms,
		private RoomPermissionMapper $permissions,
	) {}

	/** @return RoomGroup[] */
	public function listAll(): array {
		return $this->groups->findAll();
	}

	/**
	 * @return array{items: RoomGroup[], total: int, page: int, perPage: int}
	 */
	public function listPage(int $page, int $perPage, ?string $q): array {
		$perPage = max(1, min(100, $perPage));
		$page = max(1, $page);
		$needle = $q === null ? null : (trim($q) === '' ? null : trim($q));
		$total = $this->groups->countAll($needle);
		if ($total === 0) {
			return ['items' => [], 'total' => 0, 'page' => 1, 'perPage' => $perPage];
		}
		$lastPage = (int) ceil($total / $perPage);
		if ($page > $lastPage) {
			$page = $lastPage;
		}
		$items = $this->groups->findPage($page, $perPage, $needle);
		return ['items' => $items, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
	}

	public function get(string $id): RoomGroup {
		return $this->groups->findById($id);
	}

	public function create(array $data): RoomGroup {
		$id = $data['id'] ?? '';
		$name = $data['name'] ?? '';
		if (!preg_match(self::ID_PATTERN, $id)) {
			throw new RoomValidationException('Invalid group id');
		}
		if (trim($name) === '') {
			throw new RoomValidationException('Group name is required');
		}
		$g = new RoomGroup();
		$g->setId($id);
		$g->setName($name);
		$g->setDescription($data['description'] ?? null);
		$this->groups->insert($g);
		return $g;
	}

	public function update(string $id, array $patch): RoomGroup {
		$g = $this->groups->findById($id);
		if (array_key_exists('name', $patch)) {
			if (trim($patch['name']) === '') {
				throw new RoomValidationException('Group name cannot be empty');
			}
			$g->setName($patch['name']);
		}
		if (array_key_exists('description', $patch)) {
			$g->setDescription($patch['description']);
		}
		$this->groups->update($g);
		return $g;
	}

	public function delete(string $id): void {
		foreach ($this->rooms->findByGroupId($id) as $room) {
			$room->setGroupId(null);
			$room->setUpdatedAt(new DateTime());
			$this->rooms->update($room);
		}
		$this->permissions->deleteByGroupId($id);
		$this->groups->deleteById($id);
	}
}
