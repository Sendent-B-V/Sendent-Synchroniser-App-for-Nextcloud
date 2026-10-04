<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushAvailability;
use OCA\SendentSynchroniser\Service\ChangeNotification\SetupCheck;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SetupCheckTest extends TestCase {

	/** @var ChangeNotificationConfig&MockObject */
	private $config;

	/** @var NotifyPushAvailability&MockObject */
	private $availability;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(ChangeNotificationConfig::class);
		$this->availability = $this->createMock(NotifyPushAvailability::class);
	}

	/**
	 * Pins the NC-version capability probe so these tests behave identically
	 * on every server checkout the suite runs against.
	 */
	private function check(bool $serverSupported = true): SetupCheck {
		return new class($this->config, $this->availability, $serverSupported) extends SetupCheck {
			public function __construct($config, $availability, private bool $supported) {
				parent::__construct($config, $availability);
			}

			protected function serverSupportsChangeEvents(): bool {
				return $this->supported;
			}
		};
	}

	private function notifyPush(bool $daemonOk, string $wsUrl = 'wss://cloud.example.com/push/ws'): void {
		$this->availability->method('isAppEnabled')->willReturn(true);
		$this->availability->method('queue')->willReturn(new \stdClass());
		$this->availability->method('probeDaemon')->willReturn(['ok' => $daemonOk, 'message' => $daemonOk ? 'ok' : 'daemon unreachable']);
		$this->availability->method('websocketUrl')->willReturn($wsUrl);
		$this->availability->method('effectiveTransport')->willReturn('notify_push');
	}

	private function noNotifyPush(string $mode): void {
		$this->availability->method('isAppEnabled')->willReturn(false);
		$this->availability->method('queue')->willReturn(null);
		$this->availability->method('websocketUrl')->willReturn(null);
		$this->availability->method('effectiveTransport')->willReturn('polling');
		$this->config->method('transportMode')->willReturn($mode);
	}

	public function testAHealthyPushStackPasses(): void {
		$this->notifyPush(true);

		$result = $this->check()->run();

		$this->assertTrue($result['ok']);
		$this->assertTrue($result['server_supported']);
		$this->assertTrue($result['notify_push']['ok']);
		$this->assertTrue($result['notify_push']['websocket_secure']);
	}

	public function testAPre32ServerFailsTheCheck(): void {
		$this->notifyPush(true);

		$result = $this->check(false)->run();

		$this->assertFalse($result['ok']);
		$this->assertFalse($result['server_supported']);
	}

	public function testADeadDaemonFailsTheCheck(): void {
		$this->notifyPush(false);

		$result = $this->check()->run();

		$this->assertFalse($result['ok']);
		$this->assertStringContainsString('unreachable', $result['notify_push']['message']);
	}

	public function testAMissingNotifyPushFailsInAutoMode(): void {
		$this->noNotifyPush('auto');

		$result = $this->check()->run();

		$this->assertFalse($result['notify_push']['ok']);
		$this->assertFalse($result['ok']);
	}

	public function testAPollingPinnedInstancePassesWithoutNotifyPush(): void {
		$this->noNotifyPush('polling');

		$result = $this->check()->run();

		$this->assertTrue($result['ok']);
		$this->assertStringContainsString('pinned to polling', $result['notify_push']['message']);
	}

	public function testAnInsecureWebsocketEndpointIsFlaggedButPasses(): void {
		$this->notifyPush(true, 'ws://localhost:7867/ws');

		$notifyPush = $this->check()->run()['notify_push'];

		$this->assertTrue($notifyPush['ok']);
		$this->assertFalse($notifyPush['websocket_secure']);
		$this->assertStringContainsString('wss://', $notifyPush['message']);
	}
}
