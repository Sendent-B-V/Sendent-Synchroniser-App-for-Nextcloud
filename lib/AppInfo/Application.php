<?php

namespace OCA\SendentSynchroniser\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\SendentSynchroniser\Listener\DavChangeListener;
use OCA\SendentSynchroniser\Listener\TokenInvalidInjector;
use OCA\SendentSynchroniser\Notification\Notifier;
use OCA\SendentSynchroniser\Service\InitialLoadManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APPID = 'sendentsynchroniser';

	/**
	 * @param array $params
	 */
	public function __construct(array $params = []) {
		parent::__construct('sendentsynchroniser', $params);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(\OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent::class, TokenInvalidInjector::class);
		$context->registerEventListener(\OCA\Files\Event\LoadAdditionalScriptsEvent::class, TokenInvalidInjector::class);
		$context->registerEventListener(
			\OCA\DAV\Events\SabrePluginAuthInitEvent::class,
			\OCA\SendentSynchroniser\Listener\SabrePluginRegistrationListener::class,
		);
		// Trash-bin scrub of X-SENDENT* properties. The move-to-trash event moved
		// namespaces across our NC support range: the OCA\DAV class exists on
		// NC <= 31 (removed in 32), the OCP one since NC 31.0.2 — register both;
		// a class-string for an event that never gets dispatched is harmless,
		// and the listener guards against NC 31 dispatching both for one delete.
		$context->registerEventListener(
			\OCA\DAV\Events\CalendarObjectMovedToTrashEvent::class,
			\OCA\SendentSynchroniser\Listener\CalendarObjectTrashScrubListener::class,
		);
		$context->registerEventListener(
			\OCP\Calendar\Events\CalendarObjectMovedToTrashEvent::class,
			\OCA\SendentSynchroniser\Listener\CalendarObjectTrashScrubListener::class,
		);
		// Change notifications require NC 32+: object-level events exist only
		// as OCP\Calendar\Events (@since 32.0.0). On older servers these
		// class-strings are never dispatched and the feature is inert.
		// Collection-level and CardDAV events are OCA on every version.
		$changeEvents = [
			\OCP\Calendar\Events\CalendarObjectCreatedEvent::class,
			\OCP\Calendar\Events\CalendarObjectUpdatedEvent::class,
			\OCP\Calendar\Events\CalendarObjectDeletedEvent::class,
			\OCP\Calendar\Events\CalendarObjectMovedEvent::class,
			\OCP\Calendar\Events\CalendarObjectMovedToTrashEvent::class,
			\OCP\Calendar\Events\CalendarObjectRestoredEvent::class,
			\OCA\DAV\Events\CalendarCreatedEvent::class,
			\OCA\DAV\Events\CalendarUpdatedEvent::class,
			\OCA\DAV\Events\CalendarDeletedEvent::class,
			\OCA\DAV\Events\CalendarMovedToTrashEvent::class,
			\OCA\DAV\Events\CalendarRestoredEvent::class,
			\OCA\DAV\Events\CalendarShareUpdatedEvent::class,
			\OCA\DAV\Events\CalendarPublishedEvent::class,
			\OCA\DAV\Events\CalendarUnpublishedEvent::class,
			\OCA\DAV\Events\CardCreatedEvent::class,
			\OCA\DAV\Events\CardUpdatedEvent::class,
			\OCA\DAV\Events\CardDeletedEvent::class,
			\OCA\DAV\Events\CardMovedEvent::class,
			\OCA\DAV\Events\AddressBookCreatedEvent::class,
			\OCA\DAV\Events\AddressBookUpdatedEvent::class,
			\OCA\DAV\Events\AddressBookDeletedEvent::class,
			\OCA\DAV\Events\AddressBookShareUpdatedEvent::class,
		];
		foreach ($changeEvents as $changeEvent) {
			$context->registerEventListener($changeEvent, DavChangeListener::class);
		}
		$context->registerNotifierService(Notifier::class);
	}

	public function boot(IBootContext $context): void {
		$context->getAppContainer()->query(InitialLoadManager::class);
	}

}
