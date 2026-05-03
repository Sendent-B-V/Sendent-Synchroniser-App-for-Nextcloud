<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.vandebroek@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Command\Rooms;

use OCA\SendentSynchroniser\Service\Room\RoomService;
use OCA\SendentSynchroniser\Service\Room\RoomValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateRoom extends Command {
	public function __construct(private RoomService $rooms) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:rooms:update')
			->setDescription('Update fields on an existing room. Only options actually passed are updated.')
			->addArgument('id', InputArgument::REQUIRED, 'Room id')
			->addOption('name', null, InputOption::VALUE_REQUIRED)
			->addOption('email', null, InputOption::VALUE_REQUIRED)
			->addOption('capacity', null, InputOption::VALUE_REQUIRED)
			->addOption('room-number', null, InputOption::VALUE_REQUIRED)
			->addOption('floor', null, InputOption::VALUE_REQUIRED)
			->addOption('address', null, InputOption::VALUE_REQUIRED)
			->addOption('room-type', null, InputOption::VALUE_REQUIRED)
			->addOption('description', null, InputOption::VALUE_REQUIRED)
			->addOption('group', null, InputOption::VALUE_REQUIRED, 'Room group id; pass empty string to clear')
			->addOption('active', null, InputOption::VALUE_REQUIRED, 'true|false')
			->addOption('facility', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Replaces facilities array entirely. Repeatable.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$patch = [];
		$map = [
			'name' => 'name', 'email' => 'email',
			'room-number' => 'roomNumber', 'floor' => 'floor', 'address' => 'address',
			'room-type' => 'roomType', 'description' => 'description',
		];
		foreach ($map as $opt => $field) {
			$v = $input->getOption($opt);
			if ($v !== null) {
				$patch[$field] = $v;
			}
		}
		if ($input->getOption('capacity') !== null) {
			$patch['capacity'] = (int) $input->getOption('capacity');
		}
		if ($input->getOption('group') !== null) {
			$g = $input->getOption('group');
			$patch['groupId'] = ($g === '') ? null : $g;
		}
		if ($input->getOption('active') !== null) {
			$patch['active'] = filter_var($input->getOption('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
			if ($patch['active'] === null) {
				$output->writeln('<error>--active must be true or false</error>');
				return 2;
			}
		}
		// Facilities array passed: replace; not passed: leave alone
		if ($input->hasParameterOption('--facility')) {
			$patch['facilities'] = $input->getOption('facility') ?? [];
		}

		if ($patch === []) {
			$output->writeln('<comment>No fields to update.</comment>');
			return Command::SUCCESS;
		}

		try {
			$room = $this->rooms->update($input->getArgument('id'), $patch);
			$output->writeln('<info>Updated:</info> ' . $room->getId());
			return Command::SUCCESS;
		} catch (DoesNotExistException) {
			$output->writeln('<error>Room not found.</error>');
			return 3;
		} catch (RoomValidationException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 2;
		}
	}
}
