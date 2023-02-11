<?php

namespace OCA\SendentSynchroniser\Settings;

use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;

class SendentSynchroniserSettings implements ISettings {

	/** @var IAppManager */
	private $appManager;

	/** @var IInitialState */
	private $initialState;

	/** @var IAppConfig */
	private $appConfig;

	public function __construct(
		IAppManager $appManager,
		IInitialState $initialState,
		IAppConfig $appConfig
			) {
		$this->appManager = $appManager;
		$this->initialState = $initialState;
		$this->appConfig = $appConfig;
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm() {

		return new TemplateResponse('sendentsynchroniser', 'index');
	}

	/**
	 * @param string $appId
	 *
	 * @return false|string
	 */
	private function getEnabledAppVersion(string $appId) {
		if (!$this->appManager->isInstalled($appId)) {
			return false;
		}

		return $this->appManager->getAppVersion($appId);
	}

	/**
	 * @return string the section ID, e.g. 'sharing'
	 */
	public function getSection() {
		return 'sendentsynchroniser';
	}

	/**
	 * @return int whether the form should be rather on the top or bottom of
	 * the admin section. The forms are arranged in ascending order of the
	 * priority values. It is required to return a value between 0 and 100.
	 */
	public function getPriority() {
		return 50;
	}
}
