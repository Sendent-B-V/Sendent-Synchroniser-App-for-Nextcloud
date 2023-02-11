<?php

namespace OCA\SendentSynchroniser\AppInfo;

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

	}

	public function boot(IBootContext $context): void {
		$context->getAppContainer()->query(InitialLoadManager::class);
	}
}
