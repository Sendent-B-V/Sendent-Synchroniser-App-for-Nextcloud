<?php

namespace OCA\SendentSynchroniser\Http\Dto;

use JsonSerializable;

class SubscriptionOut implements JsonSerializable {
	protected $key;
	protected $amountusers;
	protected $amountusersmax;
	protected $level;
	protected $expirationdate;
	protected $grantinterval;
	protected $email;
	protected $istrial;
	protected $product;
	protected $technicallevel;

	public function __construct() {
	}

	public function jsonSerialize() {
		return [
			'Key' => $this->key,
			'AmountUsers' => $this->amountusers,
			'AmountUsersMax' => $this->amountusersmax,
			'Level' => $this->level,
			'ExpirationDate' => $this->expirationdate,
			'GrantInterval' => $this->grantinterval,
			'Email' => $this->email,
			'IsTrial' => $this->istrial,
			'Product' => $this->product,
			'TechnicalProductLevel' => $this->technicallevel
		];
	}
}
