<?php

namespace OCA\SendentSynchroniser\Settings;

use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\Settings\ISettings;
use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Service\SyncUserService;

class Admin implements ISettings {

	/** @var IAppManager */
	private $appManager;

	/** @var IGroupManager */
	private $groupManager;

	/** @var IInitialState */
	private $initialState;

	/** @var IAppConfig */
	private $appConfig;

	/** @var SyncUserService */
	private $syncUserService;

	public function __construct(
		IAppManager $appManager,
		IGroupManager $groupManager,
		IInitialState $initialState,
		IAppConfig $appConfig,
		SyncUserService $syncUserService) {

		$this->appConfig = $appConfig;
		$this->appManager = $appManager;
		$this->groupManager = $groupManager;
		$this->initialState = $initialState;
		$this->syncUserService = $syncUserService;

	}

	private function getParams() {

		$nbEnabledUsers = [];	// Users for which Sendent Synchroniser is enabled

		// Gets groups used in the app
		$sendentGroups = $this->appConfig->getAppValue('activeGroups', '');
		$sendentGroups = ($sendentGroups !== '' && $sendentGroups !== 'null') ? json_decode($sendentGroups) : [];
		$sendentGroups = array_map(function ($gid) {
			$group = $this->groupManager->get($gid);
			if (!is_null($group)) {
				return array(
					"displayName" => $group->getDisplayName(),
					"gid" => $group->getGid()
				);
			} else {
				return array(
					"displayName" => $gid->getName() . ' *** DELETED GROUP ***',
					"gid" => $gid->getName()
				);
			}
		}, $sendentGroups);

		// Counts all enabled users
		foreach($sendentGroups as $sendentGroup) {
			$group = $this->groupManager->get($sendentGroup['gid']);
			if (!is_null($group)) {
				$groupUsers = $group->getUsers();
				foreach($groupUsers as $user) {
					if(!array_key_exists($user->getUID(), $nbEnabledUsers)) {
						$nbEnabledUsers[$user->getUID()] = $user->getUID();
					}
				}
			}
		}
		$nbEnabledUsers = count($nbEnabledUsers);

		// Gets all Nextcloud groups
		$NCGroups = $this->groupManager->search('');
		$NCGroups = array_map(function ($group) {
			return array(
				"displayName" => $group->getDisplayName(),
				"gid" => $group->getGid()
			);
		}, $NCGroups);

		// Removes sendentGroups from all Nextcloud groups
		$NCGroups = array_udiff($NCGroups, $sendentGroups, function($g1, $g2) {
			return strcmp($g1['gid'], $g2['gid']);
		});

		$params['ncGroups'] = $NCGroups;
		$params['sendentGroups'] = $sendentGroups;
		$params['nbEnabledUsers'] = $nbEnabledUsers;
		$params['nbActiveUsers'] = count($this->syncUserService->getValidUsers());

		$params['reminderType'] = $this->appConfig->getAppValue('reminderType', Constants::REMINDER_DEFAULT_TYPE);
		$params['notificationInterval'] = $this->appConfig->getAppValue('notificationInterval', Constants::REMINDER_NOTIFICATIONS_DEFAULT_INTERVAL);
		$params['notificationMethod'] = $this->appConfig->getAppValue('notificationMethod', Constants::NOTIFICATIONMETHOD_MODAL_DEFAULT);
		$params['sharedSecret'] = $this->appConfig->getAppValue('sharedSecret', '');
		$params['IMAPSyncEnabled'] = ($this->appConfig->getAppValue('IMAPSyncEnabled', 'false') === 'true');
		$params['emailDomain'] = $this->appConfig->getAppValue('emailDomain', '') ;
		$params['mailAppInstalled'] = $this->appManager->isInstalled('mail');
		$params['notificationsAppInstalled'] = $this->appManager->isInstalled('notifications');

		return $params;
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm() {
		$params = $this->getParams();

		return new TemplateResponse('sendentsynchroniser', 'indexAdmin', $params);
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
		return 51;
	}
}
