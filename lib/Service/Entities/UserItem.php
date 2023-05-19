<?php

// db/author.php
namespace OCA\SendentSynchroniser\Service\Entities;

use JsonSerializable;

class UserItem implements JsonSerializable {
	public $name;
	public $id;
	public $email;

	public function __construct() {
		// add types in constructor
	}

	public function jsonSerialize() {
		return [
			'id' => $this->id,
			'name' => $this->name,
			'email' => $this->email
		];
	}
}
