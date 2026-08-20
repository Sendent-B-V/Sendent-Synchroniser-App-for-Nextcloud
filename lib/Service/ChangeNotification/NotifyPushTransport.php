<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\Constants;
use Psr\Log\LoggerInterface;

/**
 * Publishes one signal as a notify_push custom message addressed to the bot
 * account. The 'user' field is the RECIPIENT (whose websockets get the frame);
 * the changed users ride inside body.refs. Wire format on the socket:
 * `sendent_sync {json}`.
 *
 * Fire-and-forget by design: false is "not delivered to the queue", and the
 * callers leave the watermark untouched so the refs surface again.
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
			// The admin pinned polling: publishing frames nobody listens for
			// would only spend a Redis publish per window. The ledger alone
			// serves polling readers.
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
