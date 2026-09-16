<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\Constants;
use Psr\Log\LoggerInterface;

/**
 * Publishes one signal as a notify_push custom message to the bot account.
 * 'user' is the RECIPIENT (whose websockets get the frame); changed users
 * ride inside body.refs. Wire format: `sendent_sync {json}`.
 *
 * Fire-and-forget: false means "not delivered to the queue", so callers leave
 * the watermark untouched and the refs surface again.
 */
class NotifyPushTransport {

	public function __construct(
		private NotifyPushAvailability $availability,
		private ChangeNotificationConfig $config,
		private LoggerInterface $logger,
	) {}

	/** @param array<string, mixed> $signal */
	public function publish(array $signal): bool {
		return $this->push(Constants::CN_MESSAGE_NAME, $signal);
	}

	/** Settings-page publish-test probe. */
	public function publishPing(array $body): bool {
		return $this->push(Constants::CN_PING_MESSAGE_NAME, $body);
	}

	/** @param array<string, mixed> $body */
	private function push(string $message, array $body): bool {
		if ($this->config->transportMode() === Constants::TRANSPORT_POLLING) {
			// Polling pinned: nobody listens for frames, so don't spend a Redis publish.
			return false;
		}

		$botUser = $this->config->botUser();
		if ($botUser === '') {
			return false;
		}

		$queue = $this->availability->queue();
		if ($queue === null) {
			return false;
		}

		try {
			$queue->push('notify_custom', [
				'user' => $botUser,
				'message' => $message,
				'body' => $body,
			]);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('notify_push publish failed: ' . $e->getMessage(), [
				'exception' => $e,
				'app' => 'sendentsynchroniser',
			]);
			return false;
		}
	}
}
