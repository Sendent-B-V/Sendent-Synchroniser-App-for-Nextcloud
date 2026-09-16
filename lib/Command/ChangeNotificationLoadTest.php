<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Command;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\SignalPublisher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ sendentsynchroniser:cn-loadtest --events=350 --seconds=60 --users=10000
 *
 * Drives the ledger + batch-window path at a target event rate with synthetic
 * references (no DAV objects are created). Synthetic refs use the reserved
 * principal prefix below so a test run is distinguishable in the ledger; run
 * against staging, not production.
 */
class ChangeNotificationLoadTest extends Command {

	private const PRINCIPAL_PREFIX = 'principals/users/cn-loadtest-';

	public function __construct(
		private ChangeLedgerService $ledger,
		private SignalPublisher $publisher,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:cn-loadtest')
			->setDescription('Drive synthetic change events through the ledger at a target rate')
			->addOption('events', null, InputOption::VALUE_REQUIRED, 'Target events per second', '350')
			->addOption('seconds', null, InputOption::VALUE_REQUIRED, 'Duration', '60')
			->addOption('users', null, InputOption::VALUE_REQUIRED, 'Distinct synthetic users', '10000');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$rate = max(1, (int)$input->getOption('events'));
		$seconds = max(1, (int)$input->getOption('seconds'));
		$users = max(1, (int)$input->getOption('users'));

		$total = 0;
		$latencies = [];
		$start = microtime(true);

		for ($s = 0; $s < $seconds; $s++) {
			$secondStart = microtime(true);
			for ($i = 0; $i < $rate; $i++) {
				$user = self::PRINCIPAL_PREFIX . (($total * 7919) % $users);
				$ref = new CollectionReference(
					$user,
					($total % 3 === 0) ? Constants::COLLECTION_TYPE_CARDDAV : Constants::COLLECTION_TYPE_CALDAV,
					($total % 3 === 0) ? 'contacts' : 'personal',
					$total,
					$total % 500 === 0
				);

				$t0 = microtime(true);
				$this->ledger->record([$ref]);
				$this->publisher->flushIfDue();
				$latencies[] = (microtime(true) - $t0) * 1000;
				$total++;
			}
			// Hold the target rate; if the second overran, keep going flat out.
			$elapsed = microtime(true) - $secondStart;
			if ($elapsed < 1.0) {
				usleep((int)((1.0 - $elapsed) * 1e6));
			}
			$output->writeln(sprintf('second %d: %d events', $s + 1, $total));
		}

		sort($latencies);
		$wall = microtime(true) - $start;
		$p = static fn (float $q) => $latencies[(int)floor($q * (count($latencies) - 1))];

		$output->writeln('');
		$output->writeln('events_total: ' . $total);
		$output->writeln(sprintf('achieved_rate: %.1f/s (target %d/s)', $total / $wall, $rate));
		$output->writeln(sprintf('latency_ms p50: %.2f p95: %.2f p99: %.2f max: %.2f', $p(0.5), $p(0.95), $p(0.99), $p(1.0)));
		$output->writeln('Check flush behaviour with: occ sendentsynchroniser:cn-status');

		return 0;
	}
}
