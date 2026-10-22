<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Controller;

use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Controller\SettingsController;
use OCA\SendentSynchroniser\Db\SyncUserMapper;
use OCA\SendentSynchroniser\Service\SyncUserService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\Notification\IManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SettingsControllerGraphAuthMethodTest extends TestCase {

	private IAppConfig&MockObject $appConfig;
	private SettingsController $controller;

	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->controller = new SettingsController(
			'sendentsynchroniser',
			$this->createMock(IRequest::class),
			'admin',
			$this->appConfig,
			$this->createMock(IGroupManager::class),
			new NullLogger(),
			$this->createMock(IManager::class),
			$this->createMock(SyncUserMapper::class),
			$this->createMock(SyncUserService::class)
		);
	}

	#[DataProvider('knownMethods')]
	public function testKnownMethodIsStored(string $method): void {
		$this->appConfig->expects($this->once())
			->method('setAppValue')
			->with(Constants::GRAPH_AUTH_METHOD_KEY, $method);

		$this->controller->setGraphAuthMethod($method);
	}

	public static function knownMethods(): array {
		return [
			'service account' => [Constants::GRAPH_AUTH_METHOD_SERVICE_ACCOUNT],
			'per user, fallback' => [Constants::GRAPH_AUTH_METHOD_PER_USER_FALLBACK],
			'per user, strict' => [Constants::GRAPH_AUTH_METHOD_PER_USER_STRICT],
		];
	}

	public function testStoredValuesMatchTheContract(): void {
		// The sync service compares these strings byte for byte (CONTRACTS.md, Q3 and C7).
		$this->assertSame('serviceAccount', Constants::GRAPH_AUTH_METHOD_SERVICE_ACCOUNT);
		$this->assertSame('perUserFallback', Constants::GRAPH_AUTH_METHOD_PER_USER_FALLBACK);
		$this->assertSame('perUserStrict', Constants::GRAPH_AUTH_METHOD_PER_USER_STRICT);
		$this->assertSame(Constants::GRAPH_AUTH_METHOD_SERVICE_ACCOUNT, Constants::GRAPH_AUTH_METHOD_DEFAULT);
	}

	#[DataProvider('unknownMethods')]
	public function testUnknownMethodIsRejected(string $method): void {
		$this->appConfig->expects($this->never())->method('setAppValue');

		$response = $this->controller->setGraphAuthMethod($method);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public static function unknownMethods(): array {
		return [
			'free text' => ['delegated'],
			'empty' => [''],
			'pre-rename value' => ['perUserOAuth'],
			'wrong case' => ['PerUserStrict'],
		];
	}

	#[DataProvider('acceptedPublicUrls')]
	public function testPublicUrlIsNormalisedAndStored(string $input, string $stored): void {
		$this->appConfig->expects($this->once())
			->method('setAppValue')
			->with(Constants::SYNC_SERVICE_PUBLIC_URL_KEY, $stored);

		$this->controller->setSyncServicePublicUrl($input);
	}

	public static function acceptedPublicUrls(): array {
		return [
			'origin' => ['https://sync.example.com', 'https://sync.example.com'],
			'trailing slash dropped' => ['https://sync.example.com/', 'https://sync.example.com'],
			'path prefix kept' => [' https://example.com/sync/ ', 'https://example.com/sync'],
			'empty clears' => ['', ''],
		];
	}

	#[DataProvider('rejectedPublicUrls')]
	public function testInvalidPublicUrlIsRejected(string $input): void {
		$this->appConfig->expects($this->never())->method('setAppValue');

		$response = $this->controller->setSyncServicePublicUrl($input);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public static function rejectedPublicUrls(): array {
		return [
			'http' => ['http://sync.example.com'],
			'no scheme' => ['sync.example.com'],
			'query' => ['https://sync.example.com?x=1'],
			'fragment' => ['https://sync.example.com#x'],
			'no host' => ['https://'],
		];
	}
}
