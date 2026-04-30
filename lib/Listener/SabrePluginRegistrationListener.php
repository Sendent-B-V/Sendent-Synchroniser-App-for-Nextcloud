<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Listener;

use OCA\DAV\Events\SabrePluginAddPluginEvent;
use OCA\SendentSynchroniser\Sabre\SchedulingSuppressorPlugin;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Adds Sendent Sync's SchedulingSuppressorPlugin to the Sabre/DAV server
 * during DAV server construction.
 *
 * @template-implements IEventListener<SabrePluginAddPluginEvent>
 */
class SabrePluginRegistrationListener implements IEventListener {

	public function __construct(
		private SchedulingSuppressorPlugin $plugin,
	) {}

	public function handle(Event $event): void {
		if (!$event instanceof SabrePluginAddPluginEvent) {
			return;
		}
		$event->getServer()->addPlugin($this->plugin);
	}
}
