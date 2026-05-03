<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Service\Room;

use OCA\SendentSynchroniser\UserBackend\RoomUserBackend;
use OCP\IUserManager;

class HiddenUserService {
	public function __construct(
		private IUserManager $userManager,
		private RoomUserBackend $backend,
	) {}

	public function uidFor(string $roomId): string {
		return RoomUserBackend::PREFIX . $roomId;
	}

	public function principalUriFor(string $roomId): string {
		return 'principals/users/' . $this->uidFor($roomId);
	}

	/**
	 * No-op. The hidden user "exists" the moment the rooms-table row is in
	 * the DB — `RoomUserBackend::userExists()` looks up the room by id from
	 * the mapper, so as long as the room row is inserted before anything
	 * checks the principal, NC sees the user.
	 *
	 * We do NOT call `IUserManager::createUserFromBackend()`: NC's manager
	 * requires the backend to implement `ICreateUserBackend::createUser()`,
	 * but for a virtual lookup-only backend like ours there's nothing to
	 * create — the room IS the user. Pattern: no createUser call, just
	 * registerBackend + userExists callback.
	 *
	 * Caller contract: `RoomService::create()` must insert the room row
	 * before any code path that relies on `userExists()` (e.g. CalDAV
	 * calendar provisioning).
	 */
	public function provision(string $roomId): void {
		// intentional no-op
	}

	public function deprovision(string $roomId): void {
		// Best-effort: if NC happened to instantiate an IUser for this uid
		// during the room's lifetime (e.g. via $userManager->get()), drop it.
		// In practice the room row is the source of truth, so this is mostly
		// defensive cache invalidation.
		$user = $this->userManager->get($this->uidFor($roomId));
		$user?->delete();
	}
}
