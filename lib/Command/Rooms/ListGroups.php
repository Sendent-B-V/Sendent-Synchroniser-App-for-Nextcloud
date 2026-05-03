<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.vandebroek@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Command\Rooms;

use OCA\SendentSynchroniser\Service\Room\RoomGroupService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListGroups extends Command {
	public function __construct(private RoomGroupService $groups) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:rooms:groups:list')
			->setDescription('List all room groups.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$table = new Table($output);
		$table->setHeaders(['id', 'name', 'description']);
		foreach ($this->groups->listAll() as $g) {
			$table->addRow([$g->getId(), $g->getName() ?? '', $g->getDescription() ?? '']);
		}
		$table->render();
		return Command::SUCCESS;
	}
}
