<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Command;

use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\CursorService;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushAvailability;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ sendentsynchroniser:cn-status — configuration and feed state as plain
 * `key: value` lines, for support. `cn-check` probes the daemon.
 */
class ChangeNotificationStatus extends Command {

	public function __construct(
		private ChangeNotificationConfig $config,
		private ChangeLedgerService $ledger,
		private CursorService $cursor,
		private NotifyPushAvailability $availability,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:cn-status')
			->setDescription('Show change-notification transport and feed status');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$output->writeln('transport_mode: ' . $this->config->transportMode());
		$output->writeln('effective_transport: ' . $this->availability->effectiveTransport());
		$output->writeln('ws_url: ' . ($this->availability->websocketUrl() ?? '(none)'));
		$output->writeln('bot_user: ' . ($this->config->botUser() ?: '(unset)'));
		$output->writeln('poll_interval_s: ' . $this->config->pollInterval());
		$output->writeln('feed_collections: ' . $this->ledger->countCollections());
		$output->writeln('cursor: ' . $this->cursor->current());

		return 0;
	}
}
