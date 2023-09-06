<?php

namespace OCA\SendentSynchroniser\Service;

use OC\Authentication\Token\IProvider;
use \OCP\ILogger;
use OCA\SendentSynchroniser\Db\SyncUserMapper;

class SyncUserService {

	/** @var ILogger */
	private $logger;

	/** @var IProvider */
	private $tokenProvider;

	/** @var SyncUserMapper */
	private $syncUserMapper;

    public function __construct(ILogger $logger, Iprovider $tokenProvider, SyncUserMapper $syncUserMapper) {
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

}