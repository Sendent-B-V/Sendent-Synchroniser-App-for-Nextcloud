<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Cron;

use OCA\SendentSynchroniser\Db\SequenceMapper;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushTransport;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Every cron run: one hint, and the DB sequence table's prune.
 *
 * Nextcloud has no after-commit hook, so a long-running process (cron jobs,
 * background-job workers, occ) that writes calendars sends its hints when it
 * exits; its rows are visible long before. This hint, like a lost one, is
 * covered by the next: the Connector reads the feed from its own cursor.
 */
class ChangeNotificationMaintenance extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private NotifyPushTransport $transport,
		private SequenceMapper $sequence,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);

		$this->setInterval(60);
	}

	protected function run($arguments): void {
		try {
			$this->transport->publishHint();
		} catch (\Throwable $e) {
			$this->logger->error('Change hint from cron failed: ' . $e->getMessage(), [
				'exception' => $e,
				'app' => 'sendentsynchroniser',
			]);
		}

		try {
			$this->sequence->prune();
		} catch (\Throwable $e) {
			$this->logger->error('Sequence prune failed: ' . $e->getMessage(), [
				'exception' => $e,
				'app' => 'sendentsynchroniser',
			]);
		}
	}
}
