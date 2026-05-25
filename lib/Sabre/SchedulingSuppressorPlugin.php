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
 * Suppresses Nextcloud's outbound iMIP and internal CalDAV iTip delivery
 * for users in Sendent's active groups when "Graph API mode" is enabled.
 *
 * Hooks Sabre/DAV's `schedule` event at a lower priority value than
 * OCA\DAV\CalDAV\Schedule\IMipPlugin (Sabre/Event invokes lower-priority
 * listeners first). When the suppression service returns true, we set a
 * '2.x' scheduleStatus and return false from the handler, which halts
 * Sabre's event dispatch — skipping both scheduleLocalDelivery (internal
 * iTip into the recipient's NC calendar) and IMipPlugin (outbound email).
 */
class SchedulingSuppressorPlugin extends ServerPlugin {

	/**
	 * Priority must be lower (= called earlier) than IMipPlugin's. IMipPlugin
	 * registers at priority 100 in NC 28-33.
	 */
	public const SCHEDULE_PRIORITY = 50;

	private Server $server;

	public function __construct(
		private SchedulingSuppressionService $suppressionService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {}

	public function initialize(Server $server): void {
		$server->on('schedule', [$this, 'onSchedule'], self::SCHEDULE_PRIORITY);
		$this->server = $server;
		@file_put_contents('/tmp/sendent-suppressor.log', '[' . date('c') . '] initialize attached schedule listener prio=' . self::SCHEDULE_PRIORITY . PHP_EOL, FILE_APPEND);
		error_log('[sendentsynchroniser] SchedulingSuppressorPlugin::initialize attached schedule listener at prio ' . self::SCHEDULE_PRIORITY);
	}

	public function onSchedule(Message $iTipMessage): ?bool {
		$user = $this->userSession->getUser();
		$uid = $user?->getUID();
		$requestPath = $this->server->getRequestUri();
		$shouldSuppress = $this->suppressionService->shouldSuppress($uid, $requestPath);

		@file_put_contents('/tmp/sendent-suppressor.log', '[' . date('c') . '] onSchedule uid=' . ($uid ?? '(null)') . ' path=' . $requestPath . ' recipient=' . (string)($iTipMessage->recipient ?? '') . ' method=' . (string)($iTipMessage->method ?? '') . ' suppress=' . ($shouldSuppress ? 'true' : 'false') . PHP_EOL, FILE_APPEND);
		error_log('[sendentsynchroniser] onSchedule uid=' . ($uid ?? '(null)') . ' path=' . $requestPath . ' recipient=' . (string)($iTipMessage->recipient ?? '') . ' suppress=' . ($shouldSuppress ? 'true' : 'false'));
		$this->logger->info(
			'SchedulingSuppressorPlugin::onSchedule invoked uid={uid} path={path} recipient={recipient} method={method} suppress={suppress}',
			[
				'uid' => $uid ?? '(null)',
				'path' => $requestPath,
				'recipient' => (string)($iTipMessage->recipient ?? ''),
				'method' => (string)($iTipMessage->method ?? ''),
				'suppress' => $shouldSuppress ? 'true' : 'false',
				'app' => 'sendentsynchroniser',
			]
		);

		if (!$shouldSuppress) {
			return null;
		}

		$iTipMessage->scheduleStatus = '2.0;Success - suppressed by Sendent Sync (Graph API mode)';
		return false;
	}

	public function getPluginName(): string {
		return 'sendentsynchroniser-scheduling-suppressor';
	}
}
