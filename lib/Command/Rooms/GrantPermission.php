<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.vandebroek@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Command\Rooms;

use OCA\SendentSynchroniser\Service\Room\PermissionService;
use OCA\SendentSynchroniser\Service\Room\RoomValidationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GrantPermission extends Command {
	public function __construct(private PermissionService $perms) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:rooms:permissions:grant')
			->setDescription('Grant a permission on a room or group.')
			->addArgument('id', InputArgument::REQUIRED, 'Room id (default) or group id with --on-group')
			->addArgument('role', InputArgument::REQUIRED, 'viewer | booker | manager')
			->addArgument('principal-type', InputArgument::REQUIRED, 'user | group')
			->addArgument('principal-id', InputArgument::REQUIRED, 'NC user uid or group gid')
			->addOption('on-group', null, InputOption::VALUE_NONE, 'Grant on a room-group instead of a room');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$p = $input->getOption('on-group')
				? $this->perms->grantOnGroup($input->getArgument('id'), $input->getArgument('role'), $input->getArgument('principal-type'), $input->getArgument('principal-id'))
				: $this->perms->grantOnRoom($input->getArgument('id'), $input->getArgument('role'), $input->getArgument('principal-type'), $input->getArgument('principal-id'));
			$output->writeln('<info>Granted, perm-id:</info> ' . $p->getId());
			return Command::SUCCESS;
		} catch (RoomValidationException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 2;
		}
	}
}
