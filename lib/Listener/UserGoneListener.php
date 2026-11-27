<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Listener;

use OCA\SendentSynchroniser\Service\ChangeNotification\EventRowCleaner;
use OCA\SendentSynchroniser\Service\SyncUserService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\UserRemovedEvent;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Removes the per-event rows of a user who was deleted, or who left a group
 * and is no longer a sync user.
 *
 * @template-implements IEventListener<Event>
 */
class UserGoneListener implements IEventListener {

	public function __construct(
		private EventRowCleaner $cleaner,
		private SyncUserService $syncUsers,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		try {
			if ($event instanceof UserDeletedEvent) {
				$userId = $event->getUser()->getUID();
				$this->cleaner->removeForUser($userId);
			} else if ($event instanceof UserRemovedEvent) {
				$userId = $event->getUser()->getUID();
				if (!$this->syncUsers->isValidUser($userId)) {
					$this->cleaner->removeForUser($userId);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->error('Failed to check or remove the per-event rows of a user: ' . $e->getMessage(), [
				'exception' => $e,
				'app' => 'sendentsynchroniser',
			]);
		}
	}
}
