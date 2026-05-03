<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Calendar\Resource;

use OCA\SendentSynchroniser\Db\Room as RoomEntity;
use OCP\Calendar\IMetadataProvider;
use OCP\Calendar\Room\IBackend;
use OCP\Calendar\Room\IRoom;

/**
 * Wraps a RoomEntity for NC's calendar resource picker.
 *
 * Implements both `IRoom` (the principal/booking shape) and `IMetadataProvider`
 * (the picker-display shape). Without `IMetadataProvider` the picker silently
 * skips rooms whose backend exposes only `IRoom` — which is why fresh rooms
 * weren't appearing earlier even though `getAllRooms()` returned them.
 *
 * Email must always be a non-empty string for NC to register the room as a
 * bookable principal; we fall back to a synthesized id-based address if the
 * admin hasn't set one. The synthesized address won't actually receive mail —
 * it's a placeholder so the picker doesn't filter the room out.
 */
class Room implements IRoom, IMetadataProvider {

	private const METADATA_KEYS = [
		'{urn:ietf:params:xml:ns:caldav}calendar-description',
		'{http://nextcloud.com/ns}room-type',
		'{http://nextcloud.com/ns}room-seating-capacity',
		'{http://nextcloud.com/ns}room-building-address',
		'{http://nextcloud.com/ns}room-building-room-number',
		'{http://nextcloud.com/ns}room-building-floor',
		'{http://nextcloud.com/ns}room-features',
	];

	public function __construct(
		private IBackend $backend,
		private RoomEntity $entity,
	) {}

	public function getId(): string {
		return $this->entity->getId();
	}

	public function getDisplayName(): string {
		return $this->entity->getName() ?? $this->entity->getId();
	}

	public function getGroupRestrictions(): array {
		return [];
	}

	public function getEMail(): string {
		$email = $this->entity->getEmail();
		if ($email !== null && $email !== '') {
			return $email;
		}
		// Fallback so the picker doesn't filter the room out for missing email.
		// Uses the principal-uri-shaped form NC uses for synthetic principals.
		return $this->entity->getId() . '@rooms.local';
	}

	public function getBackend(): IBackend {
		return $this->backend;
	}

	// ---------- IMetadataProvider ----------

	public function getAllAvailableMetadataKeys(): array {
		return self::METADATA_KEYS;
	}

	public function hasMetadataForKey(string $key): bool {
		return $this->getMetadataForKey($key) !== null;
	}

	public function getMetadataForKey(string $key): ?string {
		return match ($key) {
			'{urn:ietf:params:xml:ns:caldav}calendar-description' => $this->buildDescription(),
			'{http://nextcloud.com/ns}room-type' => $this->entity->getRoomType(),
			'{http://nextcloud.com/ns}room-seating-capacity'
				=> $this->entity->getCapacity() !== null ? (string) $this->entity->getCapacity() : null,
			'{http://nextcloud.com/ns}room-building-address'
				=> $this->nullIfBlank($this->entity->getAddress()),
			'{http://nextcloud.com/ns}room-building-room-number'
				=> $this->nullIfBlank($this->entity->getRoomNumber()),
			'{http://nextcloud.com/ns}room-building-floor'
				=> $this->nullIfBlank($this->entity->getFloor()),
			'{http://nextcloud.com/ns}room-features' => null, // facilities live in a separate table; picker doesn't need them inline
			default => null,
		};
	}

	private function nullIfBlank(?string $s): ?string {
		return ($s !== null && $s !== '') ? $s : null;
	}

	private function buildDescription(): string {
		$parts = [$this->getDisplayName()];

		if (($a = $this->entity->getAddress()) !== null && $a !== '') {
			$parts[] = "Address: {$a}";
		}
		if (($n = $this->entity->getRoomNumber()) !== null && $n !== '') {
			$parts[] = "Room: {$n}";
		}
		$cap = $this->entity->getCapacity();
		if ($cap !== null && $cap > 0) {
			$parts[] = "Capacity: {$cap}";
		}
		if (($d = $this->entity->getDescription()) !== null && $d !== '') {
			$parts[] = $d;
		}

		return implode(' | ', $parts);
	}
}
