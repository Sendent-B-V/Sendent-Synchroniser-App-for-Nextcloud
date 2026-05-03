<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Command\Rooms;

use OCA\SendentSynchroniser\Service\Room\RoomGroupService;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class DeleteGroup extends Command {
	public function __construct(private RoomGroupService $groups) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:rooms:groups:delete')
			->setDescription('Delete a group. Rooms in the group are unassigned (not deleted).')
			->addArgument('id', InputArgument::REQUIRED)
			->addOption('force', 'f', InputOption::VALUE_NONE);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$id = $input->getArgument('id');
		if (!$input->getOption('force')) {
			$helper = $this->getHelper('question');
			$q = new ConfirmationQuestion("Delete group \"$id\"? Rooms in the group will be unassigned. [y/N] ", false);
			if (!$helper->ask($input, $output, $q)) {
				$output->writeln('<comment>Aborted.</comment>');
				return Command::SUCCESS;
			}
		}
		try {
			$this->groups->delete($id);
			$output->writeln('<info>Deleted group:</info> ' . $id);
			return Command::SUCCESS;
		} catch (DoesNotExistException) {
			$output->writeln('<error>Group not found.</error>');
			return 3;
		}
	}
}
