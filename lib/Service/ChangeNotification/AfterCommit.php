<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Runs work once the current database transaction can no longer roll it back
 * or be held up by it.
 *
 * DAV change events fire inside the backend's atomic() transaction. Two kinds
 * of follow-up work must not happen there:
 *
 *  - Publishing a signal: it would reach the Connector before the change it
 *    announces is visible.
 *  - Writing app config: the row lock would be held until the user's write
 *    commits, stalling every other DAV writer that touches the same key, and
 *    a concurrent first insert of the key fails on PostgreSQL.
 *
 * Nextcloud has no public after-commit hook, so work queued inside a
 * transaction runs at the end of the request (register_shutdown_function, as
 * core itself uses). Without an open transaction it runs immediately.
 *
 * Long-running processes (occ, cron) that write calendars in per-item
 * transactions therefore run the queued work once, when they finish. Their
 * ledger rows are committed and visible all along, so the sweeper still
 * publishes them on its own schedule.
 */
class AfterCommit {

	/** @var list<callable(): void> */
	private array $pending = [];

	private bool $scheduled = false;

	/** @var callable(callable): void */
	private $schedule;

	/**
	 * @param (callable(callable): void)|null $schedule runs its argument at the
	 *                                                  end of the request;
	 *                                                  injectable for tests
	 */
	public function __construct(
		private IDBConnection $db,
		private LoggerInterface $logger,
		?callable $schedule = null,
	) {
		$this->schedule = $schedule ?? static function (callable $work): void {
			register_shutdown_function($work);
		};
	}

	/** @param callable(): void $work never throws to the caller; failures are logged */
	public function run(callable $work): void {
		if (!$this->db->inTransaction()) {
			$this->invoke($work);
			return;
		}

		$this->pending[] = $work;
		if (!$this->scheduled) {
			$this->scheduled = true;
			($this->schedule)(fn () => $this->drain());
		}
	}

	private function drain(): void {
		$this->scheduled = false;
		$work = $this->pending;
		$this->pending = [];
		if ($work === []) {
			return;
		}

		if ($this->db->inTransaction()) {
			// Still open at the end of the request: the write was never
			// committed and rolls back with the connection. Running the work
			// now would make it part of that doomed transaction, or announce
			// a change that is about to disappear.
			$this->logger->warning('Skipping change-notification follow-up work: a database transaction is still open at the end of the request', [
				'app' => 'sendentsynchroniser',
			]);
			return;
		}

		foreach ($work as $item) {
			$this->invoke($item);
		}
	}

	private function invoke(callable $work): void {
		try {
			$work();
		} catch (\Throwable $e) {
			$this->logger->error('Change-notification follow-up work failed: ' . $e->getMessage(), [
				'exception' => $e,
				'app' => 'sendentsynchroniser',
			]);
		}
	}
}
