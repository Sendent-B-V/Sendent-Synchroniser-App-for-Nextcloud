<?php

namespace OCA\SendentSynchroniser\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IRequest;

class PageController extends Controller {

	public function __construct($AppName, IRequest $request) {
		parent::__construct($AppName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function health(){
		return "OK";
	}

}
