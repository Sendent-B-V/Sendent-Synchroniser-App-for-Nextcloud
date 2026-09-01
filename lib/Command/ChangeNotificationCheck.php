<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Command;

use OCA\SendentSynchroniser\Service\ChangeNotification\ConnectorSetupCheck;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ sendentsynchroniser:cn-check — the settings page's setup check for
 * terminals and scripts. The exit code reflects notify_push health, where
 * pinned-to-polling counts as healthy; the connector line is informational,
 * derived from the Connector's own /notify/ack calls. Network-touching
 * (daemon probe), so it is a separate command rather than a cn-status line.
 */
class ChangeNotificationCheck extends Command {

	public function __construct(
		private ConnectorSetupCheck $setupCheck,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:cn-check')
			->setDescription('Check notify_push availability and whether the Exchange Connector is reading the feed');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$result = $this->setupCheck->run();
		$np = $result['notify_push'];
		$conn = $result['connector'];

		$mark = static fn (bool $ok): string => $ok ? 'OK ' : 'FAIL';

		$output->writeln('Layer 1 - notify_push: ' . $mark($np['ok']) . ' ' . $np['message']);
		$output->writeln('  app_enabled: ' . $mark($np['app_enabled']));
		$output->writeln('  queue_available: ' . $mark($np['queue_available']));
		$output->writeln('  daemon: ' . $mark($np['daemon']['ok']) . ' ' . $np['daemon']['message']);
		$output->writeln('  websocket: ' . ($np['ws_url'] ?? '(none)') . ($np['websocket_secure'] ? ' (wss OK)' : ' (not wss)'));
		$output->writeln('  effective_transport: ' . $np['effective_transport']);

		$output->writeln('Connector (informational): ' . $conn['message']);
		if ($conn['url'] !== '') {
			$output->writeln('  configured address: ' . $conn['url']);
		}

		return $result['ok'] ? 0 : 1;
	}
}
