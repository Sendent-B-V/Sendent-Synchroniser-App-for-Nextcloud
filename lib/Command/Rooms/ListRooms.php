<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Command\Rooms;

use OCA\SendentSynchroniser\Db\RoomBindingMapper;
use OCA\SendentSynchroniser\Service\Room\RoomService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListRooms extends Command {
	public function __construct(
		private RoomService $rooms,
		private RoomBindingMapper $bindings,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:rooms:list')
			->setDescription('List all rooms (works without a license; bound rooms show their binding state).')
			->addOption('group', null, InputOption::VALUE_REQUIRED, 'Filter by group id');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$filterGroup = $input->getOption('group');
		$rooms = $this->rooms->listAll();
		if ($filterGroup !== null) {
			$rooms = array_values(array_filter($rooms, static fn ($r) => $r->getGroupId() === $filterGroup));
		}

		$table = new Table($output);
		$table->setHeaders(['id', 'name', 'capacity', 'group', 'active', 'binding']);
		foreach ($rooms as $r) {
			$binding = $this->bindings->findByRoomIdOrNull($r->getId());
			$bindingCol = $binding === null
				? '—'
				: $binding->getKind() . ':' . $binding->getState();
			$table->addRow([
				$r->getId(),
				$r->getName() ?? '',
				$r->getCapacity() ?? '',
				$r->getGroupId() ?? '',
				$r->getActive() ? 'yes' : 'no',
				$bindingCol,
			]);
		}
		$table->render();
		return Command::SUCCESS;
	}
}
