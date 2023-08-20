<?php

namespace OCA\SendentSynchroniser\Controller;

use OCP\IRequest;
use OCP\AppFramework\Controller;
use OC\Authentication\Events\AppPasswordCreatedEvent;
use OC\Authentication\Exceptions\InvalidTokenException;
use OC\Authentication\Token\IProvider;
use OC\Authentication\Token\IToken;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\Authentication\Exceptions\CredentialsUnavailableException;
use OCP\Authentication\Exceptions\PasswordUnavailableException;
use OCP\Authentication\LoginCredentials\IStore;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ISession;
use \OCP\ILogger;
use OCP\Security\ISecureRandom;
use OCA\SendentSynchroniser\Db\SyncUser;
use OCA\SendentSynchroniser\Db\SyncUserMapper;

class PageController extends Controller {
	private $credentialStore;
	private $eventDispatcher;
	private $logger;
	private $random;
	private $session;
	private $syncUserMapper;
	private $tokenProvider;
	
	public function __construct(ILogger $logger, $AppName, IRequest $request,
		ISession $session,
		ISecureRandom $random,
		IProvider $tokenProvider,
		IStore $credentialStore,
		IEventDispatcher $eventDispatcher,
		SyncUserMapper $syncUserMapper) {

		parent::__construct($AppName, $request);
		
		$this->credentialStore = $credentialStore;
		$this->eventDispatcher = $eventDispatcher;
		$this->logger = $logger;
		$this->random = $random;
		$this->session = $session;
		$this->syncUserMapper = $syncUserMapper;
		$this->tokenProvider = $tokenProvider;

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
	public function permit() {
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
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function health(){
		return "OK";
	}
}
