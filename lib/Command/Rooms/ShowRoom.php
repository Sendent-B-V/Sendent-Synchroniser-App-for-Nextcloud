<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.vandebroek@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Command\Rooms;

use OCA\SendentSynchroniser\Db\RoomBindingMapper;
use OCA\SendentSynchroniser\Service\Room\RoomService;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ShowRoom extends Command {
	public function __construct(
		private RoomService $rooms,
		private RoomBindingMapper $bindings,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:rooms:show')
			->setDescription('Show details for one room (incl. binding state if any).')
			->addArgument('id', InputArgument::REQUIRED, 'Room id');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$id = $input->getArgument('id');
		try {
			$room = $this->rooms->get($id);
		} catch (DoesNotExistException) {
			$output->writeln('<error>Room not found: ' . $id . '</error>');
			return 3;
		}
		$output->writeln(json_encode($room->jsonSerialize(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		$binding = $this->bindings->findByRoomIdOrNull($id);
		if ($binding !== null) {
			$output->writeln('');
			$output->writeln('<info>Binding:</info>');
			$output->writeln(json_encode($binding->jsonSerialize(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		} else {
			$output->writeln('');
			$output->writeln('<comment>No binding (unbound room).</comment>');
		}
		return Command::SUCCESS;
	}
}
