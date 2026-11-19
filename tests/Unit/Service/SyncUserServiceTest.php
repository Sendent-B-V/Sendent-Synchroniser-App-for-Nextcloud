<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service;

use OC\Authentication\Token\IProvider;
use OC\Authentication\Token\IToken;
use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Db\SyncUser;
use OCA\SendentSynchroniser\Db\SyncUserMapper;
use OCA\SendentSynchroniser\Service\SchedulingSuppressionService;
use OCA\SendentSynchroniser\Service\SyncUserService;
use OCP\Accounts\IAccountManager;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SyncUserServiceTest extends TestCase {

	private IProvider&MockObject $tokenProvider;
	private SyncUserMapper&MockObject $mapper;
	private SchedulingSuppressionService&MockObject $suppression;
	private IAppConfig&MockObject $appConfig;
	private IGroupManager&MockObject $groupManager;
	private IUserManager&MockObject $userManager;
	private SyncUserService $svc;

	protected function setUp(): void {
		$this->tokenProvider = $this->createMock(IProvider::class);
		$this->mapper = $this->createMock(SyncUserMapper::class);
		$this->suppression = $this->createMock(SchedulingSuppressionService::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->svc = new SyncUserService(
			$this->createMock(IAccountManager::class),
			'sendentsynchroniser',
			$this->appConfig,
			$this->groupManager,
			new NullLogger(),
			$this->tokenProvider,
			$this->userManager,
			$this->mapper,
			$this->suppression
		);
	}

	private function token(int $id, string $name): IToken&MockObject {
		$token = $this->createMock(IToken::class);
		$token->method('getId')->willReturn($id);
		$token->method('getName')->willReturn($name);
		$token->method('getUid')->willReturn('alice');
		return $token;
	}

	private function givenSyncUser(int $resetOffer, int $status = Constants::USER_STATUS_ACTIVE): SyncUser {
		$syncUser = new SyncUser();
		$syncUser->setUid('alice');
		$syncUser->setActive($status);
		$syncUser->setResetoffer($resetOffer);
		$this->mapper->method('findByUid')->with('alice')->willReturn([$syncUser]);
		return $syncUser;
	}

	private function givenSuppression(bool $enabled): void {
		$this->suppression->method('isSuppressionEnabled')->willReturn($enabled);
	}

	// ---- invalidateUser -------------------------------------------------

	public function testInvalidateUserRevokesBothTokenGenerations(): void {
		$this->givenSyncUser(0);
		$this->tokenProvider->method('getTokenByUser')->with('alice')->willReturn([
			$this->token(1, Constants::TOKEN_NAME_LEGACY),
			$this->token(2, Constants::TOKEN_NAME),
			$this->token(3, 'Firefox on Windows'),
		]);

		$invalidated = [];
		$this->tokenProvider->method('invalidateTokenById')
			->willReturnCallback(function ($uid, $id) use (&$invalidated) {
				$invalidated[] = $id;
			});

		$this->svc->invalidateUser('alice');
		$this->assertSame([1, 2], $invalidated);
	}

	public function testInvalidateUserLeavesResetOfferUntouched(): void {
		// The flag must survive every invalidation path; only activate() clears it.
		$syncUser = $this->givenSyncUser(1);
		$this->tokenProvider->method('getTokenByUser')->willReturn([]);

		$this->svc->invalidateUser('alice');
		$this->assertSame(1, $syncUser->getResetoffer());
	}

	// ---- hasAnyAppToken -------------------------------------------------

	public function testHasAnyAppTokenTrueForLegacyName(): void {
		$this->tokenProvider->method('getTokenByUser')->with('alice')->willReturn([
			$this->token(3, 'Firefox on Windows'),
			$this->token(1, Constants::TOKEN_NAME_LEGACY),
		]);
		$this->assertTrue($this->svc->hasAnyAppToken('alice'));
	}

	public function testHasAnyAppTokenTrueForNewName(): void {
		$this->tokenProvider->method('getTokenByUser')->with('alice')->willReturn([
			$this->token(2, Constants::TOKEN_NAME),
		]);
		$this->assertTrue($this->svc->hasAnyAppToken('alice'));
	}

	public function testHasAnyAppTokenFalseWhenOnlyForeignTokens(): void {
		$this->tokenProvider->method('getTokenByUser')->with('alice')->willReturn([
			$this->token(3, 'Firefox on Windows'),
		]);
		$this->assertFalse($this->svc->hasAnyAppToken('alice'));
	}

	// ---- hasPendingResetOffer --------------------------------------------

	public function testPendingOfferRequiresFlagAndSuppression(): void {
		$this->givenSyncUser(1);
		$this->givenSuppression(true);
		$this->assertTrue($this->svc->hasPendingResetOffer('alice'));
	}

	public function testPendingOfferFalseWhenFlagCleared(): void {
		$this->givenSyncUser(0);
		$this->givenSuppression(true);
		$this->assertFalse($this->svc->hasPendingResetOffer('alice'));
	}

	public function testPendingOfferFalseWhenSuppressionDisabled(): void {
		// The whole feature is inert while "Disable Nextcloud meeting
		// invitations" is set to Disabled — even the DB is not consulted.
		$this->givenSuppression(false);
		$this->mapper->expects($this->never())->method('findByUid');
		$this->assertFalse($this->svc->hasPendingResetOffer('alice'));
	}

	public function testPendingOfferFalseWithoutSyncUserRow(): void {
		$this->givenSuppression(true);
		$this->mapper->method('findByUid')->with('alice')->willReturn([]);
		$this->assertFalse($this->svc->hasPendingResetOffer('alice'));
	}

	// ---- needsReconsent ---------------------------------------------------

	public function testNeedsReconsentWhenNoAppTokenOfEitherName(): void {
		// ACTIVE row but the NC token vanished: silent dead state — must push.
		$this->givenSyncUser(0);
		$this->givenSuppression(true);
		$this->tokenProvider->method('getTokenByUser')->willReturn([]);
		$this->assertTrue($this->svc->needsReconsent('alice'));
	}

	public function testNeedsReconsentWhenOfferPending(): void {
		$this->givenSyncUser(1);
		$this->givenSuppression(true);
		$this->tokenProvider->method('getTokenByUser')->willReturn([$this->token(2, Constants::TOKEN_NAME)]);
		$this->assertTrue($this->svc->needsReconsent('alice'));
	}

	public function testNoReconsentWhenTokenPresentAndNothingPending(): void {
		$this->givenSyncUser(0);
		$this->givenSuppression(true);
		$this->tokenProvider->method('getTokenByUser')->willReturn([$this->token(2, Constants::TOKEN_NAME)]);
		$this->assertFalse($this->svc->needsReconsent('alice'));
	}

	public function testNoReconsentForPendingFlagWhileSuppressionDisabled(): void {
		$this->givenSyncUser(1);
		$this->givenSuppression(false);
		$this->tokenProvider->method('getTokenByUser')->willReturn([$this->token(2, Constants::TOKEN_NAME)]);
		$this->assertFalse($this->svc->needsReconsent('alice'));
	}

	// ---- clearResetOffer --------------------------------------------------

	public function testClearResetOfferPersistsZero(): void {
		$syncUser = $this->givenSyncUser(1);
		$this->givenSuppression(true);
		$this->mapper->expects($this->once())->method('update')->with($syncUser);

		$this->svc->clearResetOffer('alice');
		$this->assertSame(0, $syncUser->getResetoffer());
	}

	public function testClearResetOfferIsNoopWhenAlreadyZero(): void {
		$this->givenSyncUser(0);
		$this->givenSuppression(true);
		$this->mapper->expects($this->never())->method('update');

		$this->svc->clearResetOffer('alice');
	}

	public function testClearResetOfferKeepsFlagWhileSuppressionDisabled(): void {
		// Feature gated off: the user re-consents without ever seeing the offer,
		// so the flag must survive for when the admin enables the setting later.
		$syncUser = $this->givenSyncUser(1);
		$this->givenSuppression(false);
		$this->mapper->expects($this->never())->method('update');

		$this->svc->clearResetOffer('alice');
		$this->assertSame(1, $syncUser->getResetoffer());
	}

	public function testClearResetOfferIsNoopWithoutRow(): void {
		$this->givenSuppression(true);
		$this->mapper->method('findByUid')->willReturn([]);
		$this->mapper->expects($this->never())->method('update');

		$this->svc->clearResetOffer('alice');
	}

	private function activeGroups(string $json): void {
		// isValidUser() is answered from the synced-calendars lookup, which
		// also reads the calendar setting, so every setting needs a value.
		$this->settings(['activeGroups' => $json]);
	}

	private function syncUserWithStatus(int|string $status): SyncUser {
		$user = new SyncUser();
		$user->setActive($status);
		return $user;
	}

	public function testAnActivatedUserInAnActiveGroupIsValid(): void {
		$this->activeGroups('["sync"]');
		$this->mapper->method('findByUid')->with('alice')->willReturn([$this->syncUserWithStatus(Constants::USER_STATUS_ACTIVE)]);
		$this->groupManager->method('isInGroup')->with('alice', 'sync')->willReturn(true);

		$this->assertTrue($this->svc->isValidUser('alice'));
	}

	public function testAUserWhoNeverActivatedIsNotValid(): void {
		$this->activeGroups('["sync"]');
		$this->mapper->method('findByUid')->willReturn([]);
		$this->groupManager->method('isInGroup')->willReturn(true);

		$this->assertFalse($this->svc->isValidUser('alice'));
	}

	public function testAUserWhoRetractedConsentIsNotValid(): void {
		$this->activeGroups('["sync"]');
		$this->mapper->method('findByUid')->willReturn([$this->syncUserWithStatus(Constants::USER_STATUS_NOCONSENT)]);
		$this->groupManager->method('isInGroup')->willReturn(true);

		$this->assertFalse($this->svc->isValidUser('alice'));
	}

	public function testAnActivatedUserOutsideEveryActiveGroupIsNotValid(): void {
		$this->activeGroups('["sync"]');
		$this->mapper->method('findByUid')->willReturn([$this->syncUserWithStatus(Constants::USER_STATUS_ACTIVE)]);
		$this->groupManager->method('isInGroup')->willReturn(false);

		$this->assertFalse($this->svc->isValidUser('alice'));
	}

	public function testAStatusReadBackAsAStringStillCounts(): void {
		// Depending on the driver, the integer column can come back as "1".
		$this->activeGroups('["sync"]');
		$this->mapper->method('findByUid')->willReturn([$this->syncUserWithStatus('1')]);
		$this->groupManager->method('isInGroup')->willReturn(true);

		$this->assertTrue($this->svc->isValidUser('alice'));
	}

	public function testMalformedActiveGroupsMakeNobodyValid(): void {
		$this->activeGroups('not json');
		$this->mapper->method('findByUid')->willReturn([$this->syncUserWithStatus(Constants::USER_STATUS_ACTIVE)]);
		$this->groupManager->method('isInGroup')->willReturn(true);

		$this->assertFalse($this->svc->isValidUser('alice'));
	}

	// ---- synced calendars ---------------------------------------------------

	/**
	 * The resolution order: the user's own value, then the app setting, then
	 * 'personal'. Null and the empty string both count as "not set". Today
	 * the synced set holds exactly that one URI.
	 *
	 * @return array<string, array{?string, string, string}> stored on the user's row, app setting, expected URI
	 */
	public static function syncedCalendarCases(): array {
		return [
			'the per-user value wins over the setting' => ['work', 'team', 'work'],
			'the per-user value wins when the setting is empty' => ['work', '', 'work'],
			'a null per-user value falls to the setting' => [null, 'team', 'team'],
			'an empty per-user value falls to the setting' => ['', 'team', 'team'],
			'null and an empty setting fall to personal' => [null, '', 'personal'],
			'empty and an empty setting fall to personal' => ['', '', 'personal'],
		];
	}

	/** Every app setting the service reads, at its default unless overridden. */
	private function settings(array $overrides = []): void {
		$values = $overrides + [
			'activeGroups' => '["sync"]',
			'defaultCalendar' => '',
			'defaultAddressbook' => '',
			'emailTemplate' => '',
			'emailDomain' => '',
		];
		$map = [];
		foreach ($values as $key => $value) {
			$map[] = [$key, '', $value];
		}
		$this->appConfig->method('getAppValue')->willReturnMap($map);
	}

	private function syncUserWithCalendar(?string $calendar, int|string $status = Constants::USER_STATUS_ACTIVE): SyncUser {
		$user = new SyncUser();
		$user->setUid('alice');
		$user->setActive($status);
		$user->setCalendar($calendar);
		return $user;
	}

	/** Makes alice a member of the active group 'sync', as getValidUsers() walks it. */
	private function givenAliceInTheActiveGroup(): void {
		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$alice->method('getDisplayName')->willReturn('Alice');
		$alice->method('getEmailAddress')->willReturn('alice@example.com');

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$alice]);

		$this->groupManager->method('get')->with('sync')->willReturn($group);
		$this->groupManager->method('isInGroup')->with('alice', 'sync')->willReturn(true);
		$this->userManager->method('get')->with('alice')->willReturn($alice);
	}

	#[DataProvider('syncedCalendarCases')]
	public function testTheSyncedSetHoldsTheOneCalendarFromTheResolutionOrder(?string $stored, string $setting, string $expected): void {
		$this->settings(['defaultCalendar' => $setting]);

		$this->assertSame([$expected], $this->svc->resolveSyncedCalendars($this->syncUserWithCalendar($stored)));
	}

	public function testResolvingTheSyncedCalendarsDoesNotLoadOrChangeTheRow(): void {
		// The caller hands the row in; the rule only reads it.
		$this->settings(['defaultCalendar' => 'team']);
		$this->mapper->expects($this->never())->method('findByUid');
		$this->mapper->expects($this->never())->method('update');
		$syncUser = $this->syncUserWithCalendar(null);

		$this->svc->resolveSyncedCalendars($syncUser);

		$this->assertNull($syncUser->getCalendar());
	}

	#[DataProvider('syncedCalendarCases')]
	public function testTheValidUserListReportsTheSameCalendarAsBefore(?string $stored, string $setting, string $expected): void {
		$this->settings(['defaultCalendar' => $setting]);
		$this->givenAliceInTheActiveGroup();
		$this->mapper->method('findByUid')->with('alice')->willReturn([$this->syncUserWithCalendar($stored)]);

		$users = $this->svc->getValidUsers();

		$this->assertCount(1, $users);
		$this->assertSame('alice', $users[0]['uid']);
		$this->assertSame($expected, $users[0]['calendar']);
		// The address book keeps its own fallback and is not touched by the calendar rule.
		$this->assertSame('contacts', $users[0]['addressbook']);
	}

	#[DataProvider('syncedCalendarCases')]
	public function testAValidUserGetsTheirSyncedCalendars(?string $stored, string $setting, string $expected): void {
		$this->settings(['defaultCalendar' => $setting]);
		$this->mapper->method('findByUid')->with('alice')->willReturn([$this->syncUserWithCalendar($stored)]);
		$this->groupManager->method('isInGroup')->with('alice', 'sync')->willReturn(true);

		$this->assertSame([$expected], $this->svc->syncedCalendarsOfValidUser('alice'));
	}

	public function testTheCalendarInTheListIsOneOfTheSyncedCalendars(): void {
		// The calendar the Connector is told about must be one the feed writes per-event rows for.
		$this->settings(['defaultCalendar' => 'team']);
		$this->givenAliceInTheActiveGroup();
		$this->mapper->method('findByUid')->with('alice')->willReturn([$this->syncUserWithCalendar('')]);

		$this->assertContains(
			$this->svc->getValidUsers()[0]['calendar'],
			$this->svc->syncedCalendarsOfValidUser('alice')
		);
	}

	public function testAUserWhoNeverActivatedHasNoSyncedCalendars(): void {
		$this->settings(['defaultCalendar' => 'team']);
		$this->mapper->method('findByUid')->willReturn([]);
		$this->groupManager->method('isInGroup')->willReturn(true);

		$this->assertNull($this->svc->syncedCalendarsOfValidUser('alice'));
	}

	public function testAUserWhoRetractedConsentHasNoSyncedCalendars(): void {
		$this->settings();
		$this->mapper->method('findByUid')->willReturn([$this->syncUserWithCalendar('work', Constants::USER_STATUS_NOCONSENT)]);
		$this->groupManager->method('isInGroup')->willReturn(true);

		$this->assertNull($this->svc->syncedCalendarsOfValidUser('alice'));
	}

	public function testAnInactiveUserHasNoSyncedCalendars(): void {
		$this->settings();
		$this->mapper->method('findByUid')->willReturn([$this->syncUserWithCalendar('work', Constants::USER_STATUS_INACTIVE)]);
		$this->groupManager->method('isInGroup')->willReturn(true);

		$this->assertNull($this->svc->syncedCalendarsOfValidUser('alice'));
	}

	public function testAnActivatedUserOutsideEveryActiveGroupHasNoSyncedCalendars(): void {
		$this->settings();
		$this->mapper->method('findByUid')->willReturn([$this->syncUserWithCalendar('work')]);
		$this->groupManager->method('isInGroup')->willReturn(false);

		$this->assertNull($this->svc->syncedCalendarsOfValidUser('alice'));
	}

	public function testMalformedActiveGroupsGiveNobodySyncedCalendars(): void {
		$this->settings(['activeGroups' => 'not json']);
		$this->mapper->method('findByUid')->willReturn([$this->syncUserWithCalendar('work')]);
		$this->groupManager->method('isInGroup')->willReturn(true);

		$this->assertNull($this->svc->syncedCalendarsOfValidUser('alice'));
	}

	public function testAStatusReadBackAsAStringStillGetsSyncedCalendars(): void {
		// Same driver quirk as for isValidUser(): the two must agree.
		$this->settings();
		$this->mapper->method('findByUid')->willReturn([$this->syncUserWithCalendar('work', '1')]);
		$this->groupManager->method('isInGroup')->willReturn(true);

		$this->assertSame(['work'], $this->svc->syncedCalendarsOfValidUser('alice'));
	}

	public function testTheSyncedCalendarsLookupLoadsTheRowOnce(): void {
		$this->settings();
		$this->mapper->expects($this->once())->method('findByUid')->with('alice')
			->willReturn([$this->syncUserWithCalendar('work')]);
		$this->groupManager->method('isInGroup')->willReturn(true);

		$this->svc->syncedCalendarsOfValidUser('alice');
	}
}
