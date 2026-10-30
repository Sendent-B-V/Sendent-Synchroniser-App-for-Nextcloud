<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Settings;

use OCA\SendentSynchroniser\Constants;
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
	private User $settings;

	protected function setUp(): void {
		$this->appConfig = $this->createStub(IAppConfig::class);
		$this->initialState = $this->createMock(IInitialState::class);
		$syncUserMapper = $this->createStub(SyncUserMapper::class);
		$syncUserMapper->method('findByUid')->willReturn([]);
		$this->settings = new User(
			$this->createStub(IAppManager::class),
			$this->createStub(IGroupManager::class),
			$this->appConfig,
			$this->initialState,
			new NullLogger(),
			$syncUserMapper,
			'alice',
		);
	}

	private function storedMode(string $stored): void {
		$this->appConfig->method('getAppValue')
			->willReturnCallback(fn (string $key, string $default = '') => $key === Constants::GRAPH_AUTH_METHOD_KEY ? $stored : $default);
	}

	public function testInitialStateCarriesTheStoredSignInMode(): void {
		$this->storedMode(Constants::GRAPH_AUTH_METHOD_PER_USER_STRICT);
		$this->initialState->expects($this->once())->method('provideInitialState')
			->with('user', [
				'activeUser' => false,
				'graphAuthMethod' => Constants::GRAPH_AUTH_METHOD_PER_USER_STRICT,
			]);

		$this->settings->getForm();
	}

	public function testUnknownStoredModeIsNormalisedToTheDefault(): void {
		$this->storedMode('garbage');
		$this->initialState->expects($this->once())->method('provideInitialState')
			->with('user', [
				'activeUser' => false,
				'graphAuthMethod' => Constants::GRAPH_AUTH_METHOD_DEFAULT,
			]);

		$this->settings->getForm();
	}
}
