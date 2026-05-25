<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Listener;

use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\SendentSynchroniser\Sabre\RoomSchedulingPlugin;
use OCA\SendentSynchroniser\Sabre\SchedulingSuppressorPlugin;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Adds Sendent Sync's Sabre plugins to the authenticated Sabre/DAV server.
 * We listen to SabrePluginAuthInitEvent (not SabrePluginAddPluginEvent)
 * because that's the lifecycle hook on the server instance through which
 * CalDAV scheduling actually flows — same event RoomVox uses.
 *
 * Plugins have disjoint scopes:
 *  - SchedulingSuppressorPlugin: human users that Connector handles
 *  - RoomSchedulingPlugin: room principals (only acts on unbound rooms)
 *
 * @template-implements IEventListener<SabrePluginAuthInitEvent>
 */
class SabrePluginRegistrationListener implements IEventListener {

	public function __construct(
		private SchedulingSuppressorPlugin $suppressor,
		private RoomSchedulingPlugin $roomScheduling,
		private LoggerInterface $logger,
	) {}

	public function handle(Event $event): void {
		if (!$event instanceof SabrePluginAuthInitEvent) {
			return;
		}
		@file_put_contents('/tmp/sendent-suppressor.log', '[' . date('c') . '] SabrePluginRegistrationListener::handle adding suppressor + roomScheduling' . PHP_EOL, FILE_APPEND);
		error_log('[sendentsynchroniser] SabrePluginRegistrationListener::handle adding suppressor + roomScheduling');
		$this->logger->info('SabrePluginRegistrationListener attaching plugins', ['app' => 'sendentsynchroniser']);
		$event->getServer()->addPlugin($this->suppressor);
		$event->getServer()->addPlugin($this->roomScheduling);
	}
}
