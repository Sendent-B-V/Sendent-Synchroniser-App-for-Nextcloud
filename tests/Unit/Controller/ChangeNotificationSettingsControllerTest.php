<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Controller;

use OCA\SendentSynchroniser\Controller\ChangeNotificationSettingsController;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushAvailability;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushTransport;
use OCA\SendentSynchroniser\Service\ChangeNotification\SignalPublisher;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ChangeNotificationSettingsControllerTest extends TestCase {

	/** @var ChangeNotificationConfig&MockObject */
	private $config;

	/** @var IUserManager&MockObject */
	private $userManager;

	/** @var NotifyPushAvailability&MockObject */
	private $availability;

	/** @var SignalPublisher&MockObject */
	private $publisher;

	/** @var NotifyPushTransport&MockObject */
	private $transport;

	/** @var \OCP\IAppConfig&MockObject */
	private $globalAppConfig;

	/** @var \OCP\BackgroundJob\IJobList&MockObject */
	private $jobList;

	private ChangeNotificationSettingsController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(ChangeNotificationConfig::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->availability = $this->createMock(NotifyPushAvailability::class);
		$this->publisher = $this->createMock(SignalPublisher::class);
		$this->transport = $this->createMock(NotifyPushTransport::class);
		$this->globalAppConfig = $this->createMock(\OCP\IAppConfig::class);
		$this->jobList = $this->createMock(\OCP\BackgroundJob\IJobList::class);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1755676800);

		$this->controller = new ChangeNotificationSettingsController(
			'sendentsynchroniser',
			$this->createMock(IRequest::class),
			$this->config,
			$this->userManager,
			$this->availability,
			$this->publisher,
			$this->transport,
			$time,
			$this->globalAppConfig,
			$this->jobList,
			new NullLogger(),
		);
	}

	public function testSetTransportModeStoresAValidMode(): void {
		$this->config->expects($this->once())->method('setTransportMode')->with('polling');

		$response = $this->controller->setTransportMode('polling');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testSetTransportModeRejectsGarbage(): void {
		$this->config->method('setTransportMode')
			->willThrowException(new \InvalidArgumentException('Unknown transport mode: wat'));

		$response = $this->controller->setTransportMode('wat');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testSetBotUserRequiresAnExistingUser(): void {
		$this->userManager->method('userExists')->with('ghost')->willReturn(false);
		$this->config->expects($this->never())->method('setBotUser');

		$response = $this->controller->setBotUser('ghost');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testSetBotUserStoresAnExistingUser(): void {
		$this->userManager->method('userExists')->with('sendent-sync')->willReturn(true);
		$this->config->expects($this->once())->method('setBotUser')->with('sendent-sync');

		$response = $this->controller->setBotUser('sendent-sync');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testSetBatchingStoresWindowAndMaxRefs(): void {
		$this->config->expects($this->once())->method('setBatchWindow')->with(5);
		$this->config->expects($this->once())->method('setMaxRefsPerSignal')->with(200);
		$this->config->expects($this->once())->method('setPollInterval')->with(60);

		$response = $this->controller->setBatching(5, 200, 60);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testRunTestRefreshesTheDaemonCheckAndReturnsAllChecks(): void {
		$this->availability->method('isAppEnabled')->willReturn(true);
		$this->availability->method('queue')->willReturn(new \stdClass());
		$this->availability->method('refreshDaemonCheck')
			->willReturn(['ok' => true, 'at' => 1, 'message' => 'daemon reachable']);
		$this->availability->method('effectiveTransport')->willReturn('notify_push');

		$data = $this->controller->runTest()->getData();

		$this->assertTrue($data['app_enabled']);
		$this->assertTrue($data['queue_available']);
		$this->assertTrue($data['daemon']['ok']);
		$this->assertSame('notify_push', $data['effective_transport']);
	}

	public function testFlushNowDelegatesToThePublisher(): void {
		$this->publisher->expects($this->once())->method('flush')->willReturn(['cursor' => 5]);

		$data = $this->controller->flushNow()->getData();

		$this->assertTrue($data['flushed']);
		$this->assertSame(5, $data['cursor']);
	}

	public function testFlushNowWithNothingPendingSaysSo(): void {
		$this->publisher->method('flush')->willReturn(null);

		$data = $this->controller->flushNow()->getData();

		$this->assertFalse($data['flushed']);
	}

	public function testSendPingPublishesThroughTheTransport(): void {
		$this->transport->expects($this->once())
			->method('publishPing')
			->with($this->callback(fn (array $b) => isset($b['nonce']) && $b['nonce'] !== ''))
			->willReturn(true);

		$data = $this->controller->sendPing()->getData();

		$this->assertTrue($data['published']);
		$this->assertNotEmpty($data['nonce']);
	}

	public function testReportPingStoresTheRoundTripResult(): void {
		$this->config->expects($this->once())->method('setRoundTrip')->with(true, $this->anything(), 38);

		$response = $this->controller->reportPing(true, 38);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testSetWebhookRejectsNonHttpsWhenEnabling(): void {
		$this->config->expects($this->never())->method('setWebhookUrl');

		$response = $this->controller->setWebhook('http://plain.example.com/hook', 's', true);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testSendTestWebhookQueuesAJobWhenEnabled(): void {
		$this->config->method('webhookEnabled')->willReturn(true);
		$this->jobList->expects($this->once())->method('add');

		$data = $this->controller->sendTestWebhook()->getData();

		$this->assertTrue($data['queued']);
	}

	public function testSetConnectorUrlStoresAnHttpsUrl(): void {
		$this->config->expects($this->once())->method('setConnectorUrl')->with('https://connector.example.com');

		$response = $this->controller->setConnectorUrl('https://connector.example.com');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testSetConnectorUrlAllowsClearing(): void {
		$this->config->expects($this->once())->method('setConnectorUrl')->with('');

		$response = $this->controller->setConnectorUrl('');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testSetConnectorUrlRejectsNonHttp(): void {
		$this->config->expects($this->never())->method('setConnectorUrl');

		$response = $this->controller->setConnectorUrl('ftp://nope');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}
}
