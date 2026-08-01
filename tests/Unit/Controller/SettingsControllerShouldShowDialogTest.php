<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Controller;

use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Controller\SettingsController;
use OCA\SendentSynchroniser\Db\SyncUser;
use OCA\SendentSynchroniser\Db\SyncUserMapper;
use OCA\SendentSynchroniser\Service\SyncUserService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\Notification\IManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SettingsControllerShouldShowDialogTest extends TestCase {

	private IAppConfig&MockObject $appConfig;
	private IGroupManager&MockObject $groupManager;
	private IRequest&MockObject $request;
	private SyncUserMapper&MockObject $mapper;
	private SyncUserService&MockObject $syncUserService;
	private SettingsController $controller;

	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->request = $this->createMock(IRequest::class);
		$this->mapper = $this->createMock(SyncUserMapper::class);
		$this->syncUserService = $this->createMock(SyncUserService::class);
		$this->controller = new SettingsController(
			'sendentsynchroniser',
			$this->request,
			'alice',
			$this->appConfig,
			$this->groupManager,
			new NullLogger(),
			$this->createMock(IManager::class),
			$this->mapper,
			$this->syncUserService
		);

		// Baseline gating: secret set, modal reminders on, no timeout cookie,
		// user in an active group.
		$this->appConfig->method('getAppValue')->willReturnCallback(
			fn ($key, $default = '') => match ($key) {
				'sharedSecret' => 'a-secret',
				'reminderType' => Constants::REMINDER_MODAL,
				'activeGroups' => '["sendent"]',
				default => $default,
			}
		);
		$this->request->method('getCookie')->willReturn(null);
		$this->groupManager->method('isInGroup')->with('alice', 'sendent')->willReturn(true);
	}

	private function givenSyncUserWithStatus(int $status): void {
		$syncUser = new SyncUser();
		$syncUser->setUid('alice');
		$syncUser->setActive($status);
		$this->mapper->method('findByUid')->with('alice')->willReturn([$syncUser]);
	}

	public function testActiveUserOnLegacyTokenIsPushed(): void {
		$this->givenSyncUserWithStatus(Constants::USER_STATUS_ACTIVE);
		$this->syncUserService->method('hasLegacyToken')->with('alice')->willReturn(true);
		$this->assertTrue($this->controller->shouldShowDialog()->getData());
	}

	public function testActiveUserOnNewTokenIsNotPushed(): void {
		$this->givenSyncUserWithStatus(Constants::USER_STATUS_ACTIVE);
		$this->syncUserService->method('hasLegacyToken')->with('alice')->willReturn(false);
		$this->assertFalse($this->controller->shouldShowDialog()->getData());
	}

	public function testNoconsentUserIsNeverPushed(): void {
		$this->givenSyncUserWithStatus(Constants::USER_STATUS_NOCONSENT);
		$this->syncUserService->expects($this->never())->method('hasLegacyToken');
		$this->assertFalse($this->controller->shouldShowDialog()->getData());
	}
}
