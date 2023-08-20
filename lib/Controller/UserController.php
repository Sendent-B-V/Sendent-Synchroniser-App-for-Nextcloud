<?php

namespace OCA\SendentSynchroniser\Controller;

use OC\Authentication\Events\AppPasswordCreatedEvent;
use OC\Authentication\Exceptions\InvalidTokenException;
use OC\Authentication\Token\IProvider;
use OC\Authentication\Token\IToken;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Authentication\Exceptions\CredentialsUnavailableException;
use OCP\Authentication\Exceptions\PasswordUnavailableException;
use OCP\Authentication\LoginCredentials\IStore;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\ISession;
use OCP\Security\ISecureRandom;
use \OCP\ILogger;
use OCA\SendentSynchroniser\Db\SyncUser;
use OCA\SendentSynchroniser\Db\SyncUserMapper;

class UserController extends Controller {

	/** @var IStore */
	private $credentialStore;

	/** @var IEventDispatcher */
	private $eventDispatcher;

	/** @var IAppConfig */
	private $appConfig;

	/** @var IGroupManager */
	private $groupManager;

	/** @var ILogger */
	private $logger;

	/** @var ISecureRandom */
	private $random;
	
	/** @var ISession */
	private $session;

	/** @var SyncUserMapper */
	private $syncUserMapper;

	/** @var IProvider */
	private $tokenProvider;

	/** @var string */
	private $userId;
	
	public function __construct(ILogger $logger, $AppName, IRequest $request,
		string $userId,
		IAppConfig $appConfig,
		IEventDispatcher $eventDispatcher,
		IGroupManager $groupManager,
		IProvider $tokenProvider,
		IsecureRandom $random,
		ISession $session,
		IStore $credentialStore,
		SyncUserMapper $syncUserMapper) {

		parent::__construct($AppName, $request);
		
		$this->appConfig = $appConfig;
		$this->credentialStore = $credentialStore;
		$this->eventDispatcher = $eventDispatcher;
		$this->groupManager = $groupManager;
		$this->logger = $logger;
		$this->random = $random;
		$this->session = $session;
		$this->syncUserMapper = $syncUserMapper;
		$this->tokenProvider = $tokenProvider;
		$this->userId = $userId;

	}

	/**
	 *
	 * This method activates a user for sendent synchroniser
	 * 
	 * @NoAdminRequired
	 *
 	 * @return DataResponse
	 *
	 */
	public function activate() {
		$this->logger->info('Permit function triggered');

		// We do not allow the creation of new tokens if this is an app password
		if ($this->session->exists('app_password')) {
			$this->logger->error('You cannot request an new apppassword with an apppassword');

			throw new OCSForbiddenException('You cannot request an new apppassword with an apppassword');
		}

		try {
			$credentials = $this->credentialStore->getLoginCredentials();
		} catch (CredentialsUnavailableException $e) {
			$this->logger->error('CredentialsUnavailableException');
			throw new OCSForbiddenException();
		}

		try {
			$password = $credentials->getPassword();
		} catch (PasswordUnavailableException $e) {
			$password = null;
			$this->logger->error('password is null');
		}

		// Generates an app token for Sendent synchroniser
		$token = $this->random->generate(72, ISecureRandom::CHAR_UPPER.ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_DIGITS);
		$generatedToken = $this->tokenProvider->generateToken(
			$token,
			$credentials->getUID(),
			$credentials->getLoginName(),
			$password,
			'SendentSynchroniser',
			IToken::PERMANENT_TOKEN,
			IToken::DO_NOT_REMEMBER
		);

		$this->logger->debug('Token created and is: ' . $token);

		$this->eventDispatcher->dispatchTyped(
			new AppPasswordCreatedEvent($generatedToken)
		);

		// Stores syncUser info
		$syncUsers = $this->syncUserMapper->findByUid($credentials->getUID());
		if (empty($syncUsers)) {
			$syncUser = new SyncUser;
			$syncUser->setUid($credentials->getUID());
			$syncUser->setActive(1);	
			$this->syncUserMapper->insert($syncUser);
		} else {
			$syncUsers[0]->setActive(1);	
			$this->syncUserMapper->update($syncUsers[0]);
		}
		
		return new DataResponse(True);
	}

	/**
	 *
	 * This method tells if the current user is a valid Sendent synchroniser user. 
	 * 
	 * @NoAdminRequired
	 *
 	 * @return DataResponse
	 *
	 */
	public function isValid() {

		$this->logger->info('Checking validity of user ' . $this->userId);

		// Checks if user is member of an active group
		$activeGroups = $this->appConfig->getAppValue('activeGroups', '');
		$activeGroups = ($activeGroups !== '' && $activeGroups !== 'null') ? json_decode($activeGroups) : [];
		foreach ($activeGroups  as $gid) {
			if ($this->groupManager->isInGroup($this->userId, $gid)) {
				// User is member of an active group, let's find if he's valid
				$syncUsers = $this->syncUserMapper->findByUid($this->userId);
				if (!empty($syncUsers)) {
					if ($syncUsers[0]->getActive()) {
						$this->logger->info('User ' . $this->userId . ' is valid');
						return new DataResponse(TRUE);
					} else {
						$this->logger->info('User ' . $this->userId . ' is not valid');
						return new DataResponse(FALSE);
					}
				} else {
					// User has never setup sync
					$this->logger->info('User ' . $this->userId . ' has not setup sync yet');
					return new DataResponse(FALSE);
				}		
			}
		};

		// User is not member of an active group, let's pretend it is valid so the Sendent synchroniser modal is not shown
		return new DataResponse(TRUE);

	}

	/**
	 * 
	 * This method invalidates a user. It shall be called by the Sendent synchroniser
	 * (external) service to trigger the display of the "synchronisation problem" warning
	 * dialog to the user.
	 * 
	 * @NoCSRFRequired
	 * 
	 */
	public function invalidate(string $userId) {

		$this->logger->info('Invalidating user ' . $userId);

		$syncUsers = $this->syncUserMapper->findByUid($userId);

		$response = [];

		if (empty($syncUsers)) {
			$this->logger->warning('User ' . $userId . ' does not exxist');
			$response = [
				'status' => 'error',
				'message' => 'user does not exist'
			];
		} else {
			$syncUsers[0]->setActive(0);	
			$this->syncUserMapper->update($syncUsers[0]);
			$response = [
				'status' => 'success',
				'message' => 'user ' . $userId . ' invalidated'
			];
		}

		return new JSONResponse($response);

	}
}
