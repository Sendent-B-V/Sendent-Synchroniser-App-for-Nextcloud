<?php

namespace OCA\SendentSynchroniser\Service;

use OCP\Accounts\IAccountManager;
use OCP\AppFramework\Services\IAppConfig;
use OC\Authentication\Token\IProvider;
use OCP\IGroupManager;
use OCP\IUserManager;
use \Psr\Log\LoggerInterface;
use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Db\SyncUserMapper;

class SyncUserService {

	/** @var IAccountManager */
	private $accountManager;

	/** @var string */
	private $appName;

	/** @var AppConfig */
	private $appConfig;

	/** @var IGroupManager */
	private $groupManager;

	/** @var LoggerInterface */
	private $logger;

	/** @var IProvider */
	private $tokenProvider;

	/** @var SyncUserMapper */
	private $syncUserMapper;

	/** @var IUserManager */
	private $userManager;

    public function __construct(IAccountManager $accountManager, $AppName, IAppConfig $appConfig, IGroupManager $groupManager, LoggerInterface $logger,
		Iprovider $tokenProvider, IUserManager $userManager, SyncUserMapper $syncUserMapper) {

		$this->accountManager = $accountManager;
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

	/**
	 * 
	 * This function returns all the users that may use the app
	 * 
	*/
	public function getAllUsers() {
		// Gets active groups
		$activeGroups = $this->appConfig->getAppValue('activeGroups', '');
		$activeGroups = ($activeGroups !== '' && $activeGroups !== 'null') ? json_decode($activeGroups) : [];

		// Gets all users in active groups
		$users = [];
		foreach ($activeGroups as $gid) {
			$group = $this->groupManager->get($gid);
			$users = array_merge($users,$group->getUsers());
		}

		return $users;
	}

	/**
	 * 
	 * This function returns all inactive users that may use the app
	 * 
	*/
	public function getInvalidUsers() {

		$users = $this->getAllUsers();

		// Gets only inactive sendent sync users
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

	public function getValidUserCount() {

		$users = $this->getAllUsers();

		// Gets only active sendent sync users
		$index = 0;
		$activeUsers = [];
		foreach ($users as $user) {
			$syncUsers = $this->syncUserMapper->findByUid($user->getUid());
			if (!empty($syncUsers)) {
				$syncUser = $syncUsers[0];
				if ($syncUser->getActive() === Constants::USER_STATUS_ACTIVE) {
					// Makes sure we don't create duplicates
					if(!array_key_exists($syncUser->getUid(), $activeUsers)) {
							$activeUsers[$index] = $user;
							$index++;
					}
				}
			}
		}

		return $index;

	}

	/**
	 * 
	 * This function returns all active users that may use the app
	 * 
	*/
	public function getValidUsers() {

		$users = $this->getAllUsers();

		// Gets all active sendent sync users
		$index = 0;
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
						$username = $syncUser->getUsername();
						if($username === null || $username === ''){
							$user['username'] = $user['uid'];
						} else {
							$user['username'] = $username;
						}
						$user['uid'] = $NCUser->getUID();
						$user['email'] = $NCUser->getEmailAddress();	// default email address
						// Replaces email address by one of the user email addresses that matches the sync domain (if any)
						$emailDomain = $this->appConfig->getAppValue('emailDomain', '');
						if ($emailDomain !== '') {
							$account = $this->accountManager->getAccount($NCUser);
							$email = $account->getProperty(IAccountManager::PROPERTY_EMAIL);
							$emailAddress = $email->getValue();
							if (substr($emailAddress, -strlen($emailDomain)) === $emailDomain) {
								$user['email'] = $email->getValue();
							} else {
								$emailsCollection = $account->getPropertyCollection(IAccountManager::COLLECTION_EMAIL);
								foreach($emailsCollection->getProperties() as $email)	{
									$emailAddress = $email->getValue();
									if (substr($emailAddress, -strlen($emailDomain)) === $emailDomain) {
										$user['email'] = $email->getValue();
									}
								}
							}
						}
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