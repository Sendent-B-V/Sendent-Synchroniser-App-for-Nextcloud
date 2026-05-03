<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Service\Room;

use DateTime;
use OCA\SendentSynchroniser\Db\RoomBinding;
use OCA\SendentSynchroniser\Db\RoomBindingMapper;
use OCA\SendentSynchroniser\Db\RoomMapper;
use OCA\SendentSynchroniser\Service\LicenseManager;
use OCA\SendentSynchroniser\Service\Room\Binding\BindingKindRegistry;
use OCA\SendentSynchroniser\Service\Room\Binding\LicenseRequiredException;

class BindingService {
	public const ENTITLEMENT = 'rooms.sync';

	public function __construct(
		private RoomBindingMapper $bindings,
		private RoomMapper $rooms,
		private BindingKindRegistry $registry,
		private LicenseManager $license,
	) {}

	public function get(string $roomId): ?RoomBinding {
		return $this->bindings->findByRoomIdOrNull($roomId);
	}

	/**
	 * @throws LicenseRequiredException
	 * @throws RoomValidationException
	 */
	public function set(string $roomId, string $kind, string $externalId, array $config): RoomBinding {
		if (!$this->license->hasEntitlement(self::ENTITLEMENT)) {
			throw new LicenseRequiredException('Room sync requires a Sendent Sync license.');
		}
		$this->rooms->findById($roomId);

		$validator = $this->registry->get($kind);
		if ($validator === null) {
			throw new RoomValidationException('Unknown binding kind: ' . $kind);
		}
		$normalized = $validator->validate($externalId, $config);

		$now = new DateTime();
		$existing = $this->bindings->findByRoomIdOrNull($roomId);
		if ($existing === null) {
			$b = new RoomBinding();
			$b->setRoomId($roomId);
			$b->setKind($kind);
			$b->setExternalId($normalized['externalId']);
			$b->setConfig(json_encode($normalized['config'], JSON_UNESCAPED_UNICODE));
			$b->setLinkVersion(1);
			$b->setState(RoomBinding::STATE_PENDING);
			$b->setInitialSyncRequested(true);
			$b->setLastEventsPushed(0);
			$b->setLastEventsPulled(0);
			$b->setCreatedAt($now);
			$b->setUpdatedAt($now);
			return $this->bindings->insert($b);
		}

		$newConfigJson = json_encode($normalized['config'], JSON_UNESCAPED_UNICODE);
		$changed = $existing->getKind() !== $kind
			|| $existing->getExternalId() !== $normalized['externalId']
			|| $existing->getConfig() !== $newConfigJson;

		if ($changed) {
			$existing->setKind($kind);
			$existing->setExternalId($normalized['externalId']);
			$existing->setConfig($newConfigJson);
			$existing->setLinkVersion($existing->getLinkVersion() + 1);
			$existing->setState(RoomBinding::STATE_PENDING);
			$existing->setInitialSyncRequested(true);
			$existing->setLastError(null);
		}
		$existing->setUpdatedAt($now);
		return $this->bindings->update($existing);
	}

	public function clear(string $roomId): void {
		$this->bindings->deleteByRoomId($roomId);
	}

	public function retry(string $roomId): RoomBinding {
		$b = $this->bindings->findByRoomId($roomId);
		$b->setLinkVersion($b->getLinkVersion() + 1);
		$b->setState(RoomBinding::STATE_PENDING);
		$b->setInitialSyncRequested(true);
		$b->setLastError(null);
		$b->setUpdatedAt(new DateTime());
		return $this->bindings->update($b);
	}

	public function applyStatus(string $roomId, int $linkVersion, string $state,
		?DateTime $lastSyncedAt, ?string $error,
		?bool $initialSyncRequested, ?int $eventsPushed, ?int $eventsPulled): bool {
		$b = $this->bindings->findByRoomIdOrNull($roomId);
		if ($b === null) {
			return false;
		}
		if ($linkVersion < $b->getLinkVersion()) {
			return true;
		}
		$b->setState($state);
		if ($lastSyncedAt !== null) {
			$b->setLastSyncedAt($lastSyncedAt);
		}
		$b->setLastError($error);
		if ($initialSyncRequested !== null) {
			$b->setInitialSyncRequested($initialSyncRequested);
		}
		if ($eventsPushed !== null) {
			$b->setLastEventsPushed($eventsPushed);
		}
		if ($eventsPulled !== null) {
			$b->setLastEventsPulled($eventsPulled);
		}
		$b->setUpdatedAt(new DateTime());
		$this->bindings->update($b);
		return true;
	}
}
