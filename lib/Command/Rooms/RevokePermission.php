<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Command\Rooms;

use OCA\SendentSynchroniser\Service\Room\PermissionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RevokePermission extends Command {
	public function __construct(private PermissionService $perms) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:rooms:permissions:revoke')
			->setDescription('Revoke a permission by its perm-id (see permissions:list).')
			->addArgument('perm-id', InputArgument::REQUIRED, 'Numeric permission id');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->perms->revoke((int) $input->getArgument('perm-id'));
		$output->writeln('<info>Revoked.</info>');
		return Command::SUCCESS;
	}
}
