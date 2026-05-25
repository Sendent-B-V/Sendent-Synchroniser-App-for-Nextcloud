<?php

namespace OCA\SendentSynchroniser\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\SendentSynchroniser\Calendar\Resource\RoomBackend;
use OCA\SendentSynchroniser\Listener\TokenInvalidInjector;
use OCA\SendentSynchroniser\Notification\Notifier;
use OCA\SendentSynchroniser\Service\InitialLoadManager;
use OCA\SendentSynchroniser\Service\Room\Binding\BindingKindRegistry;
use OCA\SendentSynchroniser\Service\Room\Binding\Exchange\ExchangeBindingValidator;
use OCA\SendentSynchroniser\UserBackend\RoomUserBackend;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IUserManager;
use OCP\Notification\IManager;

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

		// Calendar resource provider so rooms appear in NC's calendar resource picker.
		$context->registerCalendarRoomBackend(RoomBackend::class);

		// BindingKindRegistry — today seeds itself with the Exchange validator only.
		$context->registerService(BindingKindRegistry::class, function ($c) {
			return new BindingKindRegistry([
				$c->get(ExchangeBindingValidator::class),
			]);
		});
	}

	public function boot(IBootContext $context): void {
		$context->getAppContainer()->query(InitialLoadManager::class);
		$server = $context->getServerContainer();
		$manager = $server->get(IManager::class);
		$manager->registerNotifierService(Notifier::class);

		// Hidden user backend for room accounts. Registered at runtime via
		// IUserManager — IRegistrationContext has no user-backend register call.
		$userManager = $server->get(IUserManager::class);
		$userManager->registerBackend($server->get(RoomUserBackend::class));
	}

}
