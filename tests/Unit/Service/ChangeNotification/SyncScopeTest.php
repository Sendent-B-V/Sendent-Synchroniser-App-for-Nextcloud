<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Db\SyncUser;
use OCA\SendentSynchroniser\Db\SyncUserMapper;
use OCA\SendentSynchroniser\Service\ChangeNotification\SyncScope;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SyncScopeTest extends TestCase {

	/** @var SyncUserMapper&MockObject */
	private $mapper;

	/** @var IGroupManager&MockObject */
	private $groupManager;

	private SyncScope $scope;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(SyncUserMapper::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValue')->willReturnMap([
			['activeGroups', '', '["sync"]'],
		]);
		$this->scope = new SyncScope($this->mapper, $this->groupManager, $appConfig);
	}

	private function ref(string $uid): CollectionReference {
		return new CollectionReference('principals/users/' . $uid, 'caldav', 'personal', false);
	}

	private function syncUser(int $status): SyncUser {
		$user = new SyncUser();
		$user->setActive($status);
		return $user;
	}

	public function testIncludesAnActivatedUserInAnActiveGroup(): void {
		$this->mapper->method('findByUid')->with('alice')->willReturn([$this->syncUser(Constants::USER_STATUS_ACTIVE)]);
		$this->groupManager->method('isInGroup')->with('alice', 'sync')->willReturn(true);

		$this->assertTrue($this->scope->includes($this->ref('alice')));
	}

	public function testExcludesAUserWhoNeverActivated(): void {
		$this->mapper->method('findByUid')->willReturn([]);
		$this->groupManager->method('isInGroup')->willReturn(true);

		$this->assertFalse($this->scope->includes($this->ref('alice')));
	}

	public function testExcludesAUserWhoRetractedConsent(): void {
		$this->mapper->method('findByUid')->willReturn([$this->syncUser(Constants::USER_STATUS_NOCONSENT)]);
		$this->groupManager->method('isInGroup')->willReturn(true);

		$this->assertFalse($this->scope->includes($this->ref('alice')));
	}

	public function testExcludesAnActivatedUserOutsideEveryActiveGroup(): void {
		$this->mapper->method('findByUid')->willReturn([$this->syncUser(Constants::USER_STATUS_ACTIVE)]);
		$this->groupManager->method('isInGroup')->willReturn(false);

		$this->assertFalse($this->scope->includes($this->ref('alice')));
	}

	public function testAStatusReadBackAsAStringStillCounts(): void {
		// Depending on the driver, the integer column can come back as "1".
		$user = new SyncUser();
		$user->setActive('1');
		$this->mapper->method('findByUid')->willReturn([$user]);
		$this->groupManager->method('isInGroup')->willReturn(true);

		$this->assertTrue($this->scope->includes($this->ref('alice')));
	}

	public function testEachUserIsLookedUpOncePerProcess(): void {
		// A bulk DAV request fires one event per object, all for the same owner.
		$this->mapper->expects($this->once())->method('findByUid')->willReturn([$this->syncUser(Constants::USER_STATUS_ACTIVE)]);
		$this->groupManager->method('isInGroup')->willReturn(true);

		$this->scope->includes($this->ref('alice'));
		$this->scope->includes($this->ref('alice'));
	}
}
