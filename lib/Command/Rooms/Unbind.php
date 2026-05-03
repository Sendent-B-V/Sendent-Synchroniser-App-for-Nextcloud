<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.vandebroek@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Command\Rooms;

use OCA\SendentSynchroniser\Service\Room\BindingService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Unbind extends Command {
	public function __construct(private BindingService $service) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:rooms:unbind')
			->setDescription('Remove a room\'s external binding. Always allowed (cleanup must be possible without a license).')
			->addArgument('room-id', InputArgument::REQUIRED);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->service->clear($input->getArgument('room-id'));
		$output->writeln('<info>Unbound:</info> ' . $input->getArgument('room-id'));
		return Command::SUCCESS;
	}
}
