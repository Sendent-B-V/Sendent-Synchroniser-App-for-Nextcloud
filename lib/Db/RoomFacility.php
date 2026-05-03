<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.vandebroek@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method string|null getRoomId()
 * @method void setRoomId(string $roomId)
 * @method string|null getFacility()
 * @method void setFacility(string $facility)
 */
class RoomFacility extends Entity implements JsonSerializable {
	protected $roomId;
	protected $facility;

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'roomId' => $this->roomId,
			'facility' => $this->facility,
		];
	}
}
