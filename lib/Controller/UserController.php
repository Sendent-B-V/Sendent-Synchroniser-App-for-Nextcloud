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
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use \OCP\ILogger;
use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Db\SyncUser;
use OCA\SendentSynchroniser\Db\SyncUserMapper;
use OCA\SendentSynchroniser\Service\SyncUserService;

class UserController extends Controller {

	/** @var IStore */
	private $credentialStore;

	/** @var IEventDispatcher */
	private $eventDispatcher;

	/** @var IAppConfig */
	private $appConfig;

	/** @var string */
	protected $appName;

	/** @var IGroupManager */
	private $groupManager;

	/** @var ILogger */
	private $logger;

	/** @var IProvider */
	private $tokenProvider;

	/** @var ISecureRandom */
	private $random;
	
	/** @var ISession */
	private $session;

	/** @var SyncUserMapper */
	private $syncUserMapper;

	/** @var SyncUserService */
	private $syncUserService;

	/** @var IUserManager */
	private $userManager;
	
	public function __construct(ILogger $logger, $AppName, IRequest $request,
		IAppConfig $appConfig,
		IEventDispatcher $eventDispatcher,
		IGroupManager $groupManager,
		IProvider $tokenProvider,
		IsecureRandom $random,
		ISession $session,
		IStore $credentialStore,
		IUserManager $userManager,
		SyncUserMapper $syncUserMapper,
		SyncUserService $syncUserService) {

		parent::__construct($AppName, $request);
		
		$this->appConfig = $appConfig;
		$this->appName = $AppName;
		$this->credentialStore = $credentialStore;
		$this->eventDispatcher = $eventDispatcher;
		$this->groupManager = $groupManager;
		$this->logger = $logger;
		$this->random = $random;
		$this->session = $session;
		$this->syncUserMapper = $syncUserMapper;
		$this->syncUserService = $syncUserService;
		$this->tokenProvider = $tokenProvider;
		$this->userManager = $userManager;

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
			$uid = $credentials->getUID();
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

		// Invalidates existing app token
		$this->syncUserService->invalidateUser($uid);

		// Generates an app token for Sendent synchroniser
		$token = $this->random->generate(72, ISecureRandom::CHAR_UPPER.ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_DIGITS);
		$generatedToken = $this->tokenProvider->generateToken(
			$token,
			$uid,
			$credentials->getLoginName(),
			$password,
			$this->appName,
			IToken::PERMANENT_TOKEN,
			IToken::DO_NOT_REMEMBER
		);

		$this->eventDispatcher->dispatchTyped(
			new AppPasswordCreatedEvent($generatedToken)
		);

		// Encrypt token using sendent sync shared secret
		// TODO: This should not work when sharedSecret isn't set
		$sharedSecret = $this->appConfig->getAppValue('sharedSecret', '');
		$key = hash('md5', $sharedSecret);
		$ivlen = openssl_cipher_iv_length($cipher="AES-256-CBC");
		$iv = openssl_random_pseudo_bytes($ivlen);
		$ciphertext_raw = openssl_encrypt($token, $cipher, $key, $options=OPENSSL_RAW_DATA, $iv);
		$hmac = hash_hmac('sha256', $ciphertext_raw, $key, $as_binary=true);
		$encryptedToken = base64_encode( $iv.$hmac.$ciphertext_raw );

		// Stores syncUser info
		$syncUsers = $this->syncUserMapper->findByUid($uid);
		if (empty($syncUsers)) {
			$NCUser = $this->userManager->get($uid);
			$syncUser = new SyncUser;
			$syncUser->setUid($uid);
			$syncUser->setToken($encryptedToken);
			$syncUser->setEmail($NCUser->getEMailAddress());
			$syncUser->setActive(Constants::USER_STATUS_ACTIVE);
			$this->syncUserMapper->insert($syncUser);
		} else {
			$syncUsers[0]->setToken($encryptedToken);
			$syncUsers[0]->setActive(Constants::USER_STATUS_ACTIVE);
			$this->syncUserMapper->update($syncUsers[0]);
		}
		
		return new DataResponse(TRUE);
	}

	/**
	 *
	 * @NoAdminRequired
	 *
	 */
	public function activateMail() {
		return;
	}

	/**
	 *
	 * This method invalidates a user. It is used when a user clicks on the 'Retract consent' button
	 *
	 * @NoAdminRequired
	 *
	 */
	public function invalidateSelf() {

		$credentials = $this->credentialStore->getLoginCredentials();
		$resp = $this->invalidate($credentials->getUID(), Constants::USER_STATUS_NOCONSENT);

		return $resp;

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
	public function invalidate(string $userId, $retractConsent = Constants::USER_STATUS_INACTIVE) {

		$response = $this->syncUserService->invalidateUser($userId, $retractConsent);
		return new JSONResponse($response);

	}

	/**
	 *
	 * This methods returns the list of active sendent sync users.
	 *
	 * Users that have retracted their consent to synchronise their data don't count as active
	 *
	 * @NoCSRFRequired
	 *
	 */
	public function getActiveUsers() {

		$activeUsers = $this->syncUserService->getValidUsers();
		return new JSONResponse($activeUsers);

	}

}
