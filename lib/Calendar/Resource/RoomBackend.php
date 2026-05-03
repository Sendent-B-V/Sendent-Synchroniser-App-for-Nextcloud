<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Calendar\Resource;

use OCA\SendentSynchroniser\Db\RoomMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Calendar\Room\IBackend;
use OCP\Calendar\Room\IRoom;

class RoomBackend implements IBackend {
	public const BACKEND_IDENTIFIER = 'sendentsynchroniser';

	public function __construct(private RoomMapper $mapper) {}

	public function getBackendIdentifier(): string {
		return self::BACKEND_IDENTIFIER;
	}

	/** @return IRoom[] */
	public function getAllRooms(): array {
		$out = [];
		foreach ($this->mapper->findAll() as $entity) {
			if ($entity->getActive()) {
				$out[] = new Room($this, $entity);
			}
		}
		return $out;
	}

	/** @return string[] */
	public function listAllRooms(): array {
		$out = [];
		foreach ($this->mapper->findAll() as $entity) {
			if ($entity->getActive()) {
				$out[] = $entity->getId();
			}
		}
		return $out;
	}

	public function getRoom($id): ?IRoom {
		try {
			$entity = $this->mapper->findById($id);
		} catch (DoesNotExistException) {
			return null;
		}
		return new Room($this, $entity);
	}
}
