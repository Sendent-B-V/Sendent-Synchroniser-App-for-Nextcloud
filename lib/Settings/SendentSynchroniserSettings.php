<?php

namespace OCA\SendentSynchroniser\Settings;

use OCA\SendentSynchroniser\Service\SyncGroupService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroupManager;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;

class SendentSynchroniserSettings implements ISettings {

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

	public function __construct(
		IAppManager $appManager,
		IGroupManager $groupManager,
		SyncGroupService $syncGroupService,
		IInitialState $initialState,
		IAppConfig $appConfig
			) {
		$this->appManager = $appManager;
		$this->groupManager = $groupManager;
		$this->syncGroupService = $syncGroupService;
		$this->initialState = $initialState;
		$this->appConfig = $appConfig;
	}

	/**
	 * Returns 2 lists of groups:
	 * 	1- All Nextcloud groups except the groups in the second list;
	 * 	2- All Nextcloud groups that are used in for our group settings
	 */
	private function initializeGroups() {

		// Gets groups used in the app
		$sendentGroups = $this->syncGroupService->findAll();
		$sendentGroups = array_map(function ($gid) {
			error_log(print_r("evaluating syncGroup:" . $gid->getName(), true));
			$group = $this->groupManager->get($gid->getName());
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

		return $params;
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm() {
		$params = $this->initializeGroups();

		return new TemplateResponse('sendentsynchroniser', 'index', $params);
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
