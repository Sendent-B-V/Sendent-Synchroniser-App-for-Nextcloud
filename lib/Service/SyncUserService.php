<?php

namespace OCA\SendentSynchroniser\Service;

use OCP\AppFramework\Services\IAppConfig;
use OC\Authentication\Token\IProvider;
use OCP\IGroupManager;
use \OCP\ILogger;
use OCA\SendentSynchroniser\Db\SyncUserMapper;

class SyncUserService {

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

    public function __construct(IAppConfig $appConfig, IGroupManager $groupManager, ILogger $logger,
		Iprovider $tokenProvider, SyncUserMapper $syncUserMapper) {

		$this->appConfig = $appConfig;
		$this->groupManager = $groupManager;
        $this->logger = $logger;
        $this->tokenProvider = $tokenProvider;
        $this->syncUserMapper = $syncUserMapper;

	}

    public function invalidateUser(string $userId) {

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
            $syncUsers[0]->setActive(0);
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
		$activeUsers = [];
		foreach ($users as $user) {
			$syncUsers = $this->syncUserMapper->findByUid($user->getUid());
			if (!empty($syncUsers)) {
				if (!$syncUsers[0]->getActive()) {
					// Makes sure we don't create duplicates
					if(!array_key_exists($syncUsers[0]->getUid(), $activeUsers)) {
						$activeUsers[$syncUsers[0]->getUid()] = $syncUsers[0];
					}
				}
			}
		}

		return $inactiveUsers;

	}

}