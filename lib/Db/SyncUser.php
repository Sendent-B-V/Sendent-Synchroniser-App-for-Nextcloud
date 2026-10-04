<?php

namespace OCA\SendentSynchroniser\Db;

use OCP\AppFramework\Db\Entity;
use JsonSerializable;

class SyncUser extends Entity implements JsonSerializable {
	protected $active;
	protected $token;
	protected $uid;
	protected $username;
	protected $calendar;
	protected $addressbook;
	// 1 until the user answers the one-time calendar clean-up; stamped at upgrade.
	protected $resetoffer = 0;

	public function __construct() {
		$this->addType('resetoffer', 'integer');
	}

	public function jsonSerialize() {
		// No resetoffer: this payload goes to the connector.
		return [
			'id' => $this->id,
			'uid' => $this->uid,
			'token' => $this->token,
			'active' => $this->active,
			'username' => $this->username,
			'calendar' => $this->calendar,
			'addressbook' => $this->addressbook,
		];
	}
}
