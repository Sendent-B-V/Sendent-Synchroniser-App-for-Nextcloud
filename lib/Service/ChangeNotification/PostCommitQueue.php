<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use Psr\Log\LoggerInterface;

/**
 * Holds this request's ledger stamps until the DAV write that produced them
 * has committed (see AfterCommit), then confirms them and sends the hint.
 *
 * A hint sent from inside the DAV transaction would arrive before the change
 * is visible: a Connector that reacts within milliseconds reads the feed,
 * finds nothing new, and waits for the next hint.
 *
 * @psalm-import-type Stamp from ChangeLedgerService
 */
class PostCommitQueue {

	/** @var array<string, Stamp> highest stamp per collection */
	private array $stamps = [];

	private bool $queued = false;

	public function __construct(
		private AfterCommit $afterCommit,
		private ChangeLedgerService $ledger,
		private NotifyPushTransport $transport,
		private LoggerInterface $logger,
	) {}

	/** @param list<Stamp> $stamps */
	public function add(array $stamps): void {
		foreach ($stamps as $stamp) {
			$key = $stamp['ref']->key();
			// The row ends up at the highest stamp this request gave it, so
			// that is the one a reader could have read past.
			if (!isset($this->stamps[$key]) || $stamp['seq'] > $this->stamps[$key]['seq']) {
				$this->stamps[$key] = $stamp;
			}
		}

		if ($this->stamps === [] || $this->queued) {
			return;
		}

		$this->queued = true;
		$this->afterCommit->run(function (): void {
			$this->queued = false;
			$this->process();
		});
	}

	/** Never throws. */
	private function process(): void {
		$stamps = array_values($this->stamps);
		$this->stamps = [];
		if ($stamps === []) {
			return;
		}

		// Re-stamps must land before the hint makes the Connector read the feed.
		try {
			$this->ledger->confirm($stamps);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to confirm recorded DAV changes: ' . $e->getMessage(), [
				'exception' => $e,
				'app' => 'sendentsynchroniser',
			]);
		}

		try {
			$this->transport->publishHint();
		} catch (\Throwable $e) {
			$this->logger->error('Failed to send a change hint: ' . $e->getMessage(), [
				'exception' => $e,
				'app' => 'sendentsynchroniser',
			]);
		}
	}
}
