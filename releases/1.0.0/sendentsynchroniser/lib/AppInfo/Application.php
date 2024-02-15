<?php

namespace OCA\SendentSynchroniser\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\SendentSynchroniser\Listener\TokenInvalidInjector;
use OCA\SendentSynchroniser\Notification\Notifier;
use OCA\SendentSynchroniser\Service\InitialLoadManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
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
		$context->registerEventListener(\OCA\Files\Event\LoadAdditionalScriptsEvent::class, TokenInvalidInjector::class);
	}

	public function boot(IBootContext $context): void {
		$context->getAppContainer()->query(InitialLoadManager::class);
		$server = $context->getServerContainer();
		$manager = $server->get(IManager::class);
		$manager->registerNotifierService(Notifier::class);
	}

}
