<?php

// db/author.php
namespace OCA\SendentSynchroniser\Db;

use OCP\AppFramework\Db\Entity;
use JsonSerializable;

class SyncGroup extends Entity implements JsonSerializable {
	protected $name;

	public function __construct() {
		// add types in constructor
	}

	public function jsonSerialize() {
		return [
			'id' => $this->id,
			'name' => $this->name,
		];
	}
}
