<?php

namespace OCA\SendentSynchroniser\Controller;

use OCP\IRequest;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Services\IAppConfig;
use \OCP\ILogger;

class SettingsController extends ApiController {

	/** @var IAppConfig */
	private $appConfig;

	/** @var ILogger */
	private $logger;

	public function __construct($appName, IRequest $request,
		IAppConfig $appConfig,
		ILogger $logger) {

 		parent::__construct($appName, $request);

		$this->appConfig = $appConfig;
		$this->logger = $logger;

 	}

  	/**
	 * 
	 * Saves shared secret
	 * 
	 * @param string $sharedSecret
	 * 
	 */
	public function setSharedSecret($sharedSecret) {
		$this->appConfig->setAppValue('sharedSecret', $sharedSecret);
		return;
	}

	/**
	 * 
	 * Saves notification method
	 * 
	 * @param string $notificationMethod
	 * 
	 */
	public function setNotificationMethod($notificationMethod) {
		$this->appConfig->setAppValue('notificationMethod', $notificationMethod);
		return;
	}

	/**
	 *
	 * Gets notification method
	 *
	 * @param string $notificationMethod
	 *
	 */
	public function getNotificationMethod() {
		return $this->appConfig->getAppValue('notificationMethod', 0);
	}

	/**
	 * 
	 * Saves new active groups list
	 * 
	 * @param string $newSendentGroups
	 * 
	 */
	public function setActiveGroups($newSendentGroups) {
		$this->appConfig->setAppValue('activeGroups', json_encode($newSendentGroups));
		return;
	}

}
