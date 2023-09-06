<?php

namespace OCA\SendentSynchroniser\Controller;

use OCP\AppFramework\ApiController;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use \OCP\ILogger;
use OCA\SendentSynchroniser\Db\SyncUserMapper;
use OCA\SendentSynchroniser\Service\SyncUserService;

class SettingsController extends ApiController {

	/** @var IAppConfig */
	private $appConfig;

	/** @var IGroupManager */
	private $groupManager;

	/** @var ILogger */
	private $logger;

	/** @var SyncUserMapper */
	private $syncUserMapper;

	/** @var SyncUserService */
	private $syncUserService;

	public function __construct($appName, IRequest $request,
		IAppConfig $appConfig,
		IGroupManager $groupManager,
		ILogger $logger,
		SyncUserMapper $syncUserMapper,
		SyncUserService $syncUserService) {

 		parent::__construct($appName, $request);

		$this->appConfig = $appConfig;
		$this->groupManager = $groupManager;
		$this->logger = $logger;
		$this->syncUserMapper = $syncUserMapper;
		$this->syncUserService = $syncUserService;

 	}

  	/**
	 * 
	 * Saves shared secret
	 * 
	 * @param string $sharedSecret
	 * 
	 */
	public function setSharedSecret($sharedSecret) {
		$this->syncUserMapper->encryptAllUserstoken($sharedSecret);
		$this->appConfig->setAppValue('sharedSecret', $sharedSecret);
		return;
	}

	/**
	 * 
	 * Saves reminder type
	 * 
	 * @param string $reminderType
	 * 
	 */
	public function setReminderType($reminderType) {
		$this->appConfig->setAppValue('reminderType', $reminderType);
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
	 * Saves notification interval
	 * 
	 * @param string $notificationInterval
	 * 
	 */
	public function setNotificationInterval($notificationInterval) {
		$this->appConfig->setAppValue('notificationInterval', $notificationInterval);
		return;
	}

	/**
	 * 
	 * Saves new active groups list
	 * 
	 * @param string $newSendentGroups
	 * 
	 */
	public function setActiveGroups($newSendentGroups) {

		// Finds deleted group, if any
		$sendentGroups = $this->appConfig->getAppValue('activeGroups', '');
		$sendentGroups = $sendentGroups !== '' ? json_decode($sendentGroups) : [];
		$deletedGroup = array_diff($sendentGroups, $newSendentGroups);

		// Invalidate users of the deleted group if they are not member of any other active groups
		if (count($deletedGroup) > 0) {
			$gid = $deletedGroup[array_keys($deletedGroup)[0]];
			$ncGroup = $this->groupManager->get($gid);
			foreach($ncGroup->getUsers() as $user) {
				$active = FALSE;
				// Finds if user is still in another active group
				foreach($this->groupManager->getUserGroups($user) as $userGroup) {
					if (in_array($userGroup->getGID(), $newSendentGroups)) {
						// User is still in another active group
						$active = TRUE;
						break;
					}
				}
				// Invalidates user if not member of another active group
				if (!$active) {
					$this->syncUserService->invalidateUser($user->getUID());
				}
			}
		}

		// Saves new active groups list
		$this->appConfig->setAppValue('activeGroups', json_encode($newSendentGroups));

		return;
	}

}
