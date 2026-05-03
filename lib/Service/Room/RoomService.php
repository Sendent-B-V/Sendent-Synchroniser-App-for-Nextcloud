<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Service\Room;

use DateTime;
use OCA\SendentSynchroniser\Db\Room;
use OCA\SendentSynchroniser\Db\RoomBindingMapper;
use OCA\SendentSynchroniser\Db\RoomFacilityMapper;
use OCA\SendentSynchroniser\Db\RoomMapper;
use OCA\SendentSynchroniser\Db\RoomPermissionMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Calendar\Room\IManager as IRoomManager;
use Psr\Log\LoggerInterface;

class RoomService {
	private const ID_PATTERN = '/^[a-z0-9][a-z0-9-]{0,62}[a-z0-9]$/';

	public function __construct(
		private RoomMapper $rooms,
		private RoomFacilityMapper $facilities,
		private RoomPermissionMapper $permissions,
		private RoomBindingMapper $bindings,
		private HiddenUserService $hiddenUsers,
		private CalDAVService $calDav,
		private IRoomManager $roomManager,
		private LoggerInterface $logger,
	) {}

	/**
	 * Force NC to repopulate `oc_calendar_rooms` so the room appears in the
	 * Calendar resource picker immediately, instead of waiting for the daily
	 * `OCA\DAV\BackgroundJob\UpdateCalendarResourcesRoomsBackgroundJob` cron.
	 * Failures are logged and swallowed — the daily cron will reconcile.
	 */
	private function refreshRoomCache(): void {
		try {
			$this->roomManager->update();
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to refresh NC room cache: ' . $e->getMessage());
		}
	}

	/** @return Room[] */
	public function listAll(): array {
		return $this->rooms->findAll();
	}

	/**
	 * @return array{items: Room[], total: int, page: int, perPage: int}
	 */
	public function listPage(int $page, int $perPage, ?string $q): array {
		$perPage = max(1, min(100, $perPage));
		$page = max(1, $page);
		$needle = $q === null ? null : (trim($q) === '' ? null : trim($q));
		$total = $this->rooms->countAll($needle);
		$lastPage = max(1, (int) ceil($total / $perPage));
		if ($page > $lastPage) {
			$page = $lastPage;
		}
		$items = $this->rooms->findPage($page, $perPage, $needle);
		return ['items' => $items, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function get(string $id): Room {
		return $this->rooms->findById($id);
	}

	public function create(array $data): Room {
		$id = $data['id'] ?? '';
		$name = $data['name'] ?? '';
		if (!preg_match(self::ID_PATTERN, $id)) {
			throw new RoomValidationException('Invalid room id: must be lowercase, kebab-case, 2-64 chars');
		}
		if (trim($name) === '') {
			throw new RoomValidationException('Room name is required');
		}

		// Build entity. URIs are deterministic from the id.
		$principalUri = $this->hiddenUsers->principalUriFor($id);
		$calendarUri = $this->calDav->calendarUriFor($this->hiddenUsers->uidFor($id));

		$now = new DateTime();
		$room = new Room();
		$room->setId($id);
		$room->setName($name);
		$room->setEmail($data['email'] ?? null);
		$room->setCapacity(isset($data['capacity']) ? (int) $data['capacity'] : null);
		$room->setRoomNumber($data['roomNumber'] ?? null);
		$room->setFloor($data['floor'] ?? null);
		$room->setAddress($data['address'] ?? null);
		$room->setRoomType($data['roomType'] ?? 'meeting-room');
		$room->setDescription($data['description'] ?? null);
		$room->setBackingPrincipalUri($principalUri);
		$room->setBackingCalendarUri($calendarUri);
		$room->setGroupId($data['groupId'] ?? null);
		$room->setActive(true);
		$room->setCreatedAt($now);
		$room->setUpdatedAt($now);

		// Insert the room row FIRST. Once it's in the DB, RoomUserBackend's
		// userExists() returns true for `_room_<id>`, so any CalDAV plumbing
		// that resolves the principal sees a valid user.
		$this->rooms->insert($room);

		// Provision the calendar. If this fails, roll back the room insert
		// so we don't leave an orphan row.
		try {
			$this->calDav->provision($principalUri, $name);
		} catch (\Throwable $e) {
			$this->rooms->deleteById($id);
			throw $e;
		}

		// Side-effect bookkeeping. Best-effort: a failure here doesn't undo the
		// room itself.
		$this->hiddenUsers->provision($id); // currently a no-op; kept as a hook
		if (!empty($data['facilities']) && is_array($data['facilities'])) {
			$this->facilities->replaceForRoom($id, $data['facilities']);
		}

		$this->refreshRoomCache();

		return $room;
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function update(string $id, array $patch): Room {
		$room = $this->rooms->findById($id);
		$mutable = ['name','email','capacity','roomNumber','floor','address',
					'roomType','description','groupId','active'];
		foreach ($mutable as $field) {
			if (array_key_exists($field, $patch)) {
				$setter = 'set' . ucfirst($field);
				$room->$setter($patch[$field]);
			}
		}
		$room->setUpdatedAt(new DateTime());
		$this->rooms->update($room);

		if (array_key_exists('facilities', $patch) && is_array($patch['facilities'])) {
			$this->facilities->replaceForRoom($id, $patch['facilities']);
		}

		$this->refreshRoomCache();

		return $room;
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function delete(string $id): void {
		$room = $this->rooms->findById($id);
		$this->bindings->deleteByRoomId($id);
		$this->permissions->deleteByRoomId($id);
		$this->facilities->deleteByRoomId($id);
		$this->calDav->deprovision($room->getBackingPrincipalUri());
		$this->hiddenUsers->deprovision($id);
		$this->rooms->deleteById($id);
		$this->refreshRoomCache();
	}
}
