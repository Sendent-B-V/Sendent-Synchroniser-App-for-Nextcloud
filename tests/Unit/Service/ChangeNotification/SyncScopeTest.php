<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Service\ChangeNotification\SyncScope;
use OCA\SendentSynchroniser\Service\SyncUserService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The rules themselves (who is a valid sync user, which calendars are synced)
 * live in SyncUserService and are tested there.
 */
class SyncScopeTest extends TestCase {

	/** @var SyncUserService&MockObject */
	private $syncUsers;

	private SyncScope $scope;

	/**
	 * What SyncUserService knows: user id => synced calendar URIs. Anyone not
	 * listed is not a valid sync user.
	 * @var array<string, list<string>>
	 */
	private array $validUsers = [];

	/** Calls to SyncUserService, whichever of its lookups was used. */
	private int $lookups = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->syncUsers = $this->createMock(SyncUserService::class);
		// Both lookups answer from the same list, so these tests hold whichever
		// one SyncScope uses, and count the cost the same way.
		$this->syncUsers->method('isValidUser')->willReturnCallback(function (string $uid): bool {
			$this->lookups++;
			return isset($this->validUsers[$uid]);
		});
		$this->syncUsers->method('syncedCalendarsOfValidUser')->willReturnCallback(function (string $uid): ?array {
			$this->lookups++;
			return $this->validUsers[$uid] ?? null;
		});
		$this->scope = new SyncScope($this->syncUsers);
	}

	private function ref(string $uid, string $collectionUri = 'personal', string $type = Constants::COLLECTION_TYPE_CALDAV): CollectionReference {
		return new CollectionReference('principals/users/' . $uid, $type, $collectionUri, false);
	}

	// ---- includes ---------------------------------------------------------

	public function testIncludesTheCollectionsOfAValidUser(): void {
		$this->validUsers = ['alice' => ['personal']];

		$this->assertTrue($this->scope->includes($this->ref('alice')));
	}

	public function testExcludesTheCollectionsOfAnyoneElse(): void {
		$this->validUsers = ['alice' => ['personal']];

		$this->assertFalse($this->scope->includes($this->ref('bob')));
	}

	public function testEachUserIsLookedUpOncePerProcess(): void {
		// A bulk DAV request fires one event per object, all for the same owner.
		$this->validUsers = ['alice' => ['personal']];

		$this->scope->includes($this->ref('alice'));
		$this->scope->includes($this->ref('alice'));

		$this->assertSame(1, $this->lookups);
	}

	public function testSomeoneWhoIsNotAValidUserIsAlsoLookedUpOnlyOnce(): void {
		$this->scope->includes($this->ref('bob'));
		$this->scope->includes($this->ref('bob'));

		$this->assertSame(1, $this->lookups);
	}

	public function testIncludesEveryCalendarAndAddressBookOfAValidUser(): void {
		// Per-collection rows are written for all of them, not only the synced calendar.
		$this->validUsers = ['alice' => ['personal']];

		$this->assertTrue($this->scope->includes($this->ref('alice', 'personal')));
		$this->assertTrue($this->scope->includes($this->ref('alice', 'work')));
		$this->assertTrue($this->scope->includes($this->ref('alice', 'contacts', Constants::COLLECTION_TYPE_CARDDAV)));
		$this->assertTrue($this->scope->includes($this->ref('alice', 'personal', Constants::COLLECTION_TYPE_CARDDAV)));
	}

	public function testIncludesStaysTrueAfterTheSyncedCalendarQuestionSaidNo(): void {
		$this->validUsers = ['alice' => ['personal']];

		$this->assertFalse($this->scope->isSyncedCalendar($this->ref('alice', 'work')));
		$this->assertTrue($this->scope->includes($this->ref('alice', 'work')));
	}

	// ---- isSyncedCalendar -------------------------------------------------

	public function testTheSyncedCalendarOfAValidUserMatches(): void {
		$this->validUsers = ['alice' => ['personal']];

		$this->assertTrue($this->scope->isSyncedCalendar($this->ref('alice', 'personal')));
	}

	public function testASyncedCalendarOtherThanPersonalMatches(): void {
		$this->validUsers = ['alice' => ['work']];

		$this->assertTrue($this->scope->isSyncedCalendar($this->ref('alice', 'work')));
		$this->assertFalse($this->scope->isSyncedCalendar($this->ref('alice', 'personal')));
	}

	public function testAnotherCalendarOfTheSameUserDoesNotMatch(): void {
		$this->validUsers = ['alice' => ['personal']];

		$this->assertFalse($this->scope->isSyncedCalendar($this->ref('alice', 'work')));
	}

	public function testNoCalendarOfSomeoneWhoIsNotAValidUserMatches(): void {
		// Inactive and unknown users look the same from here: no synced calendar.
		$this->validUsers = ['alice' => ['personal']];

		$this->assertFalse($this->scope->isSyncedCalendar($this->ref('bob', 'personal')));
	}

	public function testAnAddressBookWithTheSameUriDoesNotMatch(): void {
		$this->validUsers = ['alice' => ['personal']];

		$this->assertFalse($this->scope->isSyncedCalendar($this->ref('alice', 'personal', Constants::COLLECTION_TYPE_CARDDAV)));
	}

	public function testASettingThatNamesAnotherCalendarMatchesNothingAndRaisesNothing(): void {
		// The setting is free text. When it names a calendar the user does not
		// have, no reference ever equals it: no match, and no error either.
		$this->validUsers = ['alice' => ['does-not-exist']];

		$this->assertFalse($this->scope->isSyncedCalendar($this->ref('alice', 'personal')));
		$this->assertFalse($this->scope->isSyncedCalendar($this->ref('alice', 'work')));
		$this->assertTrue($this->scope->includes($this->ref('alice', 'personal')));
	}

	public function testEachUserHasTheirOwnSyncedCalendar(): void {
		$this->validUsers = ['alice' => ['personal'], 'carol' => ['work']];

		$this->assertTrue($this->scope->isSyncedCalendar($this->ref('alice', 'personal')));
		$this->assertFalse($this->scope->isSyncedCalendar($this->ref('carol', 'personal')));
		$this->assertTrue($this->scope->isSyncedCalendar($this->ref('carol', 'work')));
	}

	// ---- more than one synced calendar ------------------------------------

	public function testEveryCalendarInTheSyncedSetMatches(): void {
		$this->validUsers = ['alice' => ['personal', 'work']];

		$this->assertTrue($this->scope->isSyncedCalendar($this->ref('alice', 'personal')));
		$this->assertTrue($this->scope->isSyncedCalendar($this->ref('alice', 'work')));
	}

	public function testACalendarOutsideTheSyncedSetDoesNotMatch(): void {
		$this->validUsers = ['alice' => ['personal', 'work']];

		$this->assertFalse($this->scope->isSyncedCalendar($this->ref('alice', 'holidays')));
		$this->assertTrue($this->scope->includes($this->ref('alice', 'holidays')));
	}

	public function testNoAddressBookMatchesAnyUriInTheSyncedSet(): void {
		$this->validUsers = ['alice' => ['personal', 'work']];

		$this->assertFalse($this->scope->isSyncedCalendar($this->ref('alice', 'personal', Constants::COLLECTION_TYPE_CARDDAV)));
		$this->assertFalse($this->scope->isSyncedCalendar($this->ref('alice', 'work', Constants::COLLECTION_TYPE_CARDDAV)));
	}

	public function testAUriOnlyMatchesItselfExactly(): void {
		// Calendar URIs are free text, and a URI made of digits is still text.
		$this->validUsers = ['alice' => ['0', '1e1']];

		$this->assertTrue($this->scope->isSyncedCalendar($this->ref('alice', '0')));
		$this->assertFalse($this->scope->isSyncedCalendar($this->ref('alice', '00')));
		$this->assertFalse($this->scope->isSyncedCalendar($this->ref('alice', '10')));
	}

	public function testSeveralSyncedCalendarsStillCostOneLookup(): void {
		$this->validUsers = ['alice' => ['personal', 'work']];

		$this->scope->includes($this->ref('alice', 'personal'));
		$this->scope->isSyncedCalendar($this->ref('alice', 'personal'));
		$this->scope->isSyncedCalendar($this->ref('alice', 'work'));

		$this->assertSame(1, $this->lookups);
	}

	// ---- one lookup for both questions --------------------------------------

	public function testTheSyncedCalendarQuestionLooksAUserUpOnce(): void {
		$this->validUsers = ['alice' => ['personal']];

		$this->scope->isSyncedCalendar($this->ref('alice', 'personal'));
		$this->scope->isSyncedCalendar($this->ref('alice', 'work'));

		$this->assertSame(1, $this->lookups);
	}

	public function testBothQuestionsForOneUserCostOneLookup(): void {
		$this->validUsers = ['alice' => ['personal']];

		$this->assertTrue($this->scope->includes($this->ref('alice', 'personal')));
		$this->assertTrue($this->scope->isSyncedCalendar($this->ref('alice', 'personal')));

		$this->assertSame(1, $this->lookups);
	}

	public function testBothQuestionsCostOneLookupInTheOtherOrderToo(): void {
		$this->validUsers = ['alice' => ['personal']];

		$this->assertTrue($this->scope->isSyncedCalendar($this->ref('alice', 'personal')));
		$this->assertTrue($this->scope->includes($this->ref('alice', 'personal')));

		$this->assertSame(1, $this->lookups);
	}

	public function testBothQuestionsForSomeoneWhoIsNotAValidUserCostOneLookup(): void {
		$this->assertFalse($this->scope->includes($this->ref('bob', 'personal')));
		$this->assertFalse($this->scope->isSyncedCalendar($this->ref('bob', 'personal')));

		$this->assertSame(1, $this->lookups);
	}
}
