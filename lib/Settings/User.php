<?php

namespace OCA\SendentSynchroniser\Settings;

use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\Settings\ISettings;
use \Psr\Log\LoggerInterface;
use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Db\SyncUserMapper;

class User implements ISettings {

	/** @var IAppManager */
	private $appManager;

	/** @var IGroupManager */
	private $groupManager;

	/** @var IAppConfig */
	private $appConfig;

	/** @var IInitialState */
	private $initialState;

	/** @var LoggerInterface */
	private $logger;

	/** @var SyncUserMapper */
	private $syncUserMapper;

	/** @var string */
	private $userId;

	public function __construct(
		IAppManager $appManager,
		IGroupManager $groupManager,
		IAppConfig $appConfig,
		IInitialState $initialState,
		LoggerInterface $logger,
		SyncUserMapper $syncUserMapper,
		string $userId) {

		$this->appConfig = $appConfig;
		$this->appManager = $appManager;
		$this->groupManager = $groupManager;
		$this->initialState = $initialState;
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
		$syncUsers = $this->syncUserMapper->findByUid($this->userId);
		$activeUser = !empty($syncUsers) && $syncUsers[0]->getActive() === Constants::USER_STATUS_ACTIVE;

		$this->initialState->provideInitialState('user', [
			'activeUser' => $activeUser,
		]);

		return new TemplateResponse('sendentsynchroniser', 'indexUser');
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
		if ($this->isUserAllowed())
		{
			return 'sendentsynchroniser';
		}
	}

	/**
	 * @return int whether the form should be rather on the top or bottom of
	 * the admin section. The forms are arranged in ascending order of the
	 * priority values. It is required to return a value between 0 and 100.
	 */
	public function getPriority() {
		return 51;
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

		// Is shared secret configured?
		if (empty($this->appConfig->getAppValue('sharedSecret', ''))) {
			return false;
		};

		// Is user member of an active group?
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
