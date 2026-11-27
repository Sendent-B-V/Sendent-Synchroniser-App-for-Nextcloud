<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Controller;

use OCA\SendentSynchroniser\Controller\SettingsController;
use OCA\SendentSynchroniser\Db\SyncUserMapper;
use OCA\SendentSynchroniser\Service\SyncUserService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\Notification\IManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * An admin removes groups from the active groups. The members who are in no
 * other active group are invalidated, and the invalidation removes their
 * per-event rows.
 */
class SettingsControllerSetActiveGroupsTest extends TestCase {

	private IGroupManager&MockObject $groupManager;
	private SyncUserService&MockObject $syncUserService;
	private SettingsController $controller;

	/** @var list<string> the users that were invalidated, in call order */
	private array $invalidated = [];

	protected function setUp(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValue')->willReturnCallback(
			fn ($key, $default = '') => $key === 'activeGroups' ? '["staff","sales","support"]' : $default
		);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->syncUserService = $this->createMock(SyncUserService::class);
		$this->syncUserService->method('invalidateUser')->willReturnCallback(
			function (string $uid): array {
				$this->invalidated[] = $uid;
				return [];
			}
		);
		$this->controller = new SettingsController(
			'sendentsynchroniser',
			$this->createMock(IRequest::class),
			'admin',
			$appConfig,
			$this->groupManager,
			new NullLogger(),
			$this->createMock(IManager::class),
			$this->createMock(SyncUserMapper::class),
			$this->syncUserService
		);
	}

	/**
	 * @param array<string, list<string>> $groupsOfUser user id => the groups of that user
	 */
	private function givenMembers(array $groupsOfUser): void {
		$users = [];
		$groups = [];
		$members = [];
		foreach ($groupsOfUser as $uid => $gids) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users[$uid] = $user;
			foreach ($gids as $gid) {
				$members[$gid][] = $user;
			}
		}
		foreach ($members as $gid => $groupMembers) {
			$group = $this->createMock(IGroup::class);
			$group->method('getGID')->willReturn($gid);
			$group->method('getUsers')->willReturn($groupMembers);
			$groups[$gid] = $group;
		}

		$this->groupManager->method('get')->willReturnCallback(
			fn (string $gid) => $groups[$gid] ?? null
		);
		$this->groupManager->method('getUserGroups')->willReturnCallback(
			fn (IUser $user) => array_map(fn (string $gid) => $groups[$gid], $groupsOfUser[$user->getUID()])
		);
	}

	public function testAMemberOfTheRemovedGroupIsInvalidated(): void {
		$this->givenMembers(['alice' => ['support'], 'bob' => ['staff']]);

		$this->controller->setActiveGroups(['staff', 'sales']);

		$this->assertSame(['alice'], $this->invalidated);
	}

	public function testAMemberWhoIsInAnotherActiveGroupIsNotInvalidated(): void {
		$this->givenMembers(['alice' => ['support', 'staff']]);

		$this->controller->setActiveGroups(['staff', 'sales']);

		$this->assertSame([], $this->invalidated);
	}

	public function testTheMembersOfEachRemovedGroupAreInvalidated(): void {
		$this->givenMembers(['alice' => ['sales'], 'bob' => ['support'], 'carol' => ['staff']]);

		$this->controller->setActiveGroups(['staff']);

		sort($this->invalidated);
		$this->assertSame(['alice', 'bob'], $this->invalidated);
	}

	public function testAMemberOfTwoRemovedGroupsIsNotLeftActive(): void {
		// Both groups of alice are removed in one save. No active group is
		// left for her.
		$this->givenMembers(['alice' => ['sales', 'support']]);

		$this->controller->setActiveGroups(['staff']);

		$this->assertContains('alice', $this->invalidated);
	}
}
