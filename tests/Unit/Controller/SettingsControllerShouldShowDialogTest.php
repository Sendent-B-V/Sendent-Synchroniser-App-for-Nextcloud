<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Controller;

use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Controller\SettingsController;
use OCA\SendentSynchroniser\Db\AuthStatus;
use OCA\SendentSynchroniser\Db\AuthStatusMapper;
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
	private AuthStatusMapper&MockObject $authStatusMapper;
	private SettingsController $controller;

	// Read by the stubs, so a test can change them after setUp.
	private string $signInMode = Constants::GRAPH_AUTH_METHOD_SERVICE_ACCOUNT;
	private ?AuthStatus $authRow = null;

	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->request = $this->createMock(IRequest::class);
		$this->mapper = $this->createMock(SyncUserMapper::class);
		$this->syncUserService = $this->createMock(SyncUserService::class);
		$this->authStatusMapper = $this->createMock(AuthStatusMapper::class);
		$this->authStatusMapper->method('findByUidAndProvider')
			->willReturnCallback(fn (string $uid, string $provider) => $this->authRow);
		$this->controller = new SettingsController(
			'sendentsynchroniser',
			$this->request,
			'alice',
			$this->appConfig,
			$this->groupManager,
			new NullLogger(),
			$this->createMock(IManager::class),
			$this->mapper,
			$this->syncUserService,
			$this->authStatusMapper
		);

		// Baseline gating: secret set, modal reminders on, no timeout cookie,
		// user in an active group.
		// getAppValue() is declared `: string` and PHPUnit mocks are generated
		// with strict_types — every arm must return a string, or the mock
		// throws a TypeError. App values are stored as strings in production.
		$this->appConfig->method('getAppValue')->willReturnCallback(
			fn ($key, $default = '') => match ($key) {
				'sharedSecret' => 'a-secret',
				'reminderType' => (string)Constants::REMINDER_MODAL,
				'activeGroups' => '["sendent"]',
				Constants::GRAPH_AUTH_METHOD_KEY => $this->signInMode,
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

	public function testActiveUserNeedingReconsentIsPushed(): void {
		$this->givenSyncUserWithStatus(Constants::USER_STATUS_ACTIVE);
		$this->syncUserService->method('needsReconsent')->with('alice')->willReturn(true);
		$this->assertTrue($this->controller->shouldShowDialog()->getData());
	}

	public function testActiveUserNotNeedingReconsentIsNotPushed(): void {
		$this->givenSyncUserWithStatus(Constants::USER_STATUS_ACTIVE);
		$this->syncUserService->method('needsReconsent')->with('alice')->willReturn(false);
		$this->assertFalse($this->controller->shouldShowDialog()->getData());
	}

	public function testInactiveUserIsAlwaysPushed(): void {
		// After the migration clear every former ACTIVE user is INACTIVE.
		$this->givenSyncUserWithStatus(Constants::USER_STATUS_INACTIVE);
		$this->syncUserService->expects($this->never())->method('needsReconsent');
		$this->assertTrue($this->controller->shouldShowDialog()->getData());
	}

	public function testNoconsentUserIsNeverPushed(): void {
		$this->givenSyncUserWithStatus(Constants::USER_STATUS_NOCONSENT);
		$this->syncUserService->expects($this->never())->method('needsReconsent');
		$this->assertFalse($this->controller->shouldShowDialog()->getData());
	}

	public function testUserWithoutRowIsPushed(): void {
		$this->mapper->method('findByUid')->with('alice')->willReturn([]);
		$this->assertTrue($this->controller->shouldShowDialog()->getData());
	}

	// --- Microsoft sign-in status, only for ACTIVE (consented) users --------

	private function givenGraphStatus(string $status, ?string $reason = null): void {
		$row = new AuthStatus();
		$row->setUid('alice');
		$row->setProvider('graph');
		$row->setStatus($status);
		$row->setReason($reason);
		$row->setChangedAt(1792402200);
		$this->authRow = $row;
	}

	public function testNeedsReconnectUnderFallbackIsPushed(): void {
		$this->signInMode = Constants::GRAPH_AUTH_METHOD_PER_USER_FALLBACK;
		$this->givenSyncUserWithStatus(Constants::USER_STATUS_ACTIVE);
		$this->givenGraphStatus('needsReconnect', 'refresh_token_revoked');
		$this->syncUserService->method('needsReconsent')->willReturn(false);

		$this->assertTrue($this->controller->shouldShowDialog()->getData());
	}

	public function testNeedsReconnectUnderStrictIsPushed(): void {
		// Normally the callback already invalidated them; belt and braces.
		$this->signInMode = Constants::GRAPH_AUTH_METHOD_PER_USER_STRICT;
		$this->givenSyncUserWithStatus(Constants::USER_STATUS_ACTIVE);
		$this->givenGraphStatus('needsReconnect', 'consent_revoked');
		$this->syncUserService->method('needsReconsent')->willReturn(false);

		$this->assertTrue($this->controller->shouldShowDialog()->getData());
	}

	public function testNeverConnectedUnderStrictIsPushed(): void {
		// No row at all: the service has never reported anything for alice.
		$this->signInMode = Constants::GRAPH_AUTH_METHOD_PER_USER_STRICT;
		$this->givenSyncUserWithStatus(Constants::USER_STATUS_ACTIVE);
		$this->syncUserService->method('needsReconsent')->willReturn(false);

		$this->assertTrue($this->controller->shouldShowDialog()->getData());
	}

	public function testNotConnectedUnderStrictIsPushed(): void {
		$this->signInMode = Constants::GRAPH_AUTH_METHOD_PER_USER_STRICT;
		$this->givenSyncUserWithStatus(Constants::USER_STATUS_ACTIVE);
		$this->givenGraphStatus('notConnected');
		$this->syncUserService->method('needsReconsent')->willReturn(false);

		$this->assertTrue($this->controller->shouldShowDialog()->getData());
	}

	public function testConnectedUnderStrictFallsThroughToReconsent(): void {
		$this->signInMode = Constants::GRAPH_AUTH_METHOD_PER_USER_STRICT;
		$this->givenSyncUserWithStatus(Constants::USER_STATUS_ACTIVE);
		$this->givenGraphStatus('active');
		$this->syncUserService->expects($this->once())->method('needsReconsent')->willReturn(false);

		$this->assertFalse($this->controller->shouldShowDialog()->getData());
	}

	public function testNeverConnectedUnderFallbackIsNotPushed(): void {
		// Fallback keeps syncing them through the service account; no nag.
		$this->signInMode = Constants::GRAPH_AUTH_METHOD_PER_USER_FALLBACK;
		$this->givenSyncUserWithStatus(Constants::USER_STATUS_ACTIVE);
		$this->syncUserService->method('needsReconsent')->willReturn(false);

		$this->assertFalse($this->controller->shouldShowDialog()->getData());
	}

	public function testServiceAccountModeIgnoresGraphStatus(): void {
		// A stale row from before the admin switched modes must not nag anyone.
		$this->signInMode = Constants::GRAPH_AUTH_METHOD_SERVICE_ACCOUNT;
		$this->givenSyncUserWithStatus(Constants::USER_STATUS_ACTIVE);
		$this->givenGraphStatus('needsReconnect', 'refresh_token_revoked');
		$this->syncUserService->method('needsReconsent')->willReturn(false);

		$this->assertFalse($this->controller->shouldShowDialog()->getData());
	}

	public function testGraphStatusIsNotConsultedForInactiveUser(): void {
		// Not consented: the plain consent prompt applies, whatever Microsoft says.
		$this->signInMode = Constants::GRAPH_AUTH_METHOD_PER_USER_STRICT;
		$this->givenSyncUserWithStatus(Constants::USER_STATUS_INACTIVE);
		$this->authStatusMapper->expects($this->never())->method('findByUidAndProvider');

		$this->assertTrue($this->controller->shouldShowDialog()->getData());
	}
}
