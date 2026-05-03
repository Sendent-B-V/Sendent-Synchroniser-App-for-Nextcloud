<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.vandebroek@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Command\Rooms;

use OCA\SendentSynchroniser\Service\Room\PermissionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListPermissions extends Command {
	public function __construct(private PermissionService $perms) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:rooms:permissions:list')
			->setDescription('List permissions on a room or a group.')
			->addArgument('id', InputArgument::REQUIRED, 'Room id (default) or group id with --group')
			->addOption('group', null, InputOption::VALUE_NONE, 'Treat the id as a room-group id');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$id = $input->getArgument('id');
		$perms = $input->getOption('group') ? $this->perms->listForGroup($id) : $this->perms->listForRoom($id);

		$table = new Table($output);
		$table->setHeaders(['perm-id', 'role', 'principalType', 'principalId']);
		foreach ($perms as $p) {
			$table->addRow([$p->getId(), $p->getRole(), $p->getPrincipalType(), $p->getPrincipalId()]);
		}
		$table->render();
		return Command::SUCCESS;
	}
}
