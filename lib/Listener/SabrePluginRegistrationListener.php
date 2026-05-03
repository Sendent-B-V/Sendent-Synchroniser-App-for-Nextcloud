<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Listener;

use OCA\DAV\Events\SabrePluginAddPluginEvent;
use OCA\SendentSynchroniser\Sabre\RoomSchedulingPlugin;
use OCA\SendentSynchroniser\Sabre\SchedulingSuppressorPlugin;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Adds Sendent Sync's Sabre plugins to the Sabre/DAV server during DAV server
 * construction. Plugins have disjoint scopes:
 *  - SchedulingSuppressorPlugin: human users that Connector handles
 *  - RoomSchedulingPlugin: room principals (only acts on unbound rooms)
 *
 * @template-implements IEventListener<SabrePluginAddPluginEvent>
 */
class SabrePluginRegistrationListener implements IEventListener {

	public function __construct(
		private SchedulingSuppressorPlugin $suppressor,
		private RoomSchedulingPlugin $roomScheduling,
	) {}

	public function handle(Event $event): void {
		if (!$event instanceof SabrePluginAddPluginEvent) {
			return;
		}
		$event->getServer()->addPlugin($this->suppressor);
		$event->getServer()->addPlugin($this->roomScheduling);
	}
}
