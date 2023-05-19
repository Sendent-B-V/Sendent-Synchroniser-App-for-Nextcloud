<?php

// db/author.php
namespace OCA\SendentSynchroniser\Service\Entities;

use JsonSerializable;

class AppPasswordItem implements JsonSerializable {
	public $username;
	public $usergroup;
	public $userEmail;
	public $userId;
	public $password;

	public function __construct() {
		// add types in constructor
	}

	public function jsonSerialize() {
		return [
			'username' => $this->username,
			'usergroup' => $this->usergroup,
			'userEmail' => $this->userEmail,
			'userId' => $this->userId,
			'password' => $this->password
		];
	}
}
