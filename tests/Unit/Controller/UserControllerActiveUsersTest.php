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
use OCP\Authentication\LoginCredentials\IStore;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\ISession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * GET /api/1.0/user/actives: the paged response carries the org-wide sign-in
 * mode as a top-level `signInMode` field beside `data` and `pagination`
 * (CONTRACTS.md, "Sign-in mode in the active-users response" under C7).
 * The legacy flat list is a bare JSON array and cannot carry it.
 */
class UserControllerActiveUsersTest extends TestCase {

	private IAppConfig&MockObject $appConfig;
	private SyncUserService&MockObject $syncUserService;
	private UserController $controller;

	/** @var string|null What the app config holds for the mode; null means nothing stored. */
	private ?string $storedMode = null;

	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->syncUserService = $this->createMock(SyncUserService::class);
		$this->controller = new UserController(
			new NullLogger(),
			'sendentsynchroniser',
			$this->createMock(IRequest::class),
			$this->appConfig,
			$this->createMock(IAppManager::class),
			$this->createMock(CollectionService::class),
			$this->createMock(IEventDispatcher::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IProvider::class),
			$this->createMock(ISecureRandom::class),
			$this->createMock(ISession::class),
			$this->createMock(IStore::class),
			$this->createMock(SyncUserMapper::class),
			$this->syncUserService
		);

		// Behaves like the real IAppConfig: a key that was never written yields
		// whatever default the caller passed. getAppValue() is declared `: string`,
		// so every arm must return a string.
		$this->appConfig->method('getAppValue')->willReturnCallback(
			fn (string $key, string $default = '') => match (true) {
				$key === Constants::GRAPH_AUTH_METHOD_KEY && $this->storedMode !== null => $this->storedMode,
				default => $default,
			}
		);

		$this->syncUserService->method('getValidUsers')->willReturn([
			['uid' => 'alice', 'username' => 'alice'],
			['uid' => 'bob', 'username' => 'bob'],
			['uid' => 'carol', 'username' => 'carol'],
		]);
	}

	#[DataProvider('storedModes')]
	public function testPagedResponseCarriesTheStoredMode(string $stored): void {
		$this->storedMode = $stored;

		$data = $this->pagedData(page: 1);

		$this->assertSame($stored, $data['signInMode']);
	}

	public static function storedModes(): array {
		return [
			'service account' => [Constants::GRAPH_AUTH_METHOD_SERVICE_ACCOUNT],
			'per user, fallback' => [Constants::GRAPH_AUTH_METHOD_PER_USER_FALLBACK],
			'per user, strict' => [Constants::GRAPH_AUTH_METHOD_PER_USER_STRICT],
		];
	}

	public function testNothingStoredReportsTheDefault(): void {
		$this->storedMode = null;

		$data = $this->pagedData(page: 1);

		$this->assertSame(Constants::GRAPH_AUTH_METHOD_DEFAULT, $data['signInMode']);
	}

	#[DataProvider('unknownStoredModes')]
	public function testUnknownStoredValueFallsBackToTheDefault(string $stored): void {
		$this->storedMode = $stored;

		$data = $this->pagedData(page: 1);

		$this->assertSame(Constants::GRAPH_AUTH_METHOD_DEFAULT, $data['signInMode']);
	}

	public static function unknownStoredModes(): array {
		return [
			'pre-rename value' => ['perUserOAuth'],
			'empty string' => [''],
			'wrong case' => ['PerUserStrict'],
		];
	}

	public function testModeIsPresentOnEveryPage(): void {
		$this->storedMode = Constants::GRAPH_AUTH_METHOD_PER_USER_STRICT;

		$page1 = $this->pagedData(page: 1, limit: 2);
		$page2 = $this->pagedData(page: 2, limit: 2);

		$this->assertSame(Constants::GRAPH_AUTH_METHOD_PER_USER_STRICT, $page1['signInMode']);
		$this->assertSame(Constants::GRAPH_AUTH_METHOD_PER_USER_STRICT, $page2['signInMode']);
		$this->assertCount(2, $page1['data']);
		$this->assertCount(1, $page2['data']);
		$this->assertSame(2, $page2['pagination']['totalPages']);
	}

	public function testPagedShapeKeepsDataAndPagination(): void {
		$this->storedMode = Constants::GRAPH_AUTH_METHOD_PER_USER_FALLBACK;

		$data = $this->pagedData(page: 1);

		$this->assertSame(['signInMode', 'data', 'pagination'], array_keys($data));
		$this->assertSame(3, $data['pagination']['total']);
	}

	public function testLegacyFlatListIsUnchanged(): void {
		$this->storedMode = Constants::GRAPH_AUTH_METHOD_PER_USER_STRICT;

		$response = $this->controller->getActiveUsers();
		$data = $response->getData();

		// A bare array has no named slots, so the mode cannot ride along here
		// and the old shape must stay exactly as older sync services expect it.
		$this->assertTrue(array_is_list($data));
		$this->assertCount(3, $data);
		$this->assertArrayNotHasKey('signInMode', $data);
	}

	private function pagedData(int $page, int $limit = 100): array {
		$response = $this->controller->getActiveUsers($page, $limit);
		$this->assertInstanceOf(JSONResponse::class, $response);
		return $response->getData();
	}
}
