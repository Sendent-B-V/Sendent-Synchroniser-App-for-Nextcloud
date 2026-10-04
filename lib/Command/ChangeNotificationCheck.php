<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Command;

use OCA\SendentSynchroniser\Service\ChangeNotification\SetupCheck;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ sendentsynchroniser:cn-check — the settings page's "Run test", with an
 * exit code for monitoring and post-deploy gates (0 = usable).
 */
class ChangeNotificationCheck extends Command {

	public function __construct(
		private SetupCheck $setupCheck,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:cn-check')
			->setDescription('Check that this server has the change events and whether notify_push is usable');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$result = $this->setupCheck->run();
		$np = $result['notify_push'];

		$mark = static fn (bool $ok): string => $ok ? 'OK ' : 'FAIL';

		$output->writeln('Server: ' . $mark($result['server_supported'])
			. ($result['server_supported']
				? ' Nextcloud 32+ change events available'
				: ' this Nextcloud is older than 32 — change notifications require NC 32 or later'));
		$output->writeln('notify_push: ' . $mark($np['ok']) . ' ' . $np['message']);
		$output->writeln('  app_enabled: ' . $mark($np['app_enabled']));
		$output->writeln('  queue_available: ' . $mark($np['queue_available']));
		$output->writeln('  daemon: ' . $mark($np['daemon']['ok']) . ' ' . $np['daemon']['message']);
		$output->writeln('  websocket: ' . ($np['ws_url'] ?? '(none)') . ($np['websocket_secure'] ? ' (wss OK)' : ' (not wss)'));
		$output->writeln('  effective_transport: ' . $np['effective_transport']);

		return $result['ok'] ? 0 : 1;
	}
}
