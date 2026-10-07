<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushAvailability;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class NotifyPushAvailabilityTest extends TestCase {

	/** @var IAppManager&MockObject */
	private $appManager;

	/** @var IClient&MockObject */
	private $client;

	/** @var ChangeNotificationConfig&MockObject */
	private $config;

	/** @var ContainerInterface&MockObject */
	private $container;

	private string $baseEndpoint = 'http://localhost:7867';

	protected function setUp(): void {
		parent::setUp();
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->client = $this->createMock(IClient::class);
		$this->config = $this->createMock(ChangeNotificationConfig::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturn(new \stdClass()); // a real (non-null) queue
	}

	private function availability(): NotifyPushAvailability {
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->client);
		$serverConfig = $this->createMock(IConfig::class);
		$serverConfig->method('getAppValue')->with('notify_push', 'base_endpoint', '')->willReturnCallback(fn () => $this->baseEndpoint);

		return new NotifyPushAvailability($this->appManager, $this->container, $clientService, $serverConfig, $this->config);
	}

	private function respondWith(int $status): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$this->client->method('get')->willReturn($response);
	}

	public function testConfiguredPushIsAdvertisedWithoutProbingTheDaemon(): void {
		// As Nextcloud advertises notify_push to its own clients: configured
		// means offered. A Connector that cannot connect polls until it can.
		$this->config->method('transportMode')->willReturn('auto');
		$this->client->expects($this->never())->method('get');

		$this->assertSame('notify_push', $this->availability()->effectiveTransport());
	}

	public function testPinnedPollingAdvertisesPolling(): void {
		$this->config->method('transportMode')->willReturn('polling');

		$this->assertSame('polling', $this->availability()->effectiveTransport());
	}

	public function testWithoutAPushEndpointPollingIsAdvertised(): void {
		$this->config->method('transportMode')->willReturn('auto');
		$this->baseEndpoint = '';

		$this->assertSame('polling', $this->availability()->effectiveTransport());
	}

	public function testATwoHundredMeansReachable(): void {
		$this->respondWith(200);

		$this->assertTrue($this->availability()->probeDaemon()['ok']);
	}

	public function testATokenGuarded400StillMeansReachable(): void {
		// notify_push >= 1.x guards /test/cookie with a per-run token header
		// only its own self-test can present; a 400 from the guard proves a
		// daemon is answering at base_endpoint, which is all this probe needs.
		$this->respondWith(400);

		$this->assertTrue($this->availability()->probeDaemon()['ok']);
	}

	public function testAGatewayErrorMeansUnreachable(): void {
		// 5xx is a proxy answering for a dead backend, not a live daemon.
		$this->respondWith(502);

		$this->assertFalse($this->availability()->probeDaemon()['ok']);
	}

	public function testATransportErrorMeansUnreachable(): void {
		$this->client->method('get')->willThrowException(new \RuntimeException('connection refused'));

		$result = $this->availability()->probeDaemon();

		$this->assertFalse($result['ok']);
		$this->assertStringContainsString('unreachable', $result['message']);
	}
}
