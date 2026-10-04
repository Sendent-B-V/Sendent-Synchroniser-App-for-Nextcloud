<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Controller;

use OCA\SendentSynchroniser\Controller\ChangeNotificationSettingsController;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\SetupCheck;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ChangeNotificationSettingsControllerTest extends TestCase {

	/** @var ChangeNotificationConfig&MockObject */
	private $config;

	/** @var IUserManager&MockObject */
	private $userManager;

	/** @var SetupCheck&MockObject */
	private $setupCheck;

	private ChangeNotificationSettingsController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(ChangeNotificationConfig::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->setupCheck = $this->createMock(SetupCheck::class);

		$this->controller = new ChangeNotificationSettingsController(
			'sendentsynchroniser',
			$this->createMock(IRequest::class),
			$this->config,
			$this->userManager,
			$this->setupCheck,
		);
	}

	public function testSetTransportModeStoresAValidMode(): void {
		$this->config->expects($this->once())->method('setTransportMode')->with('polling');

		$response = $this->controller->setTransportMode('polling');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testSetTransportModeRejectsGarbage(): void {
		$this->config->method('setTransportMode')
			->willThrowException(new \InvalidArgumentException('Unknown transport mode: wat'));

		$response = $this->controller->setTransportMode('wat');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testSetBotUserRequiresAnExistingUser(): void {
		$this->userManager->method('userExists')->with('ghost')->willReturn(false);
		$this->config->expects($this->never())->method('setBotUser');

		$response = $this->controller->setBotUser('ghost');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testSetBotUserStoresAnExistingUser(): void {
		$this->userManager->method('userExists')->with('sendent-sync')->willReturn(true);
		$this->config->expects($this->once())->method('setBotUser')->with('sendent-sync');

		$response = $this->controller->setBotUser('sendent-sync');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testSetPollIntervalStoresAndReturnsTheClampedValue(): void {
		$this->config->expects($this->once())->method('setPollInterval')->with(1);
		$this->config->method('pollInterval')->willReturn(5);

		$data = $this->controller->setPollInterval(1)->getData();

		$this->assertSame(['pollInterval' => 5], $data);
	}

	public function testCheckReturnsTheSetupCheck(): void {
		$result = ['ok' => true, 'server_supported' => true, 'notify_push' => ['ok' => true]];
		$this->setupCheck->method('run')->willReturn($result);

		$this->assertSame($result, $this->controller->check()->getData());
	}
}
