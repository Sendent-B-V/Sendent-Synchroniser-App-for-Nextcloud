<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Command\Rooms;

use OCA\SendentSynchroniser\Service\Room\RoomService;
use OCA\SendentSynchroniser\Service\Room\RoomValidationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CreateRoom extends Command {
	public function __construct(private RoomService $rooms) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:rooms:create')
			->setDescription('Create a new room. No license required (bindings are managed separately).')
			->addArgument('id', InputArgument::REQUIRED, 'Room id (lowercase, kebab-case, 2-64 chars)')
			->addArgument('name', InputArgument::REQUIRED, 'Display name')
			->addOption('email', null, InputOption::VALUE_REQUIRED)
			->addOption('capacity', null, InputOption::VALUE_REQUIRED)
			->addOption('room-number', null, InputOption::VALUE_REQUIRED)
			->addOption('floor', null, InputOption::VALUE_REQUIRED)
			->addOption('address', null, InputOption::VALUE_REQUIRED)
			->addOption('room-type', null, InputOption::VALUE_REQUIRED, 'Default: meeting-room')
			->addOption('description', null, InputOption::VALUE_REQUIRED)
			->addOption('group', null, InputOption::VALUE_REQUIRED, 'Room group id')
			->addOption('facility', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Repeatable. Facility name (e.g. --facility=projector --facility=whiteboard)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$room = $this->rooms->create([
				'id' => $input->getArgument('id'),
				'name' => $input->getArgument('name'),
				'email' => $input->getOption('email'),
				'capacity' => $input->getOption('capacity') !== null ? (int) $input->getOption('capacity') : null,
				'roomNumber' => $input->getOption('room-number'),
				'floor' => $input->getOption('floor'),
				'address' => $input->getOption('address'),
				'roomType' => $input->getOption('room-type') ?? 'meeting-room',
				'description' => $input->getOption('description'),
				'groupId' => $input->getOption('group'),
				'facilities' => $input->getOption('facility') ?? [],
			]);
			$output->writeln('<info>Room created:</info> ' . $room->getId());
			return Command::SUCCESS;
		} catch (RoomValidationException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 2;
		}
	}
}
