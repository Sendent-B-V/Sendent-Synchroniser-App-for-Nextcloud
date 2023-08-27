<?php

namespace OCA\SendentSynchroniser\Settings;

use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroupManager;
use OCP\Settings\ISettings;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ISession;
use \OCP\ILogger;
use OCP\Security\ISecureRandom;
use OCP\IURLGenerator;
use OCA\SendentSynchroniser\Db\SyncUserMapper;

class User implements ISettings {

	/** @var IAppManager */
	private $appManager;

	/** @var IGroupManager */
	private $groupManager;

	/** @var IAppConfig */
	private $appConfig;

	/** @var ILogger */
	private $logger;

	/** @var SyncUserMapper */
	private $syncUserMapper;

	public function __construct(
		IAppManager $appManager,
		IGroupManager $groupManager,
		IAppConfig $appConfig,
		ILogger $logger,
		SyncUserMapper $syncUserMapper,
		string $userId) {

		$this->appConfig = $appConfig;
		$this->appManager = $appManager;
		$this->groupManager = $groupManager;
		$this->logger = $logger;
		$this->syncUserMapper = $syncUserMapper;
		$this->userId = $userId;
	}

	/**
	 * 
	 * @return TemplateResponse
	 * 
	 */
	public function getForm() {
		if ($this->isUserAllowed() === false)
		{
			// User is not allowed to use Sendent synchroniser
			$this->logger->info('user is not allowed to use Sendent synchroniser');
			return new TemplateResponse('sendentsynchroniser', 'noSyncUser');
		}
		else{
			// User allowed to use Sendent synchroniser
			$syncUsers = $this->syncUserMapper->findByUid($this->userId);
			if (empty($syncUsers) || $syncUsers[0]->getActive() !== 1) {
				// User is not active
				return new TemplateResponse('sendentsynchroniser', 'indexUser', ['activeUser' => false]);
			} else {
				// User is active
				return new TemplateResponse('sendentsynchroniser', 'indexUser', ['activeUser' => true]);
			}
		}
	}

	/**
	 * 
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
	 * 
	 * @return string the section ID, e.g. 'sharing'
	 * 
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

	/**
	 * 
	 * This function tells if an user is allowed to use Sendent synchroniser
	 * 
	 * @return bool
	 * 
	 */
	private function isUserAllowed() : bool
	{					

		$activeGroups = $this->appConfig->getAppValue('activeGroups');
		$activeGroups = ($activeGroups !== '' && $activeGroups !== 'null') ? json_decode($activeGroups) : [];

		foreach($activeGroups as $gid)
		{
			if ($this->groupManager->isInGroup($this->userId, $gid)) {
				$this->logger->info('user is allowed to use Sendent synchroniser');
				return true;
			}

		}
		
		return false;
	}

}
