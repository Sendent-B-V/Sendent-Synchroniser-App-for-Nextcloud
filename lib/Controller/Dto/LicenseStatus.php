<?php

namespace OCA\SendentSynchroniser\Controller\Dto;

use JsonSerializable;

class LicenseStatus implements JsonSerializable {
	public $status;
	public $statusKind;
	public $dateExpiration;
	public $dateLastCheck;
	public $email;
	public $licensekey;
	public $level;

	public function __construct(string $status, string $statusKind,
	string $level, string $licensekey,
	string $dateExpiration, string $dateLastCheck, string $email) {
		// add types in constructor
		$this->status = $status;
		$this->statusKind = $statusKind;
		$this->licensekey = $licensekey;
		$this->dateExpiration = $dateExpiration;
		$this->dateLastCheck = $dateLastCheck;
		$this->email = $email;
		$this->level = $level;
	}

	public function jsonSerialize() {
		return [
			'status' => $this->status,
			'statusKind' => $this->statusKind,
			'dateExpiration' => $this->dateExpiration,
			'email' => $this->email,
			'level' => $this->level,
			'licensekey' => $this->licensekey,
			'dateLastCheck' => $this->dateLastCheck,
		];
	}
}
