<?php

namespace OCA\SendentSynchroniser\Http\Dto;

use OCA\SendentSynchroniser\Db\License;
use JsonSerializable;

class SubscriptionIn implements JsonSerializable {
	protected $key;
	protected $amountusers;
	protected $email;

	public function __construct(License $license, int $connectedusercount) {
		// add types in constructor
		$this->key = $license->getLicensekey();
		$this->email = $license->getEmail();
		$this->amountusers = $connectedusercount;
	}
	public function jsonSerialize() {
		return [
			'Key' => $this->key,
			'AmountUsers' => $this->amountusers,
			'Email' => $this->email
		];
	}
}
