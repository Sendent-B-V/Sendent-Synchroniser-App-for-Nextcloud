<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\UserBackend;

use OC\User\Backend;
use OCA\SendentSynchroniser\Db\RoomMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\User\Backend\ICheckPasswordBackend;
use OCP\User\Backend\ICountUsersBackend;
use OCP\User\Backend\IGetDisplayNameBackend;

/**
 * UIDs are `_room_<roomId>`. Accounts exist (CalDAV principals work, calendars
 * have an owner) but `getUsers()` returns empty so they don't appear in
 * pickers. `checkPassword()` always returns false — these accounts never log in.
 */
class RoomUserBackend extends Backend implements ICheckPasswordBackend, IGetDisplayNameBackend, ICountUsersBackend {
	public const PREFIX = '_room_';

	public function __construct(private RoomMapper $mapper) {}

	public function getBackendName(): string {
		return 'sendent_rooms';
	}

	public function userExists($uid): bool {
		$roomId = $this->extractRoomId($uid);
		if ($roomId === null) {
			return false;
		}
		try {
			$this->mapper->findById($roomId);
			return true;
		} catch (DoesNotExistException) {
			return false;
		}
	}

	public function getDisplayName($uid): string {
		$roomId = $this->extractRoomId($uid);
		if ($roomId === null) {
			return $uid;
		}
		try {
			return $this->mapper->findById($roomId)->getName() ?? $uid;
		} catch (DoesNotExistException) {
			return $uid;
		}
	}

	public function getUsers($search = '', $limit = null, $offset = null): array {
		return [];
	}

	public function getDisplayNames($search = '', $limit = null, $offset = null): array {
		return [];
	}

	public function checkPassword(string $loginName, string $password): string|false {
		return false;
	}

	public function countUsers(): int {
		return 0;
	}

	public function implementsActions($actions): bool {
		return (bool) ((Backend::CHECK_PASSWORD | Backend::GET_DISPLAYNAME | Backend::COUNT_USERS) & $actions);
	}

	private function extractRoomId(string $uid): ?string {
		if (!str_starts_with($uid, self::PREFIX)) {
			return null;
		}
		return substr($uid, strlen(self::PREFIX));
	}
}
