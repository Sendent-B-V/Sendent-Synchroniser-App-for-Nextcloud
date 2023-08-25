<?php

namespace OCA\SendentSynchroniser\Controller;

use OCP\IRequest;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;

class PageController extends Controller {
	
	private $AppName;

	public function __construct($AppName, IRequest $request) {

		parent::__construct($AppName, $request);
		$this->AppName = $AppName;
	}
	
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function health(){
		return "OK";
	}

	/**
	 *
	 * @NoAdminRequired
	 *
	 */
	public function getStartConsentFlowPage(){
		return new TemplateResponse($this->AppName,'startConsentFlow', array('activeUser' => 0), '');
	}

}
