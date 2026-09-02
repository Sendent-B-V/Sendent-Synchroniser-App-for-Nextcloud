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
 * Sweeper behind the leading-edge batch window: publishes whatever
 * accumulated after the last in-request flush within one cron interval —
 * about a minute with webcron/AJAX cron, five minutes with system cron.
 * Also houses the DB sequence table's prune.
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
		// No signal channel exists without a bot user (or while pinned to
		// polling, which NotifyPushTransport::publish() also refuses); skip so
		// flush() doesn't spin forever failing to advance the watermark.
		$pushChannel = $this->config->transportMode() !== \OCA\SendentSynchroniser\Constants::TRANSPORT_POLLING
			&& $this->config->botUser() !== '';
		if ($pushChannel || $this->config->webhookEnabled()) {
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
