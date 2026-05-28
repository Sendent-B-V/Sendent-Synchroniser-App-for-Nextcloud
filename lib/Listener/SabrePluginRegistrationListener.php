<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Listener;

use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Sabre\RoomSchedulingPlugin;
use OCA\SendentSynchroniser\Sabre\SchedulingSuppressorPlugin;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Adds Sendent Sync's Sabre plugins to the authenticated Sabre/DAV server.
 * Listens to SabrePluginAuthInitEvent (not SabrePluginAddPluginEvent) — that
 * is the lifecycle hook on the server instance through which CalDAV
 * scheduling actually flows.
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
		$this->logger->info('SabrePluginRegistrationListener attaching plugins', ['app' => 'sendentsynchroniser']);
		$event->getServer()->addPlugin($this->suppressor);
		if (Constants::ROOMS_FEATURE_ENABLED) {
			$event->getServer()->addPlugin($this->roomScheduling);
		}
	}
}
