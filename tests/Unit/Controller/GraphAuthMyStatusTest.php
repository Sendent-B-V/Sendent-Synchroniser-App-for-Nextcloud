<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Controller;

use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Controller\GraphAuthController;
use OCA\SendentSynchroniser\Db\AuthStatus;
use OCA\SendentSynchroniser\Db\AuthStatusMapper;
use OCA\SendentSynchroniser\Service\StartLinkBuilder;
use OCA\SendentSynchroniser\Service\SyncUserService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The logged-in user's own status {@see GraphAuthController::myStatus()}:
 * what the settings page and the reconnect modal read to pick their text.
 */
class GraphAuthMyStatusTest extends TestCase {

	private AuthStatusMapper&MockObject $mapper;
	private IUserSession&Stub $userSession;
	private GraphAuthController $controller;
	private string $signInMode = Constants::GRAPH_AUTH_METHOD_PER_USER_FALLBACK;
	private bool $activeUser = true;

	protected function setUp(): void {
		$this->mapper = $this->createMock(AuthStatusMapper::class);
		$this->userSession = $this->createStub(IUserSession::class);
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getAppValue')->willReturnCallback(fn () => $this->signInMode);
		$syncUserService = $this->createStub(SyncUserService::class);
		$syncUserService->method('isActiveUser')->willReturnCallback(fn () => $this->activeUser);
		$this->controller = new GraphAuthController(
			'sendentsynchroniser',
			$this->createStub(IRequest::class),
			$this->createStub(StartLinkBuilder::class),
			$this->userSession,
			new NullLogger(),
			$this->mapper,
			$this->createStub(IUserManager::class),
			$appConfig,
			$syncUserService,
		);
	}

	private function loggedInAs(string $uid): void {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testReturns401WithoutUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->mapper->expects($this->never())->method('findByUidAndProvider');

		$response = $this->controller->myStatus();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testReturnsOwnGraphRowAndSignInMode(): void {
		$this->loggedInAs('alice');
		$row = new AuthStatus();
		$row->setId(3);
		$row->setUid('alice');
		$row->setProvider('graph');
		$row->setStatus('needsReconnect');
		$row->setReason('refresh_token_expired');
		$row->setChangedAt(1792402200);
		$this->mapper->expects($this->once())->method('findByUidAndProvider')
			->with('alice', Constants::AUTH_PROVIDER_GRAPH)->willReturn($row);

		$response = $this->controller->myStatus();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([
			'signInMode' => 'perUserFallback',
			'activeUser' => true,
			'status' => [
				'provider' => 'graph',
				'status' => 'needsReconnect',
				'reason' => 'refresh_token_expired',
				'changedAt' => 1792402200,
			],
		], json_decode($response->render(), true), 'no id and no uid may leak to the browser');
	}

	public function testReturnsNullStatusWhenNothingStored(): void {
		$this->loggedInAs('alice');
		$this->signInMode = Constants::GRAPH_AUTH_METHOD_PER_USER_STRICT;
		$this->activeUser = false;
		$this->mapper->method('findByUidAndProvider')->willReturn(null);

		$response = $this->controller->myStatus();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['signInMode' => 'perUserStrict', 'activeUser' => false, 'status' => null], $response->getData());
	}

	public function testNormalisesAnUnknownStoredMode(): void {
		$this->loggedInAs('alice');
		$this->signInMode = 'perUserOAuth';
		$this->mapper->method('findByUidAndProvider')->willReturn(null);

		$this->assertSame('serviceAccount', $this->controller->myStatus()->getData()['signInMode']);
	}
}
