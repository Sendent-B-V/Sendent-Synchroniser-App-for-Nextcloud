<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.vandebroek@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Command\Rooms;

use OCA\SendentSynchroniser\Service\Room\Binding\BindingValidationException;
use OCA\SendentSynchroniser\Service\Room\Binding\LicenseRequiredException;
use OCA\SendentSynchroniser\Service\Room\BindingService;
use OCA\SendentSynchroniser\Service\Room\RoomValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Bind extends Command {
	public function __construct(private BindingService $service) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:rooms:bind')
			->setDescription('Bind a room to an external service (license-gated). Today only kind=exchange is registered.')
			->addArgument('room-id', InputArgument::REQUIRED)
			->addArgument('kind', InputArgument::REQUIRED, 'Binding kind, e.g. exchange')
			->addArgument('external-id', InputArgument::REQUIRED, 'For exchange: SMTP/UPN, e.g. boardroom-a@contoso.com');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$b = $this->service->set(
				$input->getArgument('room-id'),
				$input->getArgument('kind'),
				$input->getArgument('external-id'),
				[],
			);
			$output->writeln('<info>Bound:</info> ' . $b->getRoomId() . ' (linkVersion=' . $b->getLinkVersion() . ', state=' . $b->getState() . ')');
			return Command::SUCCESS;
		} catch (LicenseRequiredException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 4;
		} catch (BindingValidationException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 2;
		} catch (RoomValidationException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 2;
		} catch (DoesNotExistException) {
			$output->writeln('<error>Room not found.</error>');
			return 3;
		}
	}
}
