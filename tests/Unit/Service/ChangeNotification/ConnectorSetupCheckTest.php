<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\ConnectorSetupCheck;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushAvailability;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConnectorSetupCheckTest extends TestCase {

	private const NOW = 1755676800;

	/** @var ChangeNotificationConfig&MockObject */
	private $config;

	/** @var NotifyPushAvailability&MockObject */
	private $availability;

	private ConnectorSetupCheck $check;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(ChangeNotificationConfig::class);
		$this->availability = $this->createMock(NotifyPushAvailability::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$this->check = $this->checkWithServerSupport(true);
	}

	/**
	 * Pins the NC-version capability probe so these tests behave identically
	 * on every server checkout the suite runs against.
	 */
	private function checkWithServerSupport(bool $supported): ConnectorSetupCheck {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new class($this->config, $this->availability, $time, $supported) extends ConnectorSetupCheck {
			public function __construct($config, $availability, $time, private bool $supported) {
				parent::__construct($config, $availability, $time);
			}

			protected function serverSupportsChangeEvents(): bool {
				return $this->supported;
			}
		};
	}

	private function healthyNotifyPush(): void {
		$this->availability->method('isAppEnabled')->willReturn(true);
		$this->availability->method('queue')->willReturn(new \stdClass());
		$this->availability->method('refreshDaemonCheck')->willReturn(['ok' => true, 'at' => 1, 'message' => 'ok']);
		$this->availability->method('websocketUrl')->willReturn('wss://cloud.example.com/push/ws');
		$this->availability->method('effectiveTransport')->willReturn('notify_push');
	}

	public function testAHealthyPushStackPasses(): void {
		$this->healthyNotifyPush();

		$result = $this->check->run();

		$this->assertTrue($result['ok']);
		$this->assertTrue($result['server_supported']);
		$this->assertTrue($result['notify_push']['ok']);
		$this->assertTrue($result['notify_push']['websocket_secure']);
	}

	public function testAPre32ServerFailsTheCheck(): void {
		$this->healthyNotifyPush();

		$result = $this->checkWithServerSupport(false)->run();

		$this->assertFalse($result['ok']);
		$this->assertFalse($result['server_supported']);
	}

	public function testAMissingNotifyPushFailsInAutoMode(): void {
		$this->availability->method('isAppEnabled')->willReturn(false);
		$this->availability->method('queue')->willReturn(null);
		$this->availability->method('websocketUrl')->willReturn(null);
		$this->availability->method('effectiveTransport')->willReturn('polling');
		$this->config->method('transportMode')->willReturn('auto');

		$result = $this->check->run();

		$this->assertFalse($result['notify_push']['ok']);
		$this->assertFalse($result['ok']);
	}

	public function testAPollingPinnedInstancePassesWithoutNotifyPush(): void {
		$this->availability->method('isAppEnabled')->willReturn(false);
		$this->availability->method('queue')->willReturn(null);
		$this->availability->method('websocketUrl')->willReturn(null);
		$this->availability->method('effectiveTransport')->willReturn('polling');
		$this->config->method('transportMode')->willReturn('polling');

		$result = $this->check->run();

		$this->assertTrue($result['ok']);
		$this->assertStringContainsString('pinned to polling', $result['notify_push']['message']);
	}

	public function testAnInsecureWebsocketEndpointIsFlaggedButPasses(): void {
		$this->availability->method('isAppEnabled')->willReturn(true);
		$this->availability->method('queue')->willReturn(new \stdClass());
		$this->availability->method('refreshDaemonCheck')->willReturn(['ok' => true, 'at' => 1, 'message' => 'ok']);
		$this->availability->method('websocketUrl')->willReturn('ws://localhost:7867/ws');
		$this->availability->method('effectiveTransport')->willReturn('notify_push');

		$notifyPush = $this->check->run()['notify_push'];

		$this->assertTrue($notifyPush['ok']);
		$this->assertFalse($notifyPush['websocket_secure']);
		$this->assertStringContainsString('wss://', $notifyPush['message']);
	}

	public function testANeverSeenConnectorIsReportedButDoesNotFail(): void {
		$this->healthyNotifyPush();
		$this->config->method('ackAt')->willReturn(0);
		$this->config->method('ackCursor')->willReturn(0);

		$result = $this->check->run();

		$this->assertTrue($result['ok']);
		$this->assertFalse($result['connector']['seen_recently']);
		$this->assertStringContainsString('not acknowledged', $result['connector']['message']);
	}

	public function testARecentAckMeansTheConnectorIsAlive(): void {
		$this->healthyNotifyPush();
		$this->config->method('ackAt')->willReturn(self::NOW - 120);
		$this->config->method('ackCursor')->willReturn(9651);

		$connector = $this->check->run()['connector'];

		$this->assertTrue($connector['seen_recently']);
		$this->assertStringContainsString('9651', $connector['message']);
		$this->assertStringContainsString('120 s ago', $connector['message']);
	}

	public function testALongSilenceIsCalledOut(): void {
		$this->healthyNotifyPush();
		$this->config->method('ackAt')->willReturn(self::NOW - 90000);
		$this->config->method('ackCursor')->willReturn(42);

		$connector = $this->check->run()['connector'];

		$this->assertFalse($connector['seen_recently']);
		$this->assertStringContainsString('silent for 25 h', $connector['message']);
	}
}
