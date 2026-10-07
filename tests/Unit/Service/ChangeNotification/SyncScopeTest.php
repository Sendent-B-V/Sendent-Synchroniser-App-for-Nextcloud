<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Service\ChangeNotification\SyncScope;
use OCA\SendentSynchroniser\Service\SyncUserService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** The rule itself is SyncUserService::isValidUser(), tested there. */
class SyncScopeTest extends TestCase {

	/** @var SyncUserService&MockObject */
	private $syncUsers;

	private SyncScope $scope;

	protected function setUp(): void {
		parent::setUp();
		$this->syncUsers = $this->createMock(SyncUserService::class);
		$this->scope = new SyncScope($this->syncUsers);
	}

	private function ref(string $uid): CollectionReference {
		return new CollectionReference('principals/users/' . $uid, 'caldav', 'personal', false);
	}

	public function testIncludesTheCollectionsOfAValidUser(): void {
		$this->syncUsers->method('isValidUser')->with('alice')->willReturn(true);

		$this->assertTrue($this->scope->includes($this->ref('alice')));
	}

	public function testExcludesTheCollectionsOfAnyoneElse(): void {
		$this->syncUsers->method('isValidUser')->with('bob')->willReturn(false);

		$this->assertFalse($this->scope->includes($this->ref('bob')));
	}

	public function testEachUserIsLookedUpOncePerProcess(): void {
		// A bulk DAV request fires one event per object, all for the same owner.
		$this->syncUsers->expects($this->once())->method('isValidUser')->willReturn(true);

		$this->scope->includes($this->ref('alice'));
		$this->scope->includes($this->ref('alice'));
	}
}
