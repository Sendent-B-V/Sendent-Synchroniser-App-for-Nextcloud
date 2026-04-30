<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Sabre;

use OCA\SendentSynchroniser\Service\SchedulingSuppressionService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\VObject\ITip\Message;

/**
 * Suppresses Nextcloud's outbound iMip and internal CalDAV iTip delivery
 * for events on Sendent-synced calendars when "Graph API mode" is enabled.
 *
 * Hooks Sabre/DAV's `schedule` event with priority lower than
 * OCA\DAV\CalDAV\Schedule\IMipPlugin (Sabre invokes lower-priority listeners
 * first). When the suppression service returns true, we set the iTip
 * message's scheduleStatus, causing every downstream listener to skip it.
 */
class SchedulingSuppressorPlugin extends ServerPlugin {

	/**
	 * Priority must be lower (= called earlier) than IMipPlugin's. IMipPlugin
	 * registers at priority 100 in NC 28-33 (verify per version during
	 * compatibility testing in Task 10).
	 */
	public const SCHEDULE_PRIORITY = 50;

	public function __construct(
		private SchedulingSuppressionService $suppressionService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {}

	public function initialize(Server $server): void {
		$server->on('schedule', [$this, 'onSchedule'], self::SCHEDULE_PRIORITY);
		// Stash for use inside the listener.
		$this->server = $server;
	}

	private Server $server;

	public function onSchedule(Message $iTipMessage): void {
		$user = $this->userSession->getUser();
		$uid = $user?->getUID();
		$requestPath = $this->server->getRequestUri();

		if (!$this->suppressionService->shouldSuppress($uid, $requestPath)) {
			return;
		}

		$iTipMessage->scheduleStatus = '1.1; suppressed by Sendent Sync (Graph API mode)';
		$this->logger->debug(
			'Suppressed iTip message for {uid} on {path} (Graph API mode)',
			['uid' => $uid, 'path' => $requestPath, 'app' => 'sendentsynchroniser']
		);
	}

	public function getPluginName(): string {
		return 'sendentsynchroniser-scheduling-suppressor';
	}
}
