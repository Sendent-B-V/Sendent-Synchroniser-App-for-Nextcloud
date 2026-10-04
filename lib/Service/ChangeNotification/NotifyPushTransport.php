<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\Constants;
use Psr\Log\LoggerInterface;

/**
 * Sends the Connector a hint as a notify_push custom message to the bot
 * account ('user' is the RECIPIENT). The hint has no body, so the frame is
 * the bare text `sendent_sync` — the same pattern as the body-less
 * notify_file hint desktop clients get: it only says "check now", and the
 * Connector checks by reading /notify/changes from its own cursor.
 *
 * Fire-and-forget (Redis PUBLISH): a lost hint costs nothing, because any
 * later hint triggers the same full check.
 */
class NotifyPushTransport {

	public function __construct(
		private NotifyPushAvailability $availability,
		private ChangeNotificationConfig $config,
		private LoggerInterface $logger,
	) {}

	public function publishHint(): bool {
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
			$queue->push('notify_custom', ['user' => $botUser, 'message' => Constants::CN_MESSAGE_NAME]);
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
