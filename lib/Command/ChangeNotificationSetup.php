<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Command;

use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Unattended install: occ sendentsynchroniser:cn-setup
 *     --bot-user=sendent-sync --transport=auto
 */
class ChangeNotificationSetup extends Command {

	public function __construct(
		private ChangeNotificationConfig $config,
		private IUserManager $userManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:cn-setup')
			->setDescription('Configure change notifications for the Exchange Connector')
			->addOption('bot-user', null, InputOption::VALUE_REQUIRED, 'User the Connector authenticates as')
			->addOption('transport', null, InputOption::VALUE_REQUIRED, 'auto (notify_push when configured, else polling) | polling');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$botUser = $input->getOption('bot-user');
		if (is_string($botUser) && $botUser !== '') {
			if (!$this->userManager->userExists($botUser)) {
				$output->writeln('<error>User "' . $botUser . '" does not exist. Create it and its app password for the Connector first:</error>');
				$output->writeln('  occ user:add ' . $botUser);
				$output->writeln('  occ user:auth-tokens:add ' . $botUser);
				return 1;
			}
			$this->config->setBotUser($botUser);
			$output->writeln('Bot user set to "' . $botUser . '"');
		}

		$transport = $input->getOption('transport');
		if (is_string($transport) && $transport !== '') {
			try {
				$this->config->setTransportMode($transport);
			} catch (\InvalidArgumentException $e) {
				$output->writeln('<error>' . $e->getMessage() . '</error>');
				return 1;
			}
			$output->writeln('Transport mode set to "' . $transport . '"');
		}

		if ((!is_string($botUser) || $botUser === '') && (!is_string($transport) || $transport === '')) {
			$output->writeln('Nothing to do. Options: --bot-user=<uid>, --transport=auto|polling');
		}

		return 0;
	}
}
