<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method string|null getRoomId()
 * @method void setRoomId(?string $roomId)
 * @method string|null getGroupId()
 * @method void setGroupId(?string $groupId)
 * @method string|null getRole()
 * @method void setRole(string $role)
 * @method string|null getPrincipalType()
 * @method void setPrincipalType(string $principalType)
 * @method string|null getPrincipalId()
 * @method void setPrincipalId(string $principalId)
 */
class RoomPermission extends Entity implements JsonSerializable {
	public const ROLE_VIEWER = 'viewer';
	public const ROLE_BOOKER = 'booker';
	public const ROLE_MANAGER = 'manager';
	public const PRINCIPAL_USER = 'user';
	public const PRINCIPAL_GROUP = 'group';

	protected $roomId;
	protected $groupId;
	protected $role;
	protected $principalType;
	protected $principalId;

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'roomId' => $this->roomId,
			'groupId' => $this->groupId,
			'role' => $this->role,
			'principalType' => $this->principalType,
			'principalId' => $this->principalId,
		];
	}
}
