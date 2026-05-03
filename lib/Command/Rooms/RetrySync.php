<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Command\Rooms;

use OCA\SendentSynchroniser\Service\Room\BindingService;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RetrySync extends Command {
	public function __construct(private BindingService $service) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:rooms:retry-sync')
			->setDescription('Bump linkVersion and request a fresh initial sync. The Connector picks it up on its next poll.')
			->addArgument('room-id', InputArgument::REQUIRED);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$b = $this->service->retry($input->getArgument('room-id'));
			$output->writeln('<info>Retry queued:</info> linkVersion=' . $b->getLinkVersion() . ', state=' . $b->getState());
			return Command::SUCCESS;
		} catch (DoesNotExistException) {
			$output->writeln('<error>No binding for this room.</error>');
			return 3;
		}
	}
}
