<?php

namespace OCA\SendentSynchroniser\Controller;

use OC\Authentication\Events\AppPasswordCreatedEvent;
use OC\Authentication\Exceptions\InvalidTokenException;
use OC\Authentication\Token\IProvider;
use OC\Authentication\Token\IToken;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
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
use \Psr\Log\LoggerInterface;
use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Db\SyncUser;
use OCA\SendentSynchroniser\Db\SyncUserMapper;
use OCA\SendentSynchroniser\Service\CollectionService;
use OCA\SendentSynchroniser\Service\SyncUserService;

class UserController extends Controller {

	/** @var IStore */
	private $credentialStore;

	/** @var IEventDispatcher */
	private $eventDispatcher;

	/** @var IAppConfig */
	private $appConfig;

	/** @var IAppManager */
	private $appManager;

	/** @var string */
	protected $appName;

	/** @var CollectionService */
	private $collectionService;

	/** @var IGroupManager */
	private $groupManager;

	/** @var LoggerInterface */
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

	public function __construct(LoggerInterface $logger, $AppName, IRequest $request,
		IAppConfig $appConfig,
		IAppManager $appManager,
		CollectionService $collectionService,
		IEventDispatcher $eventDispatcher,
		IGroupManager $groupManager,
		IProvider $tokenProvider,
		ISecureRandom $random,
		ISession $session,
		IStore $credentialStore,
		SyncUserMapper $syncUserMapper,
		SyncUserService $syncUserService) {

		parent::__construct($AppName, $request);

		$this->appConfig = $appConfig;
		$this->appManager = $appManager;
		$this->appName = $AppName;
		$this->collectionService = $collectionService;
		$this->credentialStore = $credentialStore;
		$this->eventDispatcher = $eventDispatcher;
		$this->groupManager = $groupManager;
		$this->logger = $logger;
		$this->random = $random;
		$this->session = $session;
		$this->syncUserMapper = $syncUserMapper;
		$this->syncUserService = $syncUserService;
		$this->tokenProvider = $tokenProvider;

	}

	/**
	 *
	 * This method activates a user for sendent synchroniser.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
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

		// Invalidates existing app token
		$this->syncUserService->invalidateUser($credentials->getUID());

		// Generates an app token for Sendent synchroniser
		$token = $this->random->generate(72, ISecureRandom::CHAR_UPPER.ISecureRandom::CHAR_LOWER.ISecureRandom::CHAR_DIGITS);
		$generatedToken = $this->tokenProvider->generateToken(
			$token,
			$credentials->getUID(),
			$credentials->getLoginName(),
			null,
			$this->appName,
			IToken::PERMANENT_TOKEN,
			IToken::DO_NOT_REMEMBER
		);

		$this->logger->info('Created new Sendentsync app token for user "' . $credentials->getUID() . '" (' . $credentials->getLoginName() . ')');
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

		// Resolve user's active sync groups
		$activeGroups = $this->appConfig->getAppValue('activeGroups', '');
		$activeGroups = ($activeGroups !== '' && $activeGroups !== 'null') ? json_decode($activeGroups) : [];
		$userGroupIds = [];
		foreach ($activeGroups as $gid) {
			if ($this->groupManager->isInGroup($credentials->getUID(), $gid)) {
				$userGroupIds[] = $gid;
			}
		}

		// Ensure default collections exist (creates calendar/addressbook if missing)
		$this->collectionService->ensureDefaultCollections($credentials->getUID(), $userGroupIds);

		// Stores syncUser info
		$syncUsers = $this->syncUserMapper->findByUid($credentials->getUID());
		if (empty($syncUsers)) {
			$syncUser = new SyncUser;
			$syncUser->setUid($credentials->getUID());
			$syncUser->setToken($encryptedToken);
			$syncUser->setActive(Constants::USER_STATUS_ACTIVE);
			$syncUser->setUsername($credentials->getLoginName());
			$this->syncUserMapper->insert($syncUser);
			$this->logger->info('Created new Sendentsync user "' . $credentials->getUID() . '"');
		} else {
			$syncUsers[0]->setToken($encryptedToken);
			$syncUsers[0]->setActive(Constants::USER_STATUS_ACTIVE);
			$syncUsers[0]->setUsername($credentials->getLoginName());
			$this->syncUserMapper->update($syncUsers[0]);
			$this->logger->info('Updated Sendentsync user "' . $credentials->getUID() . '"');
		}

		return new JSONResponse([
			'emailDomain' =>  '@' . $this->appConfig->getAppValue('emailDomain', ''),
			'shouldAskMailSync' => ($this->appManager->isInstalled('mail') && ($this->appConfig->getAppValue('IMAPSyncEnabled', "false") === 'true')),
		]);

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
	public function invalidate(?string $userId = null, $retractConsent = Constants::USER_STATUS_INACTIVE) {
		$uid = $this->request->getParam('userId', $userId);
		if (!$uid) {
			return new JSONResponse(['status' => 'Error', 'message' => 'Missing userId'], Http::STATUS_BAD_REQUEST);
		}

		$response = $this->syncUserService->invalidateUser($uid, $retractConsent);
		return new JSONResponse($response);
	}


	/**
	 *
	 * This method invalidates all users.
	 *
	 * @NoCSRFRequired
	 *
	 */
	public function invalidateAll($retractConsent = Constants::USER_STATUS_INACTIVE) {

		$users = $this->syncUserService->getAllUsers();

		foreach ($users as $user) {
			$this->syncUserService->invalidateUser($user->getUid(), $retractConsent);
		}

		return new JSONResponse();
	}

	/**
	 *
	 * This methods returns the list of active sendent sync users.
	 *
	 * Users that have retracted their consent to synchronise their data don't count as active.
	 * Supports pagination via `page` and `limit` query parameters.
	 *
	 * @NoCSRFRequired
	 *
	 */
	public function getActiveUsers(?int $page = null, int $limit = 100) {

		$activeUsers = $this->syncUserService->getValidUsers();
		$total = count($activeUsers);

		// When page parameter is provided, return paginated results
		if ($page !== null) {
			$page = max(1, $page);
			$limit = max(1, min($limit, 1000));
			$offset = ($page - 1) * $limit;
			$pagedUsers = array_slice($activeUsers, $offset, $limit);

			return new JSONResponse([
				'data' => array_values($pagedUsers),
				'pagination' => [
					'page' => $page,
					'limit' => $limit,
					'total' => $total,
					'totalPages' => (int)ceil($total / $limit),
				],
			]);
		}

		// Backwards-compatible: no page param returns flat array
		return new JSONResponse($activeUsers);
	}

}
