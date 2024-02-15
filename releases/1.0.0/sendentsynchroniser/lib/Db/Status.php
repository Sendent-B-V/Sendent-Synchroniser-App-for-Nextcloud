<?php

namespace OCA\SendentSynchroniser\Db;

use JsonSerializable;

class Status implements JsonSerializable {
	public $version;
	public $currentuserid;
	public $app;
	public $datelicenseend;
	public $maxusers;
	public $validLicense;
	public $licenseaction;
	public $dategraceperiodend;
	public $maxusersgrace;
	public $currentusers;

	public function __construct() {
		// add types in constructor
	}
	public function jsonSerialize() {
		return [
			'Version' => $this->version,
			'CurrentUserId' => $this->currentuserid,
			'App' => $this->app,
			'DateLicenseEnd' => $this->datelicenseend,
			'MaxUsers' => $this->maxusers,
			'ValidLicense' => $this->validLicense,
			'LicenseAction' => $this->licenseaction,
			'DateGracePeriodEnd' => $this->dategraceperiodend,
			'MaxGraceUsers' => $this->maxusersgrace,
			'CurrentUserCount' => $this->currentusers
		];
	}
}
