<?php

namespace OCA\SendentSynchroniser\Controller;

use OCP\IRequest;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Controller;

class PageController extends Controller {
	private $userId;

	public function __construct($AppName, IRequest $request, $UserId) {
		parent::__construct($AppName, $request);
		$this->userId = $UserId;
	}

	/**
	 * 	 * CAUTION: the @Stuff turns off security checks; for this page no admin is
	 * 	 *          required and no CSRF check. If you don't know what CSRF is, read
	 * 	 *          it up in the docs or you might create a security hole. This is
	 * 	 *          basically the only required method to add this exemption, don't
	 * 	 *          add it to any other method if you don't exactly know what it does
	 * 	 *
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return TemplateResponse
	 */
	public function index(): TemplateResponse {
		return new TemplateResponse('sendentsynchroniser', 'index');  // templates/index.php
	}
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @return string
	 */
	public function health(){
		return "OK";
	}
}
