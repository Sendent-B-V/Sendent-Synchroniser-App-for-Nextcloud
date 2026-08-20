<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Cron;

use OCA\SendentSynchroniser\Db\SequenceMapper;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\SignalPublisher;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Sweeper behind the leading-edge batch window: when traffic stops, whatever
 * accumulated after the last in-request flush would sit unpublished forever.
 * This job publishes it within one cron interval - about a minute with
 * webcron/AJAX cron, and commonly five minutes with the recommended system
 * cron, which is the real trailing-latency bound (see plan deviation 2).
 *
 * Also the housekeeping hook for the DB sequence table.
 */
class FlushSignalBatch extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private SignalPublisher $publisher,
		private SequenceMapper $sequence,
		private ChangeNotificationConfig $config,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);

		// Every cron run; flush() is a no-op when nothing is pending.
		$this->setInterval(60);
	}

	protected function run($arguments): void {
		// With no bot user and no webhook there is no signal channel: flush()
		// would read rows, fail to publish, never advance the watermark, and
		// repeat forever. Polling readers use the ledger directly, so skip.
		if ($this->config->botUser() !== '' || $this->config->webhookEnabled()) {
			try {
				$this->publisher->flush();
			} catch (\Throwable $e) {
				$this->logger->error('Sweeper flush failed: ' . $e->getMessage(), [
					'exception' => $e,
					'app' => 'sendentsynchroniser',
				]);
			}
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
