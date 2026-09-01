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

	/** @var ChangeNotificationConfig&MockObject */
	private $config;

	/** @var NotifyPushAvailability&MockObject */
	private $availability;

	/** @var ITimeFactory&MockObject */
	private $time;

	/** @var array<string, mixed> */
	private array $tlsResult = ['ok' => true, 'reachable' => true, 'message' => 'verified TLS', 'issuer' => 'Let\'s Encrypt', 'expires_in_days' => 60];

	private bool $tcpResult = true;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(ChangeNotificationConfig::class);
		$this->availability = $this->createMock(NotifyPushAvailability::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(1755676800);
	}

	private function check(): ConnectorSetupCheck {
		return new class($this->config, $this->availability, $this->time, $this->tlsResult, $this->tcpResult) extends ConnectorSetupCheck {
			/** @var array<string, mixed> */
			private array $tls;
			private bool $tcp;

			public function __construct($config, $availability, $time, array $tls, bool $tcp) {
				parent::__construct($config, $availability, $time);
				$this->tls = $tls;
				$this->tcp = $tcp;
			}

			protected function probeTls(string $host, int $port): array {
				return $this->tls;
			}

			protected function probeTcp(string $host, int $port): bool {
				return $this->tcp;
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

	public function testAllGreen(): void {
		$this->healthyNotifyPush();
		$this->config->method('connectorUrl')->willReturn('https://connector.example.com');

		$result = $this->check()->run();

		$this->assertTrue($result['ok']);
		$this->assertTrue($result['notify_push']['ok']);
		$this->assertTrue($result['notify_push']['websocket_secure']);
		$this->assertTrue($result['connector']['ok']);
		$this->assertTrue($result['connector']['tls_valid']);
		$this->assertSame(60, $result['connector']['tls_expires_in_days']);
		$this->assertFalse($result['connector']['tls_expiring_soon']);
	}

	public function testAnExpiringCertificateWarns(): void {
		$this->healthyNotifyPush();
		$this->config->method('connectorUrl')->willReturn('https://connector.example.com');
		$this->tlsResult['expires_in_days'] = 12;

		$connector = $this->check()->run()['connector'];

		$this->assertTrue($connector['ok']);
		$this->assertTrue($connector['tls_expiring_soon']);
		$this->assertStringContainsString('12 days', $connector['message']);
	}

	public function testAFailedHandshakeFailsLayerTwo(): void {
		$this->healthyNotifyPush();
		$this->config->method('connectorUrl')->willReturn('https://connector.example.com');
		$this->tlsResult = ['ok' => false, 'reachable' => true, 'message' => 'TLS handshake failed: self-signed certificate', 'issuer' => null, 'expires_in_days' => null];

		$result = $this->check()->run();

		$this->assertFalse($result['ok']);
		$this->assertFalse($result['connector']['tls_valid']);
		$this->assertTrue($result['connector']['reachable']);
		$this->assertStringContainsString('self-signed', $result['connector']['message']);
	}

	public function testAnHttpAddressFailsButReportsReachability(): void {
		$this->healthyNotifyPush();
		$this->config->method('connectorUrl')->willReturn('http://connector.internal:8080');

		$connector = $this->check()->run()['connector'];

		$this->assertFalse($connector['ok']);
		$this->assertFalse($connector['https']);
		$this->assertTrue($connector['reachable']);
		$this->assertStringContainsString('not https', $connector['message']);
	}

	public function testAnUnconfiguredAddressFails(): void {
		$this->healthyNotifyPush();
		$this->config->method('connectorUrl')->willReturn('');

		$connector = $this->check()->run()['connector'];

		$this->assertFalse($connector['ok']);
		$this->assertFalse($connector['configured']);
	}

	public function testAPollingPinnedInstancePassesLayerOneWithoutNotifyPush(): void {
		$this->availability->method('isAppEnabled')->willReturn(false);
		$this->availability->method('queue')->willReturn(null);
		$this->availability->method('websocketUrl')->willReturn(null);
		$this->availability->method('effectiveTransport')->willReturn('polling');
		$this->config->method('transportMode')->willReturn('polling');
		$this->config->method('connectorUrl')->willReturn('https://connector.example.com');

		$notifyPush = $this->check()->run()['notify_push'];

		$this->assertTrue($notifyPush['ok']);
		$this->assertStringContainsString('pinned to polling', $notifyPush['message']);
	}

	public function testAMissingNotifyPushFailsLayerOneInAutoMode(): void {
		$this->availability->method('isAppEnabled')->willReturn(false);
		$this->availability->method('queue')->willReturn(null);
		$this->availability->method('websocketUrl')->willReturn(null);
		$this->availability->method('effectiveTransport')->willReturn('polling');
		$this->config->method('transportMode')->willReturn('auto');
		$this->config->method('connectorUrl')->willReturn('https://connector.example.com');

		$result = $this->check()->run();

		$this->assertFalse($result['notify_push']['ok']);
		$this->assertFalse($result['ok']);
	}

	public function testAnInsecureWebsocketEndpointIsFlaggedInTheMessage(): void {
		$this->availability->method('isAppEnabled')->willReturn(true);
		$this->availability->method('queue')->willReturn(new \stdClass());
		$this->availability->method('refreshDaemonCheck')->willReturn(['ok' => true, 'at' => 1, 'message' => 'ok']);
		$this->availability->method('websocketUrl')->willReturn('ws://localhost:7867/ws');
		$this->availability->method('effectiveTransport')->willReturn('notify_push');
		$this->config->method('connectorUrl')->willReturn('https://connector.example.com');

		$notifyPush = $this->check()->run()['notify_push'];

		$this->assertTrue($notifyPush['ok']);
		$this->assertFalse($notifyPush['websocket_secure']);
		$this->assertStringContainsString('wss://', $notifyPush['message']);
	}
}
