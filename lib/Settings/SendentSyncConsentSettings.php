<?php

namespace OCA\SendentSynchroniser\Settings;

use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroupManager;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ISession;
use \OCP\ILogger;
use OCP\Security\ISecureRandom;
use OCA\SendentSynchroniser\Service\SyncGroupService;
use OCA\SendentSynchroniser\Service\UserGroupService;
use OCP\IURLGenerator;

class SendentSyncConsentSettings implements ISettings {

	/** @var IAppManager */
	private $appManager;

	/** @var SyncGroupService */
	private $syncGroupService;

	/** @var IGroupManager */
	private $groupManager;

	/** @var IInitialState */
	private $initialState;

	/** @var IAppConfig */
	private $appConfig;

	private $userId;
	private $externalUserService;
	private $logger;

	public function __construct(
		IAppManager $appManager,
		IGroupManager $groupManager,
		SyncGroupService $syncGroupService,
		IInitialState $initialState,
		IAppConfig $appConfig, $UserId,
		ILogger $logger,
		UserGroupService $externalUserService
			) {
		$this->appManager = $appManager;
		$this->groupManager = $groupManager;
		$this->syncGroupService = $syncGroupService;
		$this->initialState = $initialState;
		$this->appConfig = $appConfig;
		$this->userId = $UserId;
		$this->externalUserService = $externalUserService;
		$this->logger = $logger;
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm() {
		if($this->isSyncUser() == false)
		{
			$this->logger->info('user is not allowed to setup synchronisation');
			return new TemplateResponse('sendentsynchroniser', 'noSyncUser');
		}
		else{
			return new TemplateResponse('sendentsynchroniser', 'permissionForToken');
		}
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

	/**
	 * @return bool
	 */
	private function isSyncUser() : bool
	{					
		$this->logger->error('userid ========= ' .  $this->userId );

		$isSyncUser = false;
		$sendentGroups = $this->syncGroupService->findAll();

		foreach($sendentGroups as $group)
		{
			$this->logger->error('group ========= ' .  $group->getName() );
			$groupusers = $this->externalUserService->GetGroupUsers($group->getName());

			foreach($groupusers as $groupUser)
			{
				foreach($groupUser->users as $user)
				{
					$username = $user->id;
					$this->logger->error('starting processing of user: ' . $username );

					if($user->id == $this->userId)
					{
						$isSyncUser = true;
						$this->logger->error('user is syncuser');
					}
				}
			}
		}
		
		return $isSyncUser;
	}

}
