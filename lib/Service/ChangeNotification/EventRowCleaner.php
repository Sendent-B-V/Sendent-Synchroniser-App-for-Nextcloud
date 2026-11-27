<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\Db\EventChangeRowMapper;
use Psr\Log\LoggerInterface;

/**
 * Removes the per-event rows of a user who is no longer a sync user.
 * The one place for the rule: a failed removal is logged, never thrown.
 */
class EventRowCleaner {

	public function __construct(
		private EventChangeRowMapper $eventRows,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Removes all per-event rows of one user. Rows of other users stay.
	 * Never throws: the caller is in the middle of its own work.
	 */
	public function removeForUser(string $userId): void {
		try {
			$this->eventRows->deleteForPrincipal('principals/users/' . $userId);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to remove per-event rows for user ' . $userId . ': ' . $e->getMessage(), [
				'exception' => $e,
				'app' => 'sendentsynchroniser',
			]);
		}
	}
}
