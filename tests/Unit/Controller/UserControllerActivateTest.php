<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Controller;

use OC\Authentication\Token\IProvider;
use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Controller\UserController;
use OCA\SendentSynchroniser\Db\SyncUserMapper;
use OCA\SendentSynchroniser\Service\CollectionService;
use OCA\SendentSynchroniser\Service\SyncUserService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Authentication\LoginCredentials\ICredentials;
use OCP\Authentication\LoginCredentials\IStore;
use OCP\Authentication\Token\IToken;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\ISession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * GET /api/1.0/user/activate: the response carries the org-wide sign-in mode
 * so the consent modal, which has no initial state, can decide whether to
 * offer the Connect Microsoft button right after activation.
 */
class UserControllerActivateTest extends TestCase {

	private IAppConfig&Stub $appConfig;
	private UserController $controller;

	protected function setUp(): void {
		$this->appConfig = $this->createStub(IAppConfig::class);

		$credentials = $this->createStub(ICredentials::class);
		$credentials->method('getUID')->willReturn('alice');
		$credentials->method('getLoginName')->willReturn('alice');
		$credentialStore = $this->createStub(IStore::class);
		$credentialStore->method('getLoginCredentials')->willReturn($credentials);

		$session = $this->createStub(ISession::class);
		$session->method('exists')->willReturn(false);

		$random = $this->createStub(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('x', 72));

		$tokenProvider = $this->createStub(IProvider::class);
		$tokenProvider->method('generateToken')->willReturn($this->createStub(IToken::class));

		$syncUserMapper = $this->createStub(SyncUserMapper::class);
		$syncUserMapper->method('findByUid')->willReturn([]);

		$this->controller = new UserController(
			new NullLogger(),
			'sendentsynchroniser',
			$this->createStub(IRequest::class),
			$this->appConfig,
			$this->createStub(IAppManager::class),
			$this->createStub(CollectionService::class),
			$this->createStub(IEventDispatcher::class),
			$this->createStub(IGroupManager::class),
			$tokenProvider,
			$random,
			$session,
			$credentialStore,
			$syncUserMapper,
			$this->createStub(SyncUserService::class),
		);
	}

	private function storedMode(string $stored): void {
		$this->appConfig->method('getAppValue')
			->willReturnCallback(fn (string $key, string $default = '') => $key === Constants::GRAPH_AUTH_METHOD_KEY ? $stored : $default);
	}

	public function testResponseCarriesTheStoredSignInMode(): void {
		$this->storedMode(Constants::GRAPH_AUTH_METHOD_PER_USER_FALLBACK);

		$response = $this->controller->activate();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Constants::GRAPH_AUTH_METHOD_PER_USER_FALLBACK, $response->getData()['graphAuthMethod']);
	}

	public function testUnknownStoredModeIsNormalisedToTheDefault(): void {
		$this->storedMode('garbage');

		$response = $this->controller->activate();

		$this->assertSame(Constants::GRAPH_AUTH_METHOD_DEFAULT, $response->getData()['graphAuthMethod']);
	}

	public function testExistingFieldsAreKept(): void {
		$this->storedMode(Constants::GRAPH_AUTH_METHOD_SERVICE_ACCOUNT);

		$data = $this->controller->activate()->getData();

		$this->assertSame(['emailDomain', 'shouldAskMailSync', 'graphAuthMethod'], array_keys($data));
	}
}
