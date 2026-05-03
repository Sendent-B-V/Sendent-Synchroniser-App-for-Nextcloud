<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.vandebroek@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method string|null getName()
 * @method void setName(string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 */
class RoomGroup extends Entity implements JsonSerializable {
	protected $name;
	protected $description;

	public function __construct() {
		// NC's Entity defaults `id` to integer cast, which mangles our string PK
		// (e.g. "1111" gets cast to int 1111, then JSON-serialized as a number,
		// breaking the controller's `?string $groupId` parameter on POST). Force
		// string type so the value round-trips correctly.
		$this->addType('id', 'string');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'name' => $this->name,
			'description' => $this->description,
		];
	}
}
