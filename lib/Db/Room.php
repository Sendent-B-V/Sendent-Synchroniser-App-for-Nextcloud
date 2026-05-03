<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.vandebroek@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Db;

use DateTimeInterface;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method string|null getName()
 * @method void setName(string $name)
 * @method string|null getEmail()
 * @method void setEmail(?string $email)
 * @method int|null getCapacity()
 * @method void setCapacity(?int $capacity)
 * @method string|null getRoomNumber()
 * @method void setRoomNumber(?string $roomNumber)
 * @method string|null getFloor()
 * @method void setFloor(?string $floor)
 * @method string|null getAddress()
 * @method void setAddress(?string $address)
 * @method string|null getRoomType()
 * @method void setRoomType(string $roomType)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getBackingPrincipalUri()
 * @method void setBackingPrincipalUri(string $uri)
 * @method string|null getBackingCalendarUri()
 * @method void setBackingCalendarUri(string $uri)
 * @method string|null getGroupId()
 * @method void setGroupId(?string $groupId)
 * @method bool|null getActive()
 * @method void setActive(bool $active)
 * @method DateTimeInterface|null getCreatedAt()
 * @method void setCreatedAt(DateTimeInterface $createdAt)
 * @method DateTimeInterface|null getUpdatedAt()
 * @method void setUpdatedAt(DateTimeInterface $updatedAt)
 */
class Room extends Entity implements JsonSerializable {
	protected $name;
	protected $email;
	protected $capacity;
	protected $roomNumber;
	protected $floor;
	protected $address;
	protected $roomType;
	protected $description;
	protected $backingPrincipalUri;
	protected $backingCalendarUri;
	protected $groupId;
	protected $active;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		// NC's Entity defaults `id` to integer cast, which mangles our
		// string-PK rooms (e.g. "boardroom-a" → 0, "1111" → int 1111).
		// Force string so the value round-trips correctly through JSON.
		$this->addType('id', 'string');
		$this->addType('capacity', 'integer');
		$this->addType('active', 'boolean');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'name' => $this->name,
			'email' => $this->email,
			'capacity' => $this->capacity,
			'roomNumber' => $this->roomNumber,
			'floor' => $this->floor,
			'address' => $this->address,
			'roomType' => $this->roomType,
			'description' => $this->description,
			'backingPrincipalUri' => $this->backingPrincipalUri,
			'backingCalendarUri' => $this->backingCalendarUri,
			'groupId' => $this->groupId,
			'active' => (bool) $this->active,
			'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
			'updatedAt' => $this->updatedAt?->format(\DateTimeInterface::ATOM),
		];
	}
}
