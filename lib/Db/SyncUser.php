<?php

// db/author.php
namespace OCA\SendentSynchroniser\Db;

use OCP\AppFramework\Db\Entity;
use JsonSerializable;

class SyncUser extends Entity implements JsonSerializable {
	protected $username;
	protected $groupId;

	public function __construct() {
		// add types in constructor
	}

	public function jsonSerialize() {
		return [
			'id' => $this->id,
			'username' => $this->username,
			'groupId' => $this->groupId,
		];
	}
}
