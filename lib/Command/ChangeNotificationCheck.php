<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Command;

use OCA\SendentSynchroniser\Service\ChangeNotification\ConnectorSetupCheck;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ sendentsynchroniser:cn-check — the settings page's layered setup check
 * for terminals and scripts. Exit 0 when both layers pass, 1 otherwise.
 * Network-touching (daemon probe + connector TLS handshake), so it is its own
 * command instead of a cn-status line.
 */
class ChangeNotificationCheck extends Command {

	public function __construct(
		private ConnectorSetupCheck $setupCheck,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:cn-check')
			->setDescription('Check notify_push availability and the Exchange Connector TLS setup');
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

		$output->writeln('Layer 2 - connector TLS: ' . $mark($conn['ok']) . ' ' . $conn['message']);
		$output->writeln('  url: ' . ($conn['url'] !== '' ? $conn['url'] : '(unset)'));
		$output->writeln('  https: ' . $mark($conn['https']) . '  reachable: ' . $mark($conn['reachable']) . '  tls_valid: ' . $mark($conn['tls_valid']));
		if ($conn['tls_issuer'] !== null) {
			$output->writeln('  issuer: ' . $conn['tls_issuer'] . '  expires_in_days: ' . ($conn['tls_expires_in_days'] ?? '?'));
		}

		return $result['ok'] ? 0 : 1;
	}
}
