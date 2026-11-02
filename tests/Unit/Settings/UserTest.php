<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Settings;

use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Db\AuthStatus;
use OCA\SendentSynchroniser\Db\AuthStatusMapper;
use OCA\SendentSynchroniser\Db\SyncUserMapper;
use OCA\SendentSynchroniser\Settings\User;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The personal settings page gets the org-wide sign-in mode through initial
 * state, so the consent flow can show the Connect Microsoft button without a
 * round trip.
 */
class UserTest extends TestCase {

	private IAppConfig&Stub $appConfig;
	private IInitialState&MockObject $initialState;
	private AuthStatusMapper&Stub $authStatusMapper;
	private User $settings;

	protected function setUp(): void {
		$this->appConfig = $this->createStub(IAppConfig::class);
		$this->initialState = $this->createMock(IInitialState::class);
		$syncUserMapper = $this->createStub(SyncUserMapper::class);
		$syncUserMapper->method('findByUid')->willReturn([]);
		$this->authStatusMapper = $this->createStub(AuthStatusMapper::class);
		$this->settings = new User(
			$this->createStub(IAppManager::class),
			$this->createStub(IGroupManager::class),
			$this->appConfig,
			$this->initialState,
			new NullLogger(),
			$syncUserMapper,
			$this->authStatusMapper,
			'alice',
		);
	}

	private function storedMode(string $stored): void {
		$this->appConfig->method('getAppValue')
			->willReturnCallback(fn (string $key, string $default = '') => $key === Constants::GRAPH_AUTH_METHOD_KEY ? $stored : $default);
	}

	public function testInitialStateCarriesTheStoredSignInMode(): void {
		$this->storedMode(Constants::GRAPH_AUTH_METHOD_PER_USER_STRICT);
		$this->authStatusMapper->method('findByUidAndProvider')->willReturn(null);
		$this->initialState->expects($this->once())->method('provideInitialState')
			->with('user', [
				'activeUser' => false,
				'graphAuthMethod' => Constants::GRAPH_AUTH_METHOD_PER_USER_STRICT,
				'authStatus' => null,
			]);

		$this->settings->getForm();
	}

	public function testUnknownStoredModeIsNormalisedToTheDefault(): void {
		$this->storedMode('garbage');
		$this->authStatusMapper->method('findByUidAndProvider')->willReturn(null);
		$this->initialState->expects($this->once())->method('provideInitialState')
			->with('user', [
				'activeUser' => false,
				'graphAuthMethod' => Constants::GRAPH_AUTH_METHOD_DEFAULT,
				'authStatus' => null,
			]);

		$this->settings->getForm();
	}

	public function testInitialStateCarriesTheStoredMicrosoftStatus(): void {
		$this->storedMode(Constants::GRAPH_AUTH_METHOD_PER_USER_FALLBACK);
		$row = new AuthStatus();
		$row->setId(5);
		$row->setUid('alice');
		$row->setProvider('graph');
		$row->setStatus('needsReconnect');
		$row->setReason('refresh_token_expired');
		$row->setChangedAt(1792402200);
		$this->authStatusMapper->method('findByUidAndProvider')->willReturn($row);
		$this->initialState->expects($this->once())->method('provideInitialState')
			->with('user', [
				'activeUser' => false,
				'graphAuthMethod' => Constants::GRAPH_AUTH_METHOD_PER_USER_FALLBACK,
				'authStatus' => [
					'provider' => 'graph',
					'status' => 'needsReconnect',
					'reason' => 'refresh_token_expired',
					'changedAt' => 1792402200,
				],
			]);

		$this->settings->getForm();
	}
}
