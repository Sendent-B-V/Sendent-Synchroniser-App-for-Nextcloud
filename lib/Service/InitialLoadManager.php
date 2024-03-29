<?php

namespace OCA\SendentSynchroniser\Service;

use Exception;
use OCA\SendentSynchroniser\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\PreConditionNotMetException;

class InitialLoadManager {
	private $syncGroupService;
	private $externalUserGroupService;
	private $config;

	/** @var IAppManager */
	private $appManager;

	public function __construct(
		IConfig $config,
		IAppManager $appManager) {

		$this->config = $config;
		$this->appManager = $appManager;

		$this->checkUpdateNeeded010();
	}

	/**
	 *
	 * Return true if this is the first time a user is acessing their instance with sendentsynchroniser enabled
	 *
	 * @param $userId
	 * @return bool
	 */
	public function checkUpdateNeeded010(): bool {
		$firstRun = $this->config->getAppValue('sendentsynchroniser', 'firstRunAppVersion');

		if ($firstRun !== '0.1.0') {
			try {
				$this->initialLoading();
				$this->config->setAppValue('sendentsynchroniser', 'firstRunAppVersion', '0.1.0');
			} catch (PreConditionNotMetException $e) {
				return false;
			}
			return true;
		}

		return false;
	}

	public function initialLoading(): void {
	}

}
