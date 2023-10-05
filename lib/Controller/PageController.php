<?php

namespace OCA\SendentSynchroniser\Controller;

use OCP\IRequest;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCA\SendentSynchroniser\Constants;

class PageController extends Controller {
	
	/** @var IAppConfig */
	private $appConfig;

	/** @var string */
	private $AppName;

	public function __construct(IAppConfig $appConfig, $AppName, IRequest $request) {

		parent::__construct($AppName, $request);

		$this->appConfig = $appConfig;
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
	public function getConsentFlowPage(){

		$response = new TemplateResponse($this->AppName,'sections/consentFlow', array('activeUser' => 0), '');

		$interval = $this->appConfig->getAppValue('notificationInterval', Constants::REMINDER_NOTIFICATIONS_DEFAULT_INTERVAL);
		$expirationDate = new \DateTime;
		$expirationDate = $expirationDate->add(new \DateInterval("P" . $interval . "D"));

		$response->addCookie('sendentsynchroniser_activationreminder_timeout', 'true', $expirationDate);

		return $response;

	}

}
