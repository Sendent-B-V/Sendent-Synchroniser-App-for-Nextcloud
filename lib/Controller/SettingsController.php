<?php

namespace OCA\SendentSynchroniser\Controller;

use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\Notification\IManager;
use \Psr\Log\LoggerInterface;
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

	/** @var LoggerInterface */
	private $logger;

	/** @var IManager */
	private $notificationManager;

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
		LoggerInterface $logger,
		IManager $notificationManager,
		SyncUserMapper $syncUserMapper,
		SyncUserService $syncUserService) {

 		parent::__construct($appName, $request);

		$this->appConfig = $appConfig;
		$this->groupManager = $groupManager;
		$this->logger = $logger;
		$this->notificationManager = $notificationManager;
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
		return $this->appConfig->setAppValue('sharedSecret', $sharedSecret);
	}

	/**
	 * 
	 * Saves reminder type
	 * 
	 * @param string $reminderType
	 * 
	 */
	public function setReminderType($reminderType) {
		return $this->appConfig->setAppValue('reminderType', $reminderType);
	}

	/**
	 *
	 * Gets reminder type
	 *
	 * @param string $reminderType
	 *
	 */
	public function getReminderType() {
		return $this->appConfig->getAppValue('reminderType', Constants::REMINDER_DEFAULT_TYPE);
	}

	/**
	 * 
	 * Saves notification method
	 * 
	 * @param string $notificationMethod
	 * 
	 */
	public function setNotificationMethod($notificationMethod) {
		return $this->appConfig->setAppValue('notificationMethod', $notificationMethod);
	}

	/**
	 *
	 * Gets notification method
	 *
	 * @param string $notificationMethod
	 *
	 * @NoAdminRequired
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
		if (ctype_digit($notificationInterval)) {
			$this->appConfig->setAppValue('notificationInterval', $notificationInterval);
			return TRUE;
		} else {
			return FALSE;
		}
	}

	/**
	 *
	 * Saves IMAPSync setting
	 *
	 * @param string $IMAPSyncEnabled
	 *
	 */
	public function setIMAPSync($IMAPSyncEnabled) {
		return $this->appConfig->setAppValue('IMAPSyncEnabled', $IMAPSyncEnabled);
	}

	/**
	 *
	 * Saves emailDomain setting
	 *
	 * @param string $emailDomain
	 *
	 */
	public function setEmailDomain($emailDomain) {
		return $this->appConfig->setAppValue('emailDomain', $emailDomain);
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
		return $this->appConfig->setAppValue('activeGroups', json_encode($newSendentGroups));

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

	/**
	 *
	 * Sends notifications to inactive users
	 *
	 */
	public function sendReminder() {

		// Is shared secret configured?
		if (empty($this->appConfig->getAppValue('sharedSecret', ''))) {
			$this->logger->info('Not sending notifications as sharedSecret is not configured');
			return;
		};

		// TODO: Check licensing?

		// Gets list of invalid users (users who have opt out of sendent sync are not counted as invalid)
		$inactiveUsers = $this->syncUserService->getInvalidUsers();

		// Defers sending notifications to avoid multiple connections to the server
		$shouldFlush = $this->notificationManager->defer();

		// Prepare notifications for all invalid users
		foreach ($inactiveUsers as $inactiveUser) {
			$this->logger->info('Sending notification to user ' . $inactiveUser->getUid());
			$notification = $this->notificationManager->createNotification();
			$notification->setApp('sendentsynchroniser')
				->setUser($inactiveUser->getUid())
				->setDateTime(new \DateTime())
				->setObject('settings', 'admin')
				->setSubject('Please activate your Exchange synchronisation');
			$this->notificationManager->notify($notification);
		}

		// Sends notifications (if no other app is already deferring)
		if ($shouldFlush) {
			$this->notificationManager->flush();
		}

		$this->logger->info('Sent notification to ' . count($inactiveUsers) . ' user(s)');

		return;
	}

}
