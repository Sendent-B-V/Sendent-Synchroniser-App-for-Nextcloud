<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Command;

use OCA\SendentSynchroniser\Db\SequenceMapper;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Unattended install: occ sendentsynchroniser:cn-setup
 *     --bot-user=sendent-sync --transport=auto
 *
 * --reseed lifts the DB sequence above the ledger's high-water mark — needed
 * once if an instance permanently loses its distributed cache and the DB
 * sequence would otherwise restart below already-published cursors.
 */
class ChangeNotificationSetup extends Command {

	public function __construct(
		private ChangeNotificationConfig $config,
		private IUserManager $userManager,
		private SequenceMapper $sequence,
		private ChangeLedgerService $ledger,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:cn-setup')
			->setDescription('Configure change notifications for the Exchange Connector')
			->addOption('bot-user', null, InputOption::VALUE_REQUIRED, 'User the Connector authenticates as')
			->addOption('create', null, InputOption::VALUE_NONE, 'Create the bot user if it does not exist (random password — generate an app password for the Connector afterwards)')
			->addOption('transport', null, InputOption::VALUE_REQUIRED, 'auto | notify_push | polling')
			->addOption('reseed', null, InputOption::VALUE_NONE, 'Lift the DB sequence above the ledger high-water mark');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$botUser = $input->getOption('bot-user');
		if (is_string($botUser) && $botUser !== '') {
			if (!$this->userManager->userExists($botUser)) {
				if (!$input->getOption('create')) {
					$output->writeln('<error>User "' . $botUser . '" does not exist. Create it first (occ user:add ' . $botUser . ') or pass --create.</error>');
					return 1;
				}
				// Random throwaway login password: the account is only ever
				// used via an app password the admin generates as this user.
				try {
					$created = $this->userManager->createUser($botUser, base64_encode(random_bytes(36)));
				} catch (\Throwable $e) {
					$output->writeln('<error>Could not create user "' . $botUser . '": ' . $e->getMessage() . '</error>');
					return 1;
				}
				if ($created === false) {
					$output->writeln('<error>Could not create user "' . $botUser . '"</error>');
					return 1;
				}
				$output->writeln('Created bot user "' . $botUser . '". Log in as it once to generate an app password for the Connector.');
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

		if ($input->getOption('reseed')) {
			// Floor at the flushed watermark too: deleting high-seq rows (e.g.
			// loadtest cleanup) can leave the ledger's max below cursors that
			// were already published to readers.
			$mark = max($this->ledger->highWaterMark(), $this->config->flushedSeq());
			$this->sequence->reseedAbove($mark);
			$output->writeln('Sequence reseeded above ' . $mark);
		}

		if ((!is_string($botUser) || $botUser === '') && (!is_string($transport) || $transport === '') && !$input->getOption('reseed')) {
			$output->writeln('Nothing to do. Options: --bot-user=<uid> [--create], --transport=auto|notify_push|polling, --reseed');
		}

		return 0;
	}
}
