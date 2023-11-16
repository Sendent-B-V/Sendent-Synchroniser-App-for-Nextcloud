<?php

namespace OCA\SendentSynchroniser\Service;

use OCP\AppFramework\Services\IAppConfig;
use OC\Authentication\Token\IProvider;
use OCP\IGroupManager;
use OCP\IUserManager;
use \OCP\ILogger;
use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Db\SyncUserMapper;

class SyncUserService {

	/** @var string */
	private $appName;

	/** @var AppConfig */
	private $appConfig;

	/** @var IGroupManager */
	private $groupManager;

	/** @var ILogger */
	private $logger;

	/** @var IProvider */
	private $tokenProvider;

	/** @var SyncUserMapper */
	private $syncUserMapper;

	/** @var IUserManager */
	private $userManager;

    public function __construct($AppName, IAppConfig $appConfig, IGroupManager $groupManager, ILogger $logger,
		Iprovider $tokenProvider, IUserManager $userManager, SyncUserMapper $syncUserMapper) {

		$this->appName = $AppName;
		$this->appConfig = $appConfig;
		$this->groupManager = $groupManager;
        $this->logger = $logger;
        $this->tokenProvider = $tokenProvider;
        $this->syncUserMapper = $syncUserMapper;
		$this->userManager = $userManager;

	}

	/*
	 *
	 * This method invalidate a user
	 *
	*/
    public function invalidateUser(string $userId, int $retractConsent = Constants::USER_STATUS_INACTIVE) {

        $this->logger->info('Invalidating user ' . $userId);
        $response = [];

        $syncUsers = $this->syncUserMapper->findByUid($userId);

        if (empty($syncUsers)) {
			$this->logger->warning('User ' . $userId . ' does not exist');
			$response = [
				'status' => 'error',
				'message' => 'user does not exist'
			];
		} else {
		    // Invalidates existing app tokens
		    $existingTokens = $this->tokenProvider->getTokenByUser($userId);
			foreach($existingTokens as $token) {
				if ( $token->getName() === $this->appName) {
					$this->tokenProvider->invalidateTokenById($token->getUid(), $token->getId());
				}
			}
            // Set user status to invalid
            $syncUsers[0]->setActive($retractConsent);
            $this->syncUserMapper->update($syncUsers[0]);

			$response = [
				'status' => 'success',
				'message' => 'user ' . $userId . ' invalidated'
			];
		}

		return $response;

	}

	public function getInvalidUsers() {

		// Gets active groups
		$activeGroups = $this->appConfig->getAppValue('activeGroups', '');
		$activeGroups = ($activeGroups !== '' && $activeGroups !== 'null') ? json_decode($activeGroups) : [];

		// Gets all users in active groups
		$users = [];
		foreach ($activeGroups as $gid) {
			$group = $this->groupManager->get($gid);
			$users = array_merge($users,$group->getUsers());
		}

		// Gets all inactive sendent sync users
		$inactiveUsers = [];
		foreach ($users as $user) {
			$syncUsers = $this->syncUserMapper->findByUid($user->getUid());
			if (!empty($syncUsers)) {
				if ($syncUsers[0]->getActive() === Constants::USER_STATUS_INACTIVE) {
					// Makes sure we don't create duplicates
					if(!array_key_exists($syncUsers[0]->getUid(), $inactiveUsers)) {
						$inactiveUsers[$syncUsers[0]->getUid()] = $syncUsers[0];
					}
				}
			}
		}

		return $inactiveUsers;

	}

	public function getValidUsers() {

		// Gets active groups
		$activeGroups = $this->appConfig->getAppValue('activeGroups', '');
		$activeGroups = ($activeGroups !== '' && $activeGroups !== 'null') ? json_decode($activeGroups) : [];

		// Gets all users in active groups
		$users = [];
		foreach ($activeGroups as $gid) {
			$group = $this->groupManager->get($gid);
			$users = array_merge($users,$group->getUsers());
		}
		$index = 0;
		// Gets all active sendent sync users
		$activeUsers = [];
		foreach ($users as $user) {
			$syncUsers = $this->syncUserMapper->findByUid($user->getUid());
			if (!empty($syncUsers)) {
				$syncUser = $syncUsers[0];
				if ($syncUser->getActive() === Constants::USER_STATUS_ACTIVE) {
					// Makes sure we don't create duplicates
					if(!array_key_exists($syncUser->getUid(), $activeUsers)) {
						// Augments syncUser with some info from the corresponding NC user
						$NCUser = $this->userManager->get($syncUser->getUid());
						$user = $syncUser->jsonSerialize();
						$user['username'] = $user['uid'];
						$user['uid'] = $NCUser->getUID();
						$user['email'] = $NCUser->getEmailAddress();
						$user['displayName'] = $NCUser->getDisplayName();
						//this method replaces the mechanism with named array indexes because C# cannot deal with that.
						if(!$this->checkIfUserInArray($activeUsers, $syncUser->getUid()))
						{
							$activeUsers[$index] = $user;
							$index++;
						}
					}
				}
			}
		}

		return $activeUsers;

	}

	//this method replaces the mechanism with named array indexes because C# cannot deal with that.
	private function checkIfUserInArray($array, $id) : bool
	{
		$found = false;
		foreach($array as $user)
		{
			if($user['uid'] == $id)
			{
				$this->logger->info('Duplicate found for user: ' . $user['displayName']);
				$found = true;
				break;
			}
		}
		return $found;
	}

}