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

	private function recordingQueue(): object {
		return new class {
			public array $pushed = [];
			public function push(string $channel, array $message): void {
				$this->pushed[] = [$channel, $message];
			}
		};
	}

	/**
	 * Pins the queue-message contract verified against notify_push's source:
	 * channel 'notify_custom', 'user' is the RECIPIENT (the bot). Without a
	 * 'body' the daemon sends the bare text frame "sendent_sync", like the
	 * body-less notify_file hint desktop clients get.
	 */
	public function testAHintIsABareNotifyCustomMessageToTheBot(): void {
		$this->config->method('transportMode')->willReturn('auto');
		$this->config->method('botUser')->willReturn('sendent-sync');
		$queue = $this->recordingQueue();
		$this->availability->method('queue')->willReturn($queue);

		$this->assertTrue($this->transport->publishHint());
		$this->assertSame([['notify_custom', ['user' => 'sendent-sync', 'message' => 'sendent_sync']]], $queue->pushed);
	}

	public function testPinnedPollingModePublishesNothing(): void {
		$this->config->method('transportMode')->willReturn('polling');
		$this->config->method('botUser')->willReturn('sendent-sync');
		$this->availability->expects($this->never())->method('queue');

		$this->assertFalse($this->transport->publishHint());
	}

	public function testAnUnsetBotUserPublishesNothing(): void {
		$this->config->method('transportMode')->willReturn('auto');
		$this->config->method('botUser')->willReturn('');

		$this->assertFalse($this->transport->publishHint());
	}

	public function testAMissingQueuePublishesNothing(): void {
		$this->config->method('transportMode')->willReturn('auto');
		$this->config->method('botUser')->willReturn('sendent-sync');
		$this->availability->method('queue')->willReturn(null);

		$this->assertFalse($this->transport->publishHint());
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

		$this->assertFalse($this->transport->publishHint());
	}
}
