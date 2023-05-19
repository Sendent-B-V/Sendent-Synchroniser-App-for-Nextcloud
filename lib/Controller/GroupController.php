<?php

 namespace OCA\SendentSynchroniser\Controller;

 use OCP\IRequest;
 use OCP\AppFramework\Http\DataResponse;
 use OCP\AppFramework\ApiController;
 use OC\Authentication\Token\IProvider;
 use OC\Authentication\Token\IToken;
use OCA\SendentSynchroniser\Service\Entities\AppPasswordItem;
use OCP\IUserManager;
 use OCP\Security\ICrypto;
 use OCP\Security\ISecureRandom;
 use \OCP\ILogger;
 use OCA\SendentSynchroniser\Service\SyncGroupService;
 use OCA\SendentSynchroniser\Service\UserGroupService;

 class GroupController extends ApiController {
	private $externalUserService;
	private $syncGroupService;

	/** @var IUserManager */
	protected $userManager;
	/** @var IProvider */
	protected $tokenProvider;
	/** @var ISecureRandom */
	private $random;
	/** @var ICrypto */
	private $crypto;
	private $logger;

	public function __construct(ILogger $logger, $appName, IUserManager $userManager,
	 IProvider $tokenProvider,
	 ISecureRandom $random,
	 ICrypto $crypto,
	 IRequest $request,
	 UserGroupService $externalUserService,
	 SyncGroupService $syncGroupService) {
 		parent::__construct($appName, $request);
 		$this->externalUserService = $externalUserService;
		 $this->tokenProvider = $tokenProvider;
		$this->userManager = $userManager;
		$this->syncGroupService = $syncGroupService;
		$this->random = $random;
		$this->crypto = $crypto;
		$this->logger = $logger;
 	}

	/**
 	 * @NoCSRFRequired
     * @param string $groupid
 	 * @return DataResponse
 	 */
	public function generateAppPasswordsForGroup($groupid)
	{
		$groupusers = $this->externalUserService->GetGroupUsers($groupid);
		$usersInGroup = $groupusers[0]->users;
		$arrayResults = array();

		foreach($usersInGroup as $groupUser)
		{
			$username = $groupUser->id;
		$user = $this->userManager->get($username);
		$this->logger->error('starting processing of user: ' . $username );

		if (is_null($user)) {
			array_push($arrayResults, 'user is null: ' . $username);
			$this->logger->error('username: ' . $username . ' cannot get user!');
			return new DataResponse($arrayResults);
		}
		else{
			$token = $this->random->generate(72, ISecureRandom::CHAR_UPPER.ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_DIGITS);
			$generatedToken = $this->tokenProvider->generateToken(
				$token,
				$user->getUID(),
				$user->getDisplayName(),
				'',
				'apppasswordTest from controller',
				IToken::PERMANENT_TOKEN,
				IToken::DO_NOT_REMEMBER
			);

			$this->logger->error($username .' <-- user & token -->' .  $token);
			$appPasswordItem = new AppPasswordItem();
			$appPasswordItem->userEmail = $user->GetEmailAddress();
			$appPasswordItem->userId = $user->GetUID();
			$appPasswordItem->username = $user->GetDisplayName();
			$appPasswordItem->usergroup = $groupid;
			$appPasswordItem->password = $token;
			array_push($arrayResults, $appPasswordItem);
		}

		}
		return new DataResponse($arrayResults);
	}
	/**
 	 * @NoCSRFRequired
     * @param string $username
 	 * @return DataResponse
 	 */
	  public function generateAppPasswordsForUser($username)
	  {
		  
		  $arrayResults = array();

		  $user = $this->userManager->get($username);
		  $this->logger->error('starting processing of user: ' . $username );
  
		  if (is_null($user)) {
			  array_push($arrayResults, 'user is null: ' . $username);
			  $this->logger->error('username: ' . $username . ' cannot get user!');
			  return new DataResponse($arrayResults);
		  }
		  else{
			$token = $this->random->generate(72, ISecureRandom::CHAR_UPPER.ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_DIGITS);
			$generatedToken = $this->tokenProvider->generateToken(
				$token,
				$user->getUID(),
				$user->getDisplayName(),
				'',
				'apppasswordTest from controller',
				IToken::PERMANENT_TOKEN,
				IToken::DO_NOT_REMEMBER
			);
			$this->logger->error($username .' <-- user & token -->' .  $token);
			$appPasswordItem = new AppPasswordItem();
			$appPasswordItem->userEmail = $user->GetEmailAddress();
			$appPasswordItem->userId = $user->GetUID();
			$appPasswordItem->username = $user->GetDisplayName();
			$appPasswordItem->password = $token;
			array_push($arrayResults, $appPasswordItem);
		  }
  
		  
		  return new DataResponse($arrayResults);
	  }

 	/**
 	 * @NoAdminRequired
 	 * @NoCSRFRequired
 	 * @return DataResponse
 	 */
 	public function getExternalGroups(): DataResponse {
 		return new DataResponse($this->externalUserService->getGroups());
 	}
	/**
 	 * @NoAdminRequired
 	 * @NoCSRFRequired
 	 * @return DataResponse
 	 */
	  public function getSyncGroups(): DataResponse {
		return new DataResponse($this->syncGroupService->findAll());
	}
 	/**
 	 * @NoAdminRequired
 	 * @NoCSRFRequired
 	 * @param string $groupid
 	 * @return DataResponse
 	 */
	  public function getExternalGroupUsers(string $groupid): DataResponse {
		return new DataResponse($this->externalUserService->GetGroupUsers($groupid));
	}
}
