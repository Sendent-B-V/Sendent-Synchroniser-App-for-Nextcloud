<?php

namespace OCA\SendentSynchroniser\Db;

use OCP\AppFramework\Db\Entity;
use JsonSerializable;

class SyncUser extends Entity implements JsonSerializable {
	protected $active;
	protected $token;
	protected $uid;

	public function __construct() {
		// add types in constructor
	}

	public function jsonSerialize() {
		return [
			'id' => $this->id,
			'uid' => $this->uid,
			'token' => $this->token,
			'active' => $this->active,
		];
	}
}
