<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushAvailability;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushTransport;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class NotifyPushTransportTest extends TestCase {

	/** @var NotifyPushAvailability&MockObject */
	private $availability;

	/** @var ChangeNotificationConfig&MockObject */
	private $config;

	private NotifyPushTransport $transport;

	protected function setUp(): void {
		parent::setUp();
		$this->availability = $this->createMock(NotifyPushAvailability::class);
		$this->config = $this->createMock(ChangeNotificationConfig::class);
		$this->transport = new NotifyPushTransport($this->availability, $this->config, new NullLogger());
	}

	/**
	 * Pins the queue-message contract verified against notify_push's source:
	 * channel 'notify_custom'; fields user/message/body, where user is the
	 * RECIPIENT (bot) and the changed users ride inside body.refs.
	 */
	public function testPublishPushesTheVerifiedNotifyCustomShape(): void {
		$this->config->method('transportMode')->willReturn('auto');
		$this->config->method('botUser')->willReturn('sendent-sync');

		$captured = null;
		$queue = new class {
			public array $pushed = [];
			public function push(string $channel, array $message): void {
				$this->pushed[] = [$channel, $message];
			}
		};
		$this->availability->method('queue')->willReturn($queue);

		$this->assertTrue($this->transport->publish(['v' => 1, 'refs' => []]));
		$this->assertCount(1, $queue->pushed);
		[$channel, $message] = $queue->pushed[0];
		$this->assertSame('notify_custom', $channel);
		$this->assertSame(['user', 'message', 'body'], array_keys($message));
		$this->assertSame('sendent-sync', $message['user']);
		$this->assertSame('sendent_sync', $message['message']);
		$this->assertSame(['v' => 1, 'refs' => []], $message['body']);
	}

	public function testPinnedPollingModePublishesNothing(): void {
		$this->config->method('transportMode')->willReturn('polling');
		$this->config->method('botUser')->willReturn('sendent-sync');
		$this->availability->expects($this->never())->method('queue');

		$this->assertFalse($this->transport->publish(['v' => 1]));
	}

	public function testAnUnsetBotUserPublishesNothing(): void {
		$this->config->method('transportMode')->willReturn('auto');
		$this->config->method('botUser')->willReturn('');

		$this->assertFalse($this->transport->publish(['v' => 1]));
	}

	public function testAMissingQueuePublishesNothing(): void {
		$this->config->method('transportMode')->willReturn('auto');
		$this->config->method('botUser')->willReturn('sendent-sync');
		$this->availability->method('queue')->willReturn(null);

		$this->assertFalse($this->transport->publish(['v' => 1]));
	}

	public function testAThrowingQueueNeverEscapes(): void {
		$this->config->method('transportMode')->willReturn('auto');
		$this->config->method('botUser')->willReturn('sendent-sync');
		$queue = new class {
			public function push(string $channel, array $message): void {
				throw new \RuntimeException('redis gone');
			}
		};
		$this->availability->method('queue')->willReturn($queue);

		$this->assertFalse($this->transport->publish(['v' => 1]));
	}
}
