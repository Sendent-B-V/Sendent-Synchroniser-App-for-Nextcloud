<?php

// db/author.php
namespace OCA\SendentSynchroniser\Service\Entities;

use JsonSerializable;

class UserItem implements JsonSerializable {
	public $name;

	public function __construct() {
		// add types in constructor
	}

	public function jsonSerialize() {
		return [
			'name' => $this->name
		];
	}
}
