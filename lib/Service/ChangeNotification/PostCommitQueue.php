<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\EventStamp;
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

	/** @var list<EventStamp> one per per-event row, never merged */
	private array $eventStamps = [];

	private bool $queued = false;

	public function __construct(
		private AfterCommit $afterCommit,
		private ChangeLedgerService $ledger,
		private NotifyPushTransport $transport,
		private LoggerInterface $logger,
	) {}

	/**
	 * Holds the stamps until the commit. Stamps of one calendar become one.
	 * Each EventStamp stays its own entry, also two in one calendar.
	 *
	 * @param list<Stamp|EventStamp> $stamps
	 */
	public function add(array $stamps): void {
		foreach ($stamps as $stamp) {
			// An EventStamp has no key: each one is kept, never merged.
			if ($stamp instanceof EventStamp) {
				$this->eventStamps[] = $stamp;
				continue;
			}
			$key = $stamp['ref']->key();
			// The row ends up at the highest stamp this request gave it, so
			// that is the one a reader could have read past.
			if (!isset($this->stamps[$key]) || $stamp['seq'] > $this->stamps[$key]['seq']) {
				$this->stamps[$key] = $stamp;
			}
		}

		if (($this->stamps === [] && $this->eventStamps === []) || $this->queued) {
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
		$eventStamps = $this->eventStamps;
		$this->eventStamps = [];
		if ($stamps === [] && $eventStamps === []) {
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

		// Its own try: a failure here must not stop the hint.
		try {
			$this->ledger->confirmEventRows($eventStamps);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to confirm event rows: ' . $e->getMessage(), [
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
