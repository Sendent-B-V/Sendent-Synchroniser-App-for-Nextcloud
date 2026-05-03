<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Command\Rooms;

use OCA\SendentSynchroniser\Service\Room\RoomGroupService;
use OCA\SendentSynchroniser\Service\Room\RoomValidationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CreateGroup extends Command {
	public function __construct(private RoomGroupService $groups) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:rooms:groups:create')
			->setDescription('Create a room group.')
			->addArgument('id', InputArgument::REQUIRED)
			->addArgument('name', InputArgument::REQUIRED)
			->addOption('description', null, InputOption::VALUE_REQUIRED);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$g = $this->groups->create([
				'id' => $input->getArgument('id'),
				'name' => $input->getArgument('name'),
				'description' => $input->getOption('description'),
			]);
			$output->writeln('<info>Group created:</info> ' . $g->getId());
			return Command::SUCCESS;
		} catch (RoomValidationException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 2;
		}
	}
}
