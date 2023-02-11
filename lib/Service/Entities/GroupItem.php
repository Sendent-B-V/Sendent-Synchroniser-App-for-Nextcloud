<?php

// db/author.php
namespace OCA\SendentSynchroniser\Service\Entities;

use JsonSerializable;

class GroupItem implements JsonSerializable {
	public $id;
	public array $users;

	public function __construct() {
		// add types in constructor
		$this->users = array();
	}

	public function jsonSerialize() {
		return [
			'id' => $this->id,
			'users' => $this->users
		];
	}
}
