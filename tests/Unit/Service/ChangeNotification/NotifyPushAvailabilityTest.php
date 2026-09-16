<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushAvailability;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
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

	private NotifyPushAvailability $availability;

	protected function setUp(): void {
		parent::setUp();
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('isInstalled')->willReturn(true);

		$clientService = $this->createMock(IClientService::class);
		$this->client = $this->createMock(IClient::class);
		$clientService->method('newClient')->willReturn($this->client);

		$serverConfig = $this->createMock(IConfig::class);
		$serverConfig->method('getAppValue')
			->with('notify_push', 'base_endpoint', '')
			->willReturn('http://localhost:7867');

		$this->config = $this->createMock(ChangeNotificationConfig::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1755676800);

		$this->availability = new NotifyPushAvailability(
			$this->appManager,
			$this->createMock(ContainerInterface::class),
			$clientService,
			$serverConfig,
			$this->config,
			$time,
		);
	}

	private function respondWith(int $status): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$this->client->method('get')->willReturn($response);
	}

	public function testATwoHundredMeansReachable(): void {
		$this->respondWith(200);

		$this->assertTrue($this->availability->refreshDaemonCheck()['ok']);
	}

	public function testATokenGuarded400StillMeansReachable(): void {
		// notify_push >= 1.x guards /test/cookie with a per-run token header
		// only its own self-test can present; a 400 from the guard proves a
		// daemon is answering at base_endpoint, which is all this probe needs.
		$this->respondWith(400);

		$result = $this->availability->refreshDaemonCheck();

		$this->assertTrue($result['ok']);
	}

	public function testAGatewayErrorMeansUnreachable(): void {
		// 5xx is a proxy answering for a dead backend, not a live daemon.
		$this->respondWith(502);

		$this->assertFalse($this->availability->refreshDaemonCheck()['ok']);
	}

	public function testATransportErrorMeansUnreachable(): void {
		$this->client->method('get')->willThrowException(new \RuntimeException('connection refused'));

		$result = $this->availability->refreshDaemonCheck();

		$this->assertFalse($result['ok']);
		$this->assertStringContainsString('unreachable', $result['message']);
	}
}
