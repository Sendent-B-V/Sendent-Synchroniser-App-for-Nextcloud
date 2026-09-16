<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Command;

use OCA\SendentSynchroniser\Service\ChangeNotification\SignalPublisher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ sendentsynchroniser:cn-flush — force a signal for whatever is above the
 * watermark, ignoring the batch window. Debugging and demos.
 */
class ChangeNotificationFlush extends Command {

	public function __construct(
		private SignalPublisher $publisher,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:cn-flush')
			->setDescription('Force-publish a change-notification signal now');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$signal = $this->publisher->flush();

		if ($signal === null) {
			$output->writeln('Nothing to flush (or publish failed — check the log).');
			return 0;
		}

		$output->writeln('Published signal at cursor ' . $signal['cursor']
			. ' with ' . count($signal['refs']) . ' refs'
			. ($signal['truncated'] ? ' (truncated)' : ''));

		return 0;
	}
}
