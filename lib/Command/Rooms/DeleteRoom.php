<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.vandebroek@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Command\Rooms;

use OCA\SendentSynchroniser\Service\Room\RoomService;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class DeleteRoom extends Command {
	public function __construct(private RoomService $rooms) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:rooms:delete')
			->setDescription('Delete a room (drops binding row, hidden NC user, CalDAV calendar). Prompts unless --force.')
			->addArgument('id', InputArgument::REQUIRED, 'Room id')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip the confirmation prompt');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$id = $input->getArgument('id');
		if (!$input->getOption('force')) {
			$helper = $this->getHelper('question');
			$question = new ConfirmationQuestion(
				"Delete room \"$id\"? This will drop its binding (if any), hidden NC user, and CalDAV calendar. [y/N] ",
				false,
			);
			if (!$helper->ask($input, $output, $question)) {
				$output->writeln('<comment>Aborted.</comment>');
				return Command::SUCCESS;
			}
		}
		try {
			$this->rooms->delete($id);
			$output->writeln('<info>Deleted:</info> ' . $id);
			return Command::SUCCESS;
		} catch (DoesNotExistException) {
			$output->writeln('<error>Room not found.</error>');
			return 3;
		}
	}
}
