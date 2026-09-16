<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeFeedGuard;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ChangeFeedGuardTest extends TestCase {

	/** @var IUserSession&MockObject */
	private $userSession;

	/** @var IGroupManager&MockObject */
	private $groupManager;

	/** @var ChangeNotificationConfig&MockObject */
	private $config;

	private ChangeFeedGuard $guard;

	protected function setUp(): void {
		parent::setUp();
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->config = $this->createMock(ChangeNotificationConfig::class);
		$this->guard = new ChangeFeedGuard($this->userSession, $this->groupManager, $this->config);
	}

	private function loginAs(?string $uid): void {
		if ($uid === null) {
			$this->userSession->method('getUser')->willReturn(null);
			return;
		}
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testTheBotUserIsAllowed(): void {
		$this->config->method('botUser')->willReturn('sendent-sync');
		$this->loginAs('sendent-sync');

		$this->assertTrue($this->guard->isAllowed());
	}

	public function testAnAdminIsAllowed(): void {
		$this->config->method('botUser')->willReturn('sendent-sync');
		$this->loginAs('root');
		$this->groupManager->method('isAdmin')->with('root')->willReturn(true);

		$this->assertTrue($this->guard->isAllowed());
	}

	public function testAnyOtherUserIsRejected(): void {
		$this->config->method('botUser')->willReturn('sendent-sync');
		$this->loginAs('mallory');
		$this->groupManager->method('isAdmin')->willReturn(false);

		$this->assertFalse($this->guard->isAllowed());
	}

	public function testAnonymousIsRejected(): void {
		$this->loginAs(null);

		$this->assertFalse($this->guard->isAllowed());
	}

	public function testNobodyMatchesAnUnconfiguredBotUser(): void {
		// Empty botUser must not mean "everyone whose uid is empty matches".
		$this->config->method('botUser')->willReturn('');
		$this->loginAs('mallory');
		$this->groupManager->method('isAdmin')->willReturn(false);

		$this->assertFalse($this->guard->isAllowed());
	}
}
