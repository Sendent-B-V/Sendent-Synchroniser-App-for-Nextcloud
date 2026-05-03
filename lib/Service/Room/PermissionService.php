<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.vandebroek@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Service\Room;

use OCA\SendentSynchroniser\Db\RoomPermission;
use OCA\SendentSynchroniser\Db\RoomPermissionMapper;

class PermissionService {
	private const VALID_ROLES = [RoomPermission::ROLE_VIEWER, RoomPermission::ROLE_BOOKER, RoomPermission::ROLE_MANAGER];
	private const VALID_PRINCIPAL_TYPES = [RoomPermission::PRINCIPAL_USER, RoomPermission::PRINCIPAL_GROUP];

	public function __construct(private RoomPermissionMapper $mapper) {}

	/** @return RoomPermission[] */
	public function listForRoom(string $roomId): array {
		return $this->mapper->findByRoomId($roomId);
	}

	/** @return RoomPermission[] */
	public function listForGroup(string $groupId): array {
		return $this->mapper->findByGroupId($groupId);
	}

	public function grantOnRoom(string $roomId, string $role, string $principalType, string $principalId): RoomPermission {
		return $this->grant($roomId, null, $role, $principalType, $principalId);
	}

	public function grantOnGroup(string $groupId, string $role, string $principalType, string $principalId): RoomPermission {
		return $this->grant(null, $groupId, $role, $principalType, $principalId);
	}

	private function grant(?string $roomId, ?string $groupId, string $role, string $principalType, string $principalId): RoomPermission {
		if (!in_array($role, self::VALID_ROLES, true)) {
			throw new RoomValidationException('Invalid role: ' . $role);
		}
		if (!in_array($principalType, self::VALID_PRINCIPAL_TYPES, true)) {
			throw new RoomValidationException('Invalid principal type: ' . $principalType);
		}
		if (trim($principalId) === '') {
			throw new RoomValidationException('Principal id is required');
		}

		$p = new RoomPermission();
		$p->setRoomId($roomId);
		$p->setGroupId($groupId);
		$p->setRole($role);
		$p->setPrincipalType($principalType);
		$p->setPrincipalId($principalId);
		return $this->mapper->insert($p);
	}

	public function revoke(int $permissionId): void {
		$this->mapper->deleteById($permissionId);
	}
}
