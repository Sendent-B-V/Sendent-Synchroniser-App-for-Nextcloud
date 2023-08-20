<?php

namespace OCA\SendentSynchroniser\Controller;

use OCP\IRequest;
use OCP\AppFramework\Controller;

class PageController extends Controller {
	
	public function __construct($AppName, IRequest $request) {

		parent::__construct($AppName, $request);
	}
	
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function health(){
		return "OK";
	}
}
