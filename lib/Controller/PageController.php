<?php

namespace OCA\SendentSynchroniser\Controller;

use OCP\IRequest;
use OCP\AppFramework\Http\TemplateResponse;
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
use OCA\SendentSynchroniser\Service\SyncGroupService;
use OCA\SendentSynchroniser\Service\UserGroupService;
use OCP\IURLGenerator;

class PageController extends Controller {
	private $userId;
	private $logger;
	private $syncGroupService;
	private $externalUserService;
	private $urlGenerator;

	public function __construct(ILogger $logger, $AppName, IRequest $request, $UserId, IURLGenerator $urlGenerator,
		private ISession $session,
		private ISecureRandom $random,
		private IProvider $tokenProvider,
		private IStore $credentialStore,
		private IEventDispatcher $eventDispatcher,
		UserGroupService $externalUserService,
		SyncGroupService $syncGroupService) {
		parent::__construct($AppName, $request);
		$this->userId = $UserId;
		$this->logger = $logger;
		$this->syncGroupService = $syncGroupService;
		$this->externalUserService = $externalUserService;
		$this->urlGenerator = $urlGenerator;
	}
	
	/**
	 *
	 * This method creates an application token for the user
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

		$this->logger->error('Token created and is: ' . $token);

		$this->eventDispatcher->dispatchTyped(
			new AppPasswordCreatedEvent($generatedToken)
		);

		return new DataResponse(True);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function startConsentFlow(){
		
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function health(){
		return "OK";
	}
}
