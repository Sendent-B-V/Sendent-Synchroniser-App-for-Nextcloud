<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Settings;

use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Service\SyncUserService;
use OCA\SendentSynchroniser\Settings\Admin;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The admin settings page gets the sign-in mode and the sync service's public
 * URL through initial state. The mode goes through the normaliser, so the
 * radio group never opens on a value the setter would refuse; the URL is
 * handed over exactly as stored.
 */
class AdminTest extends TestCase {

	private IAppConfig&Stub $appConfig;
	private IInitialState&MockObject $initialState;
	private Admin $settings;

	/** App values by key; a key that is absent answers with the caller's default. */
	private array $stored = [];
	/** The params handed to initial state by the last getForm(). */
	private array $params = [];

	protected function setUp(): void {
		$this->appConfig = $this->createStub(IAppConfig::class);
		// Admin.php passes integer defaults for some keys, so $default is left
		// untyped here and cast on the way out.
		$this->appConfig->method('getAppValue')
			->willReturnCallback(fn (string $key, $default = '') => (string)($this->stored[$key] ?? $default));

		$this->initialState = $this->createMock(IInitialState::class);

		// No groups and no active users: the group and user counting above the
		// settings under test runs on empty lists.
		$groupManager = $this->createStub(IGroupManager::class);
		$groupManager->method('search')->willReturn([]);
		$syncUserService = $this->createStub(SyncUserService::class);
		$syncUserService->method('getValidUsers')->willReturn([]);

		$this->settings = new Admin(
			$this->createStub(IAppManager::class),
			$groupManager,
			$this->initialState,
			$this->appConfig,
			$syncUserService,
			$this->createStub(IL10N::class),
		);
	}

	/** Runs getForm() and returns what it provided under the 'admin' key. */
	private function initialStateParams(): array {
		$this->initialState->expects($this->once())->method('provideInitialState')
			->willReturnCallback(function (string $key, $data): void {
				$this->assertSame('admin', $key);
				$this->params = $data;
			});

		$this->settings->getForm();

		return $this->params;
	}

	public static function knownModes(): array {
		return [
			'service account' => [Constants::GRAPH_AUTH_METHOD_SERVICE_ACCOUNT],
			'per user, fallback' => [Constants::GRAPH_AUTH_METHOD_PER_USER_FALLBACK],
			'per user, strict' => [Constants::GRAPH_AUTH_METHOD_PER_USER_STRICT],
		];
	}

	#[DataProvider('knownModes')]
	public function testInitialStateCarriesTheStoredSignInMode(string $mode): void {
		$this->stored[Constants::GRAPH_AUTH_METHOD_KEY] = $mode;

		$this->assertSame($mode, $this->initialStateParams()['graphAuthMethod']);
	}

	public function testNothingStoredReportsTheDefaultMode(): void {
		$this->assertSame(Constants::GRAPH_AUTH_METHOD_DEFAULT, $this->initialStateParams()['graphAuthMethod']);
	}

	public static function unknownModes(): array {
		return [
			'pre-rename value' => ['perUserOAuth'],
			'empty' => [''],
			'wrong case' => ['PerUserStrict'],
			'free text' => ['garbage'],
		];
	}

	#[DataProvider('unknownModes')]
	public function testUnknownStoredModeIsNormalisedToTheDefault(string $stored): void {
		$this->stored[Constants::GRAPH_AUTH_METHOD_KEY] = $stored;

		$this->assertSame(Constants::GRAPH_AUTH_METHOD_DEFAULT, $this->initialStateParams()['graphAuthMethod']);
	}

	public function testInitialStateCarriesTheStoredPublicUrl(): void {
		$this->stored[Constants::SYNC_SERVICE_PUBLIC_URL_KEY] = 'https://sync.example.com';

		$this->assertSame('https://sync.example.com', $this->initialStateParams()['syncServicePublicUrl']);
	}

	public function testPublicUrlIsEmptyWhenNeverSet(): void {
		$this->assertSame('', $this->initialStateParams()['syncServicePublicUrl']);
	}

	public function testModeAndPublicUrlAreReadFromTheirOwnKeys(): void {
		// Both set at once: neither value leaks into the other's slot.
		$this->stored[Constants::GRAPH_AUTH_METHOD_KEY] = Constants::GRAPH_AUTH_METHOD_PER_USER_STRICT;
		$this->stored[Constants::SYNC_SERVICE_PUBLIC_URL_KEY] = 'https://example.com/sync';

		$params = $this->initialStateParams();

		$this->assertSame(Constants::GRAPH_AUTH_METHOD_PER_USER_STRICT, $params['graphAuthMethod']);
		$this->assertSame('https://example.com/sync', $params['syncServicePublicUrl']);
	}
}
