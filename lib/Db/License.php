<?php

// db/author.php
namespace OCA\SendentSynchroniser\Db;

use DateInterval;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

class License extends Entity implements JsonSerializable {
	public const ERROR_INCOMPLETE = 'Error_incomplete';
	public const ERROR_VALIDATING = 'Error_validating';

	protected $licensekey;
	protected $email;
	protected $dategraceperiodend;
	protected $datelicenseend;
	protected $maxusers;
	protected $maxgraceusers;
	protected $datelastchecked;
	protected $level;

	public function __construct() {
		// add types in constructor
	}
	public function jsonSerialize() {
		return [
			'id' => $this->id,
			'licensekey' => $this->licensekey,
			'email' => $this->email,
			'dategraceperiodend' => $this->dategraceperiodend,
			'maxusers' => $this->maxusers,
			'maxgraceusers' => $this->maxgraceusers,
			'level' => $this->level,
			'datelicenseend' => $this->datelicenseend,
			'datelastchecked' => $this->datelastchecked,
		];
	}

	public function isCheckNeeded(): bool {
		$diffDay = new DateInterval('P7D');
		if (date_create($this->datelastchecked) >= date_sub(date_create("now"), $diffDay) && $this->level != License::ERROR_VALIDATING) {
			error_log(print_r("LICENSE-ISCHECKNEEDED: FALSE", true));

			return false;
		}
		error_log(print_r("LICENSE-ISCHECKNEEDED: TRUE", true));

		return true;
	}
	public function isIncomplete(): bool {
		if ($this->level == "Error_incomplete" || (!isset($this->licensekey) || !isset($this->licensekey)) || ($this->licensekey == "" || $this->email == "")) {
			return true;
		}
		return false;
	}
	public function isCleared(): bool {
		if ((!isset($this->licensekey) && !isset($this->licensekey)) || ($this->licensekey == "" && $this->email == "")) {
			return true;
		}
		return false;
	}
	public function isLicenseExpired(): bool {
		if (date_create($this->datelicenseend) < date_create("now")
		&& date_create($this->dategraceperiodend) < date_create("now")) {
			return true;
		}
		return false;
	}
}
