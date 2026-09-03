<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Command;

use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\CursorService;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushAvailability;
use OCA\SendentSynchroniser\Service\ChangeNotification\SignalMetrics;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ sendentsynchroniser:cn-status — the admin settings' diagnostics block
 * for people who live in a terminal, and the monitoring hook (values are
 * plain `key: value` lines, one per metric).
 */
class ChangeNotificationStatus extends Command {

	public function __construct(
		private ChangeNotificationConfig $config,
		private ChangeLedgerService $ledger,
		private CursorService $cursor,
		private NotifyPushAvailability $availability,
		private SignalMetrics $metrics,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:cn-status')
			->setDescription('Show change-notification transport, ledger and signal status');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$daemon = $this->availability->cachedDaemonCheck();
		$hour = $this->metrics->lastHour();
		$current = $this->cursor->current();
		$ack = $this->config->ackCursor();

		$output->writeln('transport_mode: ' . $this->config->transportMode());
		$output->writeln('effective_transport: ' . $this->availability->effectiveTransport());
		$output->writeln('notify_push_app: ' . ($this->availability->isAppEnabled() ? 'enabled' : 'missing'));
		$output->writeln('notify_push_queue: ' . ($this->availability->queue() !== null ? 'available' : 'unavailable'));
		$output->writeln('notify_push_daemon: ' . ($daemon['ok'] ? 'ok' : ('failed (' . $daemon['message'] . ')')));
		$output->writeln('bot_user: ' . ($this->config->botUser() ?: '(unset)'));
		$output->writeln('connector_url: ' . ($this->config->connectorUrl() ?: '(unset)'));
		$output->writeln('batch_window_s: ' . $this->config->batchWindow());
		$output->writeln('max_refs_per_signal: ' . $this->config->maxRefsPerSignal());
		$output->writeln('ledger_collections: ' . $this->ledger->countCollections());
		$output->writeln('cursor: ' . $current);
		$output->writeln('flushed_seq: ' . $this->config->flushedSeq());
		$output->writeln('ack_cursor: ' . $ack);
		$output->writeln('connector_lag: ' . max(0, $current - $ack));
		$output->writeln('last_signal_at: ' . $this->config->lastSignalAt());
		$roundTrip = $this->config->roundTrip();
		$output->writeln('publish_test: ' . ($roundTrip['at'] === 0 ? '(never run)' : (($roundTrip['ok'] ? 'ok' : 'failed') . ' (' . $roundTrip['ms'] . ' ms at ' . $roundTrip['at'] . ')')));
		$output->writeln('flushes_last_hour: ' . $hour['flushes']);
		$output->writeln('refs_last_hour: ' . $hour['refs']);
		$output->writeln('truncated_last_hour: ' . $hour['truncated']);
		$output->writeln('max_refs_in_one_signal_last_hour: ' . $hour['max_refs']);

		return 0;
	}
}
