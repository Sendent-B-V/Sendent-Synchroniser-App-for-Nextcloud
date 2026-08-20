<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Cron;

use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushAvailability;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Keeps the cached daemon reachability current without ever probing from the
 * DAV write path. In `auto` mode this is what flips the effective transport
 * to polling when the daemon dies, and back when it returns.
 */
class NotifyPushSelfTest extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private NotifyPushAvailability $availability,
		private ChangeNotificationConfig $config,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);

		$this->setInterval(Constants::CN_DAEMON_CHECK_TTL);
	}

	protected function run($arguments): void {
		if ($this->config->transportMode() === Constants::TRANSPORT_POLLING) {
			return; // pinned to polling; nothing to test
		}

		try {
			$result = $this->availability->refreshDaemonCheck();
			if (!$result['ok']) {
				$this->logger->info('notify_push self-test failed: ' . $result['message'], [
					'app' => 'sendentsynchroniser',
				]);
			}
		} catch (\Throwable $e) {
			$this->logger->error('notify_push self-test crashed: ' . $e->getMessage(), [
				'exception' => $e,
				'app' => 'sendentsynchroniser',
			]);
		}
	}
}
