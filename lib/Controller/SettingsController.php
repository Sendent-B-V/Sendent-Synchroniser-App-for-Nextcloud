<?php

namespace OCA\SendentSynchroniser\Controller;

use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use \OCP\ILogger;
use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Db\SyncUserMapper;
use OCA\SendentSynchroniser\Service\SyncUserService;

class SettingsController extends ApiController {

	/** @var string */
	private $userId;

	/** @var IAppConfig */
	private $appConfig;

	/** @var IGroupManager */
	private $groupManager;

	/** @var ILogger */
	private $logger;

	/** @var IRequest */
	protected $request;

	/** @var SyncUserMapper */
	private $syncUserMapper;

	/** @var SyncUserService */
	private $syncUserService;

	public function __construct($appName, IRequest $request,
		string $userId,
		IAppConfig $appConfig,
		IGroupManager $groupManager,
		ILogger $logger,
		SyncUserMapper $syncUserMapper,
		SyncUserService $syncUserService) {

 		parent::__construct($appName, $request);

		$this->appConfig = $appConfig;
		$this->groupManager = $groupManager;
		$this->logger = $logger;
		$this->request = $request;
		$this->syncUserMapper = $syncUserMapper;
		$this->syncUserService = $syncUserService;
		$this->userId = $userId;

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
	 * Gets reminder type
	 *
	 * @param string $reminderType
	 *
	 */
	public function getReminderType() {
		$this->appConfig->getAppValue('reminderType', Constants::REMINDER_DEFAULT_TYPE);
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
		return $this->appConfig->getAppValue('notificationMethod', Constants::NOTIFICATIONMETHOD_MODAL_DEFAULT);
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

	/**
	 *
	 * This method tells if the modal dialog should be shown
	 *
	 * The following conditions must be met for the modal dialog to be shown:
	 *
	 * 1- The shared secret to encrypt user tokens must be set
	 * 2- The administrator must have asked for the display of the modal dialog
	 * 3- We haven't shown the dialog for a certain amount of time ('sendentsynchroniser_activationreminder_timeout' cookie)
	 * 4- The license must be valid
	 * 5- The user must be member of an active group
	 * 6- The user must be inactive (Users that have retracted their consent count as active).
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 */
	public function shouldShowDialog() {

		// Is shared secret configured?
		if (empty($this->appConfig->getAppValue('sharedSecret', ''))) {
			return new JSONResponse(FALSE);
		};

		// Did administrators ask for the modal dialog to be shown?
		if ($this->appConfig->getAppValue('reminderType', Constants::REMINDER_DEFAULT_TYPE) === Constants::REMINDER_NOTIFICATIONS) {
			return new JSONResponse(FALSE);
		};

		$cookie = $this->request->getCookie('sendentsynchroniser_activationreminder_timeout');

		if (!is_null($cookie)) {
			return new JSONResponse(FALSE);
		};

		// TODO: Verify license

		// Checks if user is member of an active group
		$activeGroups = $this->appConfig->getAppValue('activeGroups', '');
		$activeGroups = ($activeGroups !== '' && $activeGroups !== 'null') ? json_decode($activeGroups) : [];
		foreach ($activeGroups  as $gid) {
			if ($this->groupManager->isInGroup($this->userId, $gid)) {
				// User is member of an active group, let's find if he's active
				$syncUsers = $this->syncUserMapper->findByUid($this->userId);
				if (!empty($syncUsers)) {
					if ($syncUsers[0]->getActive() === Constants::USER_STATUS_ACTIVE || $syncUsers[0]->getActive() === Constants::USER_STATUS_NOCONSENT) {
						return new JSONResponse(FALSE);
					} else {
						return new JSONResponse(TRUE);
					}
				} else {
					// User has never setup sync
					return new JSONResponse(TRUE);
				}
			}
		};

		// User is not member of an active group
		return new JSONResponse(FALSE);

	}

}
