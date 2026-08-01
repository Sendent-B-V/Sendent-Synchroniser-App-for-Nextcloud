# Legacy Calendar Reset (one-time, consent-flow, token-name state) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Offer users of the legacy Sendent sync client a one-time "delete and re-create your synced calendar" step inside the consent flow, with the app-token *name* carrying all migration state — no repair step, no new stored state anywhere.

**Architecture:** New app tokens are named `sendent-synchronization`; tokens minted before this release are named `sendentsynchroniser`. Holding a legacy-named token is the marker of a pre-rework consent: `shouldShowDialog` shows the consent modal for ACTIVE users who still hold one (that is the push), and inside the flow a new `CalendarResetService` offers the reset when the legacy token exists AND the sync target calendar contains the `X-SENDENT` iCal marker. Completing the flow calls `activate()`, which invalidates the old token and mints a new-named one — that token swap *is* the once-only state: no legacy token → no modal push, no offer, ever again. The reset itself is a single atomic hard-delete (`CalDavBackend::deleteCalendar($id, true)` — verified on NC stable30: permanently deletes objects/shares/invitations, never lands in the trashbin, and equally purges a calendar already sitting in the trashbin from an earlier web-UI delete, freeing the URI it still occupies) followed by `createCalendar()` re-using the old calendar's displayname/color/order/timezone (copying the localized name verbatim is what preserves the user's locale).

**Tech Stack:** Nextcloud app (NC 28–33), PHP 8.1+, `OCA\DAV\CalDAV\CalDavBackend`, `OC\Authentication\Token\IProvider`, PHPUnit 10/11, Vue 3 `<script setup>` + `@nextcloud/axios`.

**Key design decisions (already made — do not re-litigate):**
- **State = token name, nothing else.** No `calendarResetState` preferences key, no repair step, no schema change. The offer's once-only guarantee is the token swap in `activate()`. Consequences accepted by the product owner: (a) old tokens stay valid until each user individually re-consents — dormant users keep syncing on legacy tokens indefinitely; (b) declining the reset is remembered only via completing activation — a user who *abandons* the flow (closes the modal) is re-offered next time, which is intended; (c) the X-SENDENT scan re-runs on each consent-flow entry until the user completes the flow (bounded: only legacy-token holders ever reach the scan).
- **Dual-name invalidation is security-critical.** `SyncUserService::invalidateUser()` currently matches `getName() === $this->appName`; it must match BOTH `sendent-synchronization` and `sendentsynchroniser` forever, or retracting consent stops revoking legacy tokens. This is the only name-matching site in the codebase (verified: `TokenInvalidInjector` matches no names).
- **Push mechanism:** `shouldShowDialog` returns true for an ACTIVE user holding a legacy-named token. All other gating (sharedSecret, reminderType, cookie, active groups, NOCONSENT excluded) stays untouched — the existing modal + `NotifyInactiveUsers` machinery does the nagging; no new prompting code.
- Detection = legacy token exists AND `X-SENDENT` substring in an event's `calendardata` in the user's *sync target* calendar (per-user `sndntsync_users.calendar` → admin `defaultCalendar` app value → `personal`). The scan protects prior users whose calendar holds only Nextcloud-native events from a pointless destructive offer.
- The reset runs **before** `user/activate` (which still holds the legacy token — the check happens before anything invalidates it), so the new client's initial sync lands in a fresh calendar.
- **Trashbinned calendars occupy their URI.** Web-UI/CalDAV deletes always soft-delete into the trashbin, and `oc_calendars` has a unique index `calendars_index` on `(principaluri, uri)` regardless of `deleted_at` — a trashbinned `personal` calendar blocks recreation until purged. `findCalendar()` returns the trashbinned calendar when no live one exists, and `reset()` purges it with the same `deleteCalendar($id, true)` call.
- No cancellation e-mails: backend-level `deleteCalendar()` is pure DB deletion + typed event dispatch (verified in NC stable30 source); Sabre's iMIP/iTip plugins only run on DAV HTTP requests, which this path bypasses.
- Shares on the old calendar are permanently destroyed (bound to the internal resource id) and are **not** restored — UI copy warns; recipients are logged for support.
- If the deleted calendar was the user's Nextcloud default (`dav`/`defaultCalendarId`), re-point that preference at the new calendar id.
- **Connector sequencing (accepted by product owner):** until a user re-consents, the connector still receives them via `user/actives` with their legacy token and keeps syncing into the not-yet-reset calendar. Deploy the new connector together with this app release.

**Testing note:** PHP unit tests only execute inside a Nextcloud server checkout (`tests/bootstrap.php` requires `../../../tests/bootstrap.php`; CI clones stable30–33 and runs `composer run test` from `nextcloud/apps/sendentsynchroniser`). In this standalone clone the phpunit commands below fail at bootstrap. If you have a local NC dev checkout, copy/symlink the app to `<server>/apps/sendentsynchroniser` and run from there. Otherwise: still write the tests first, verify every changed PHP file with `php -l`, and treat CI as the test runner — do not claim tests pass without CI or a NC checkout run.

---

### Task 1: Token-name constants

**Files:**
- Modify: `lib/Constants.php`
- Test: `tests/Unit/ConstantsTest.php`

- [ ] **Step 1: Add failing assertions to the existing constants test**

Append inside the existing test class in `tests/Unit/ConstantsTest.php` (the file already imports `Constants` and uses this short-reference style):

```php
	public function testTokenNames(): void {
		$this->assertSame('sendent-synchronization', Constants::TOKEN_NAME);
		$this->assertSame('sendentsynchroniser', Constants::TOKEN_NAME_LEGACY);
	}
```

- [ ] **Step 2: Run the test to verify it fails**

Run (inside a NC checkout; else `php -l tests/Unit/ConstantsTest.php` and rely on CI):
`php vendor/phpunit/phpunit/phpunit -c phpunit.xml --filter ConstantsTest`
Expected: FAIL — undefined constant `TOKEN_NAME`.

- [ ] **Step 3: Add the constants**

In `lib/Constants.php`, after the `GRAPH_API_MODE_*` constants (line 23), add:

```php
	// App-token names. Tokens minted before the architecture rework carry the
	// legacy name (the app id); new tokens carry TOKEN_NAME. A user still
	// holding a legacy-named token has not yet re-consented — that difference
	// drives the consent-modal push and the one-time calendar reset offer.
	public const TOKEN_NAME = 'sendent-synchronization';
	public const TOKEN_NAME_LEGACY = 'sendentsynchroniser';
```

- [ ] **Step 4: Run the test to verify it passes**

`php vendor/phpunit/phpunit/phpunit -c phpunit.xml --filter ConstantsTest`
Expected: PASS (standalone clone: `php -l lib/Constants.php` → "No syntax errors detected").

- [ ] **Step 5: Commit**

```bash
git add lib/Constants.php tests/Unit/ConstantsTest.php
git commit -m "feat: add app-token name constants for legacy detection"
```

---

### Task 2: Token rename + dual-name invalidation + hasLegacyToken

**Files:**
- Modify: `lib/Service/SyncUserService.php` (invalidateUser at lines 74–79; new method `hasLegacyToken`)
- Modify: `lib/Controller/UserController.php:138` (token name in `generateToken`)
- Test: `tests/Unit/Service/SyncUserServiceTest.php` (new file)

**Security note:** if `invalidateUser()` matched only the new name, retracting consent would stop revoking legacy tokens — the old client would keep a valid credential. The dual-name test below is the guard against regressing this.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Service/SyncUserServiceTest.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service;

use OC\Authentication\Token\IProvider;
use OC\Authentication\Token\IToken;
use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Db\SyncUser;
use OCA\SendentSynchroniser\Db\SyncUserMapper;
use OCA\SendentSynchroniser\Service\SyncUserService;
use OCP\Accounts\IAccountManager;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SyncUserServiceTest extends TestCase {

	private IProvider&MockObject $tokenProvider;
	private SyncUserMapper&MockObject $mapper;
	private SyncUserService $svc;

	protected function setUp(): void {
		$this->tokenProvider = $this->createMock(IProvider::class);
		$this->mapper = $this->createMock(SyncUserMapper::class);
		$this->svc = new SyncUserService(
			$this->createMock(IAccountManager::class),
			'sendentsynchroniser',
			$this->createMock(IAppConfig::class),
			$this->createMock(IGroupManager::class),
			new NullLogger(),
			$this->tokenProvider,
			$this->createMock(IUserManager::class),
			$this->mapper
		);
	}

	private function token(int $id, string $name): IToken&MockObject {
		$token = $this->createMock(IToken::class);
		$token->method('getId')->willReturn($id);
		$token->method('getName')->willReturn($name);
		$token->method('getUid')->willReturn('alice');
		return $token;
	}

	public function testInvalidateUserRevokesBothTokenGenerations(): void {
		$syncUser = new SyncUser();
		$syncUser->setUid('alice');
		$this->mapper->method('findByUid')->with('alice')->willReturn([$syncUser]);
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

	public function testHasLegacyTokenTrueForLegacyName(): void {
		$this->tokenProvider->method('getTokenByUser')->with('alice')->willReturn([
			$this->token(3, 'Firefox on Windows'),
			$this->token(1, Constants::TOKEN_NAME_LEGACY),
		]);
		$this->assertTrue($this->svc->hasLegacyToken('alice'));
	}

	public function testHasLegacyTokenFalseForNewNameOnly(): void {
		$this->tokenProvider->method('getTokenByUser')->with('alice')->willReturn([
			$this->token(2, Constants::TOKEN_NAME),
			$this->token(3, 'Firefox on Windows'),
		]);
		$this->assertFalse($this->svc->hasLegacyToken('alice'));
	}
}
```

- [ ] **Step 2: Run tests to verify they fail**

`php vendor/phpunit/phpunit/phpunit -c phpunit.xml --filter SyncUserServiceTest`
Expected: FAIL/ERROR — `hasLegacyToken` undefined; invalidate test sees only token id 1 revoked (name `sendentsynchroniser` equals the current `$this->appName` match) so `[1] !== [1, 2]`.

- [ ] **Step 3: Implement dual-name matching and hasLegacyToken**

In `lib/Service/SyncUserService.php`, replace lines 74–79:

```php
		    // Invalidates existing app tokens — both generations: tokens minted
		    // before the rework are named TOKEN_NAME_LEGACY, newer ones TOKEN_NAME.
		    // Matching both is security-critical: retracting consent must always
		    // revoke legacy tokens too.
		    $existingTokens = $this->tokenProvider->getTokenByUser($userId);
			foreach($existingTokens as $token) {
				if (in_array($token->getName(), [Constants::TOKEN_NAME, Constants::TOKEN_NAME_LEGACY], true)) {
					$this->tokenProvider->invalidateTokenById($token->getUid(), $token->getId());
				}
			}
```

And add this method after `invalidateUser()`:

```php
	/**
	 * Whether the user still holds an app token minted before the architecture
	 * rework (legacy name). Such a user has not yet completed the new consent
	 * flow: activate() replaces the token with one named Constants::TOKEN_NAME,
	 * so this returning false is the durable "already handled" signal for both
	 * the consent-modal push and the one-time calendar reset offer.
	 */
	public function hasLegacyToken(string $userId): bool {
		foreach ($this->tokenProvider->getTokenByUser($userId) as $token) {
			if ($token->getName() === Constants::TOKEN_NAME_LEGACY) {
				return true;
			}
		}
		return false;
	}
```

- [ ] **Step 4: Rename newly minted tokens**

In `lib/Controller/UserController.php`, in `activate()`, change the `generateToken` call (line 133–141): replace the 5th argument `$this->appName` with `Constants::TOKEN_NAME`:

```php
		$generatedToken = $this->tokenProvider->generateToken(
			$token,
			$credentials->getUID(),
			$credentials->getLoginName(),
			null,
			Constants::TOKEN_NAME,
			IToken::PERMANENT_TOKEN,
			IToken::DO_NOT_REMEMBER
		);
```

(`Constants` is already imported in this file.)

- [ ] **Step 5: Run tests to verify they pass**

`php vendor/phpunit/phpunit/phpunit -c phpunit.xml --filter SyncUserServiceTest`
Expected: PASS, 3 tests. (Standalone clone: `php -l lib/Service/SyncUserService.php && php -l lib/Controller/UserController.php`.)

- [ ] **Step 6: Commit**

```bash
git add lib/Constants.php lib/Service/SyncUserService.php lib/Controller/UserController.php tests/Unit/Service/SyncUserServiceTest.php
git commit -m "feat: rename new app tokens, revoke both generations on invalidate"
```

---

### Task 3: CalendarResetService — detection

**Files:**
- Create: `lib/Service/CalendarResetService.php`
- Test: `tests/Unit/Service/CalendarResetServiceTest.php`

**API contract used (verified against NC stable30 `apps/dav/lib/CalDAV/CalDavBackend.php`):**
- `getCalendarsForUser(string $principalUri): array` — rows include `id`, `uri`, `{DAV:}displayname`, `{http://apple.com/ns/ical/}calendar-color`, `{http://apple.com/ns/ical/}calendar-order`, `{urn:ietf:params:xml:ns:caldav}calendar-timezone`, `{http://nextcloud.com/ns}deleted-at`; soft-deleted (trashbinned) calendars ARE returned (no `deleted_at` filter in the query).
- `getCalendarObjects(int $calendarId): array` — metadata only, **no** `calendardata`.
- `getMultipleCalendarObjects(int $calendarId, array $uris): array` — rows include `calendardata`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Service/CalendarResetServiceTest.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\SendentSynchroniser\Db\SyncUser;
use OCA\SendentSynchroniser\Db\SyncUserMapper;
use OCA\SendentSynchroniser\Service\CalendarResetService;
use OCA\SendentSynchroniser\Service\CollectionService;
use OCA\SendentSynchroniser\Service\SyncUserService;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CalendarResetServiceTest extends TestCase {

	private CalDavBackend&MockObject $calDav;
	private CollectionService&MockObject $collections;
	private SyncUserMapper&MockObject $syncUsers;
	private SyncUserService&MockObject $syncUserService;
	private IConfig&MockObject $config;
	private CalendarResetService $svc;

	protected function setUp(): void {
		$this->calDav = $this->createMock(CalDavBackend::class);
		$this->collections = $this->createMock(CollectionService::class);
		$this->syncUsers = $this->createMock(SyncUserMapper::class);
		$this->syncUserService = $this->createMock(SyncUserService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->svc = new CalendarResetService(
			$this->calDav,
			$this->collections,
			$this->syncUsers,
			$this->syncUserService,
			$this->config,
			new NullLogger()
		);
	}

	private function givenLegacyToken(bool $has): void {
		$this->syncUserService->method('hasLegacyToken')->with('alice')->willReturn($has);
	}

	private function givenTargetCalendar(string $uri = 'personal'): void {
		$syncUser = new SyncUser();
		$syncUser->setUid('alice');
		$syncUser->setCalendar($uri);
		$this->syncUsers->method('findByUid')->with('alice')->willReturn([$syncUser]);
	}

	public function testShouldOfferFalseWithoutLegacyToken(): void {
		$this->givenLegacyToken(false);
		$this->calDav->expects($this->never())->method('getCalendarsForUser');
		$this->assertFalse($this->svc->shouldOffer('alice'));
	}

	public function testShouldOfferFalseWhenCalendarMissing(): void {
		$this->givenLegacyToken(true);
		$this->givenTargetCalendar();
		$this->calDav->method('getCalendarsForUser')->willReturn([]);
		$this->assertFalse($this->svc->shouldOffer('alice'));
	}

	public function testShouldOfferFalseWhenNoSendentMarker(): void {
		$this->givenLegacyToken(true);
		$this->givenTargetCalendar();
		$this->calDav->method('getCalendarsForUser')->willReturn([
			['id' => 7, 'uri' => 'personal', '{DAV:}displayname' => 'Persoonlijk'],
		]);
		$this->calDav->method('getCalendarObjects')->with(7)->willReturn([
			['uri' => 'a.ics'],
		]);
		$this->calDav->method('getMultipleCalendarObjects')->with(7, ['a.ics'])->willReturn([
			['uri' => 'a.ics', 'calendardata' => "BEGIN:VEVENT\r\nUID:a\r\nEND:VEVENT"],
		]);
		$this->assertFalse($this->svc->shouldOffer('alice'));
	}

	public function testShouldOfferTrueWhenMarkerFound(): void {
		$this->givenLegacyToken(true);
		$this->givenTargetCalendar();
		$this->calDav->method('getCalendarsForUser')->willReturn([
			['id' => 7, 'uri' => 'personal', '{DAV:}displayname' => 'Persoonlijk'],
		]);
		$this->calDav->method('getCalendarObjects')->with(7)->willReturn([
			['uri' => 'a.ics'],
		]);
		$this->calDav->method('getMultipleCalendarObjects')->with(7, ['a.ics'])->willReturn([
			['uri' => 'a.ics', 'calendardata' => "BEGIN:VEVENT\r\nUID:a\r\nX-SENDENT-ID:123\r\nEND:VEVENT"],
		]);
		$this->assertTrue($this->svc->shouldOffer('alice'));
	}

	public function testShouldOfferScansTrashbinnedCalendar(): void {
		// A calendar deleted via the web UI sits in the trashbin and still
		// occupies the URI — it must be scanned (and later purged) too.
		$this->givenLegacyToken(true);
		$this->givenTargetCalendar();
		$this->calDav->method('getCalendarsForUser')->willReturn([
			['id' => 7, 'uri' => 'personal', '{http://nextcloud.com/ns}deleted-at' => 1750000000],
		]);
		$this->calDav->method('getCalendarObjects')->with(7)->willReturn([
			['uri' => 'a.ics'],
		]);
		$this->calDav->method('getMultipleCalendarObjects')->with(7, ['a.ics'])->willReturn([
			['uri' => 'a.ics', 'calendardata' => "BEGIN:VEVENT\r\nUID:a\r\nX-SENDENT-ID:123\r\nEND:VEVENT"],
		]);
		$this->assertTrue($this->svc->shouldOffer('alice'));
	}

	public function testShouldOfferPrefersLiveCalendarOverTrashbinnedTwin(): void {
		// Unique index on (principaluri, uri) means live+trashed twins cannot
		// coexist at the same URI, but different URIs can — the live target wins.
		$this->givenLegacyToken(true);
		$this->givenTargetCalendar();
		$this->calDav->method('getCalendarsForUser')->willReturn([
			['id' => 5, 'uri' => 'other', '{http://nextcloud.com/ns}deleted-at' => 1750000000],
			['id' => 7, 'uri' => 'personal', '{DAV:}displayname' => 'Persoonlijk'],
		]);
		$this->calDav->method('getCalendarObjects')->with(7)->willReturn([]);
		$this->assertFalse($this->svc->shouldOffer('alice'));
	}

	public function testTargetCalendarFallsBackToAdminDefault(): void {
		$this->syncUsers->method('findByUid')->with('bob')->willReturn([]);
		$this->collections->method('getDefaultCalendar')->willReturn('personal');
		$this->assertSame('personal', $this->svc->targetCalendarUri('bob'));
	}
}
```

- [ ] **Step 2: Run tests to verify they fail**

`php vendor/phpunit/phpunit/phpunit -c phpunit.xml --filter CalendarResetServiceTest`
Expected: ERROR — class `CalendarResetService` not found. (Standalone clone: `php -l` the test file.)

- [ ] **Step 3: Implement the service (detection half)**

Create `lib/Service/CalendarResetService.php`:

```php
<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\SendentSynchroniser\Db\SyncUserMapper;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * One-time "delete and re-create the synced calendar" offered to users of the
 * legacy sync client. A user qualifies while they still hold a legacy-named
 * app token (i.e. they have not completed the new consent flow yet) AND their
 * sync target calendar contains the X-SENDENT marker the old client wrote
 * into event iCal data. Completing the consent flow swaps the token name,
 * which is what makes the offer one-time — no other state is stored.
 */
class CalendarResetService {

	private const SENDENT_MARKER = 'X-SENDENT';
	private const SCAN_CHUNK_SIZE = 100;

	public function __construct(
		private CalDavBackend $calDav,
		private CollectionService $collectionService,
		private SyncUserMapper $syncUserMapper,
		private SyncUserService $syncUserService,
		private IConfig $config,
		private LoggerInterface $logger,
	) {}

	private function principal(string $userId): string {
		return 'principals/users/' . $userId;
	}

	/**
	 * The calendar the sync client targets for this user: the per-user choice
	 * stored on the SyncUser row, else the admin-configured default.
	 */
	public function targetCalendarUri(string $userId): string {
		$syncUsers = $this->syncUserMapper->findByUid($userId);
		if (!empty($syncUsers) && ($syncUsers[0]->getCalendar() ?? '') !== '') {
			return $syncUsers[0]->getCalendar();
		}
		return $this->collectionService->getDefaultCalendar();
	}

	/**
	 * Whether the one-time reset should be offered: the user must still hold a
	 * legacy-named token (the check runs before activate() invalidates it) and
	 * the target calendar must actually contain legacy data — a prior user
	 * whose calendar holds only Nextcloud-native events must not be offered a
	 * destructive delete.
	 */
	public function shouldOffer(string $userId): bool {
		if (!$this->syncUserService->hasLegacyToken($userId)) {
			return false;
		}

		$cal = $this->findCalendar($userId, $this->targetCalendarUri($userId));
		return $cal !== null && $this->containsSendentData((int)$cal['id']);
	}

	/**
	 * Finds the calendar at the given URI: the live one if it exists, else a
	 * trashbinned one. A trashbinned calendar still occupies the URI —
	 * oc_calendars has a unique index on (principaluri, uri) regardless of
	 * deleted_at — so it must be found and purged before re-creating, or
	 * createCalendar() throws a unique-constraint violation. This happens when
	 * the user previously deleted the calendar via the web UI (web/DAV deletes
	 * always soft-delete into the trashbin).
	 *
	 * @return array|null Full calendar row (all props, possibly with
	 *                    {http://nextcloud.com/ns}deleted-at set) or null
	 */
	private function findCalendar(string $userId, string $uri): ?array {
		$trashed = null;
		foreach ($this->calDav->getCalendarsForUser($this->principal($userId)) as $cal) {
			if ($cal['uri'] !== $uri) {
				continue;
			}
			if (isset($cal['{http://nextcloud.com/ns}deleted-at'])
				&& is_numeric($cal['{http://nextcloud.com/ns}deleted-at'])) {
				$trashed = $cal;
				continue;
			}
			return $cal;
		}
		return $trashed;
	}

	/**
	 * Scans the calendar for the legacy client's X-SENDENT marker.
	 * getCalendarObjects() returns metadata only, so iCal data is fetched in
	 * chunks via getMultipleCalendarObjects(); exits on first match.
	 */
	public function containsSendentData(int $calendarId): bool {
		$uris = array_column($this->calDav->getCalendarObjects($calendarId), 'uri');
		foreach (array_chunk($uris, self::SCAN_CHUNK_SIZE) as $chunk) {
			foreach ($this->calDav->getMultipleCalendarObjects($calendarId, $chunk) as $obj) {
				if (str_contains($obj['calendardata'] ?? '', self::SENDENT_MARKER)) {
					return true;
				}
			}
		}
		return false;
	}
}
```

- [ ] **Step 4: Run tests to verify they pass**

`php vendor/phpunit/phpunit/phpunit -c phpunit.xml --filter CalendarResetServiceTest`
Expected: PASS, 7 tests. (Standalone clone: `php -l lib/Service/CalendarResetService.php`.)

- [ ] **Step 5: Commit**

```bash
git add lib/Service/CalendarResetService.php tests/Unit/Service/CalendarResetServiceTest.php
git commit -m "feat: detect legacy X-SENDENT data for one-time calendar reset"
```

---

### Task 4: CalendarResetService — the reset operation

**Files:**
- Modify: `lib/Service/CalendarResetService.php`
- Test: `tests/Unit/Service/CalendarResetServiceTest.php`

**API contract (verified, NC stable30):** `deleteCalendar($calendarId, bool $forceDeletePermanently = false)` — with `true` it atomically hard-deletes objects, object properties, changes, shares, and scheduling invitations, dispatches `CalendarDeletedEvent`, and never uses the trashbin; the force branch deletes the row whether live or trashbinned. `createCalendar($principalUri, $calendarUri, array $properties)` accepts `components` (string) plus the property-map keys used below, and returns the new calendar id.

- [ ] **Step 1: Add failing tests for reset()**

Append to `tests/Unit/Service/CalendarResetServiceTest.php`:

```php
	public function testResetRecreatesCalendarWithSameProps(): void {
		$this->givenLegacyToken(true);
		$this->givenTargetCalendar();
		$this->calDav->method('getCalendarsForUser')->willReturn([
			[
				'id' => 7,
				'uri' => 'personal',
				'{DAV:}displayname' => 'Persoonlijk',
				'{http://apple.com/ns/ical/}calendar-color' => '#FF00FF',
				'{urn:ietf:params:xml:ns:caldav}calendar-timezone' => 'BEGIN:VCALENDAR...Europe/Amsterdam...',
			],
		]);
		$this->calDav->expects($this->once())->method('deleteCalendar')->with(7, true);
		$this->calDav->expects($this->once())->method('createCalendar')
			->with('principals/users/alice', 'personal', [
				'components' => 'VEVENT',
				'{DAV:}displayname' => 'Persoonlijk',
				'{http://apple.com/ns/ical/}calendar-color' => '#FF00FF',
				'{urn:ietf:params:xml:ns:caldav}calendar-timezone' => 'BEGIN:VCALENDAR...Europe/Amsterdam...',
			])
			->willReturn(99);
		$this->config->expects($this->never())->method('setUserValue');
		$this->assertTrue($this->svc->reset('alice'));
	}

	public function testResetRepointsNcDefaultCalendar(): void {
		$this->givenLegacyToken(true);
		$this->givenTargetCalendar();
		// The NC default-calendar preference points at the old id 7
		$this->config->method('getUserValue')
			->with('alice', 'dav', 'defaultCalendarId', '')
			->willReturn('7');
		$this->calDav->method('getCalendarsForUser')->willReturn([
			['id' => 7, 'uri' => 'personal', '{DAV:}displayname' => 'Personal'],
		]);
		$this->calDav->method('createCalendar')->willReturn(99);
		$this->config->expects($this->once())->method('setUserValue')
			->with('alice', 'dav', 'defaultCalendarId', '99');
		$this->assertTrue($this->svc->reset('alice'));
	}

	public function testResetRefusesWithoutLegacyToken(): void {
		// Once activate() has swapped the token, a replayed reset must no-op.
		$this->givenLegacyToken(false);
		$this->calDav->expects($this->never())->method('deleteCalendar');
		$this->assertFalse($this->svc->reset('alice'));
	}

	public function testResetPurgesTrashbinnedCalendarBeforeRecreating(): void {
		// deleteCalendar($id, true) hard-deletes the row whether live or
		// trashbinned — freeing the URI held by the unique index.
		$this->givenLegacyToken(true);
		$this->givenTargetCalendar();
		$this->calDav->method('getCalendarsForUser')->willReturn([
			[
				'id' => 7,
				'uri' => 'personal',
				'{DAV:}displayname' => 'Persoonlijk',
				'{http://nextcloud.com/ns}deleted-at' => 1750000000,
			],
		]);
		$this->calDav->expects($this->once())->method('deleteCalendar')->with(7, true);
		$this->calDav->expects($this->once())->method('createCalendar')
			->with('principals/users/alice', 'personal', [
				'components' => 'VEVENT',
				'{DAV:}displayname' => 'Persoonlijk',
			])
			->willReturn(99);
		$this->assertTrue($this->svc->reset('alice'));
	}

	public function testResetReturnsFalseWhenCalendarMissing(): void {
		$this->givenLegacyToken(true);
		$this->givenTargetCalendar();
		$this->calDav->method('getCalendarsForUser')->willReturn([]);
		$this->calDav->expects($this->never())->method('deleteCalendar');
		$this->assertFalse($this->svc->reset('alice'));
	}
```

- [ ] **Step 2: Run tests to verify the new ones fail**

`php vendor/phpunit/phpunit/phpunit -c phpunit.xml --filter CalendarResetServiceTest`
Expected: ERROR — call to undefined method `reset()`.

- [ ] **Step 3: Implement reset()**

Add to `lib/Service/CalendarResetService.php`, after `shouldOffer()`:

```php
	/**
	 * Deletes the sync target calendar (hard delete — objects, shares and
	 * scheduling invitations are removed atomically and nothing lands in the
	 * trashbin; a calendar already sitting in the trashbin from an earlier
	 * web-UI delete is purged the same way, freeing the URI it still occupies)
	 * and re-creates it with the same URI, display name, color, sort order and
	 * timezone, preserving the user's locale-specific naming. Re-points the
	 * user's Nextcloud default-calendar preference when it referenced the old
	 * calendar. Guarded by the legacy token: once activate() has swapped the
	 * token name, this is a no-op — that is the once-only guarantee.
	 */
	public function reset(string $userId): bool {
		if (!$this->syncUserService->hasLegacyToken($userId)) {
			return false;
		}

		$uri = $this->targetCalendarUri($userId);
		$cal = $this->findCalendar($userId, $uri);
		if ($cal === null) {
			return false;
		}
		$calId = (int)$cal['id'];

		$props = ['components' => 'VEVENT'];
		foreach ([
			'{DAV:}displayname',
			'{http://apple.com/ns/ical/}calendar-color',
			'{http://apple.com/ns/ical/}calendar-order',
			'{urn:ietf:params:xml:ns:caldav}calendar-timezone',
		] as $prop) {
			if (isset($cal[$prop]) && $cal[$prop] !== null && $cal[$prop] !== '') {
				$props[$prop] = $cal[$prop];
			}
		}

		$wasNcDefault = $this->config->getUserValue($userId, 'dav', 'defaultCalendarId', '') === (string)$calId;

		// Shares are bound to the internal resource id and are destroyed by the
		// hard delete; log them so support can help users re-share afterwards.
		$shares = $this->calDav->getShares($calId);
		if (!empty($shares)) {
			$this->logger->warning('Calendar reset for user "' . $userId . '" removes ' . count($shares) . ' share(s) on calendar "' . $uri . '": ' . json_encode(array_column($shares, 'href')));
		}

		$this->logger->info('Resetting legacy sync calendar "' . $uri . '" (id ' . $calId . ') for user "' . $userId . '"');
		$this->calDav->deleteCalendar($calId, true);
		$newId = $this->calDav->createCalendar($this->principal($userId), $uri, $props);

		if ($wasNcDefault && $newId) {
			$this->config->setUserValue($userId, 'dav', 'defaultCalendarId', (string)$newId);
		}

		return true;
	}
```

- [ ] **Step 4: Run tests to verify they pass**

`php vendor/phpunit/phpunit/phpunit -c phpunit.xml --filter CalendarResetServiceTest`
Expected: PASS, 12 tests. (Standalone clone: `php -l lib/Service/CalendarResetService.php`.)

- [ ] **Step 5: Commit**

```bash
git add lib/Service/CalendarResetService.php tests/Unit/Service/CalendarResetServiceTest.php
git commit -m "feat: one-time delete-and-recreate of the legacy sync calendar"
```

---

### Task 5: Push — shouldShowDialog for active users on legacy tokens

**Files:**
- Modify: `lib/Controller/SettingsController.php` (`shouldShowDialog`, lines 288–303, and its docblock lines 247–263)
- Test: `tests/Unit/Controller/SettingsControllerShouldShowDialogTest.php` (new file)

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Controller/SettingsControllerShouldShowDialogTest.php`:

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

`php vendor/phpunit/phpunit/phpunit -c phpunit.xml --filter SettingsControllerShouldShowDialogTest`
Expected: `testActiveUserOnLegacyTokenIsPushed` FAILS (current code returns false for every ACTIVE user); the other two pass — that asymmetry confirms the test targets the right behavior.

- [ ] **Step 3: Change shouldShowDialog**

In `lib/Controller/SettingsController.php`, replace the status check inside the active-group loop (lines 291–301):

```php
				// User is member of an active group, let's find if he's active
				$syncUsers = $this->syncUserMapper->findByUid($this->userId);
				if (!empty($syncUsers)) {
					if ($syncUsers[0]->getActive() === Constants::USER_STATUS_NOCONSENT) {
						// User retracted consent — never nag them again
						return new JSONResponse(FALSE);
					}
					if ($syncUsers[0]->getActive() === Constants::USER_STATUS_ACTIVE) {
						// Active users are done — unless they still hold an app
						// token minted before the architecture rework (legacy
						// name). Those users must go through the consent flow
						// once more, where the one-time calendar reset is offered.
						return new JSONResponse($this->syncUserService->hasLegacyToken($this->userId));
					}
					return new JSONResponse(TRUE);
				} else {
					// User has never setup sync
					return new JSONResponse(TRUE);
				}
```

Also update the docblock's condition 6 (line 258) to:

```php
	 * 6- The user must be inactive, OR active but still holding a legacy-named
	 *    app token (pre-rework consent — they must re-consent once).
	 *    Users that have retracted their consent are never shown the dialog.
```

- [ ] **Step 4: Run tests to verify they pass**

`php vendor/phpunit/phpunit/phpunit -c phpunit.xml --filter SettingsControllerShouldShowDialogTest`
Expected: PASS, 3 tests. (Standalone clone: `php -l lib/Controller/SettingsController.php`.)

- [ ] **Step 5: Commit**

```bash
git add lib/Controller/SettingsController.php tests/Unit/Controller/SettingsControllerShouldShowDialogTest.php
git commit -m "feat: show consent dialog to active users still on legacy tokens"
```

---

### Task 6: CalendarResetController + routes

**Files:**
- Create: `lib/Controller/CalendarResetController.php`
- Modify: `appinfo/routes.php` (after the `user#invalidateAll` route, line 16)
- Test: `tests/Unit/Controller/CalendarResetControllerTest.php`

- [ ] **Step 1: Write the failing controller tests**

Create `tests/Unit/Controller/CalendarResetControllerTest.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Controller;

use OCA\SendentSynchroniser\Controller\CalendarResetController;
use OCA\SendentSynchroniser\Service\CalendarResetService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CalendarResetControllerTest extends TestCase {

	private CalendarResetService&MockObject $svc;
	private IUserSession&MockObject $userSession;
	private CalendarResetController $controller;

	protected function setUp(): void {
		$this->svc = $this->createMock(CalendarResetService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->controller = new CalendarResetController(
			'sendentsynchroniser',
			$this->createMock(IRequest::class),
			$this->svc,
			$this->userSession
		);
	}

	private function loginAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testStatusReportsApplicability(): void {
		$this->loginAs('alice');
		$this->svc->method('shouldOffer')->with('alice')->willReturn(true);
		$resp = $this->controller->status();
		$this->assertSame(['applicable' => true], $resp->getData());
	}

	public function testStatusUnauthorizedWithoutUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$resp = $this->controller->status();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $resp->getStatus());
	}

	public function testExecuteRunsReset(): void {
		$this->loginAs('alice');
		$this->svc->expects($this->once())->method('reset')->with('alice')->willReturn(true);
		$resp = $this->controller->execute();
		$this->assertSame(['status' => 'OK'], $resp->getData());
	}

	public function testExecuteReportsSkippedWhenGuardRefuses(): void {
		$this->loginAs('alice');
		$this->svc->method('reset')->willReturn(false);
		$resp = $this->controller->execute();
		$this->assertSame(['status' => 'Skipped'], $resp->getData());
	}
}
```

- [ ] **Step 2: Run tests to verify they fail**

`php vendor/phpunit/phpunit/phpunit -c phpunit.xml --filter CalendarResetControllerTest`
Expected: ERROR — class `CalendarResetController` not found.

- [ ] **Step 3: Implement the controller**

Create `lib/Controller/CalendarResetController.php`:

```php
<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Controller;

use OCA\SendentSynchroniser\Service\CalendarResetService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class CalendarResetController extends Controller {

	public function __construct(
		string $AppName,
		IRequest $request,
		private CalendarResetService $calendarResetService,
		private IUserSession $userSession,
	) {
		parent::__construct($AppName, $request);
	}

	private function uid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}

	/**
	 * Whether the one-time calendar reset should be offered to the current user.
	 *
	 * @NoAdminRequired
	 */
	public function status(): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(['applicable' => false], Http::STATUS_UNAUTHORIZED);
		}
		return new JSONResponse(['applicable' => $this->calendarResetService->shouldOffer($uid)]);
	}

	/**
	 * Deletes and re-creates the current user's sync calendar. Refused once the
	 * user no longer holds a legacy-named token (i.e. after activation).
	 *
	 * @NoAdminRequired
	 */
	public function execute(): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(['status' => 'Error'], Http::STATUS_UNAUTHORIZED);
		}
		$done = $this->calendarResetService->reset($uid);
		return new JSONResponse(['status' => $done ? 'OK' : 'Skipped']);
	}
}
```

- [ ] **Step 4: Register the routes**

In `appinfo/routes.php`, directly after the `user#invalidateAll` line (line 16), add:

```php
		['name' => 'calendar_reset#status',  'url' => '/api/1.0/user/calendarReset/status', 'verb' => 'GET'],
		['name' => 'calendar_reset#execute', 'url' => '/api/1.0/user/calendarReset',        'verb' => 'POST'],
```

- [ ] **Step 5: Run tests to verify they pass**

`php vendor/phpunit/phpunit/phpunit -c phpunit.xml --filter CalendarResetControllerTest`
Expected: PASS, 4 tests. (Standalone clone: `php -l lib/Controller/CalendarResetController.php && php -l appinfo/routes.php`.)

- [ ] **Step 6: Commit**

```bash
git add lib/Controller/CalendarResetController.php appinfo/routes.php tests/Unit/Controller/CalendarResetControllerTest.php
git commit -m "feat: calendar-reset status/execute endpoints"
```

---

### Task 7: Consent-flow UI step

**Files:**
- Modify: `src/components/ConsentFlow.vue`

No JS test infrastructure exists in this repo (webpack + eslint only), so this task is verified by `npm run lint:script` and `npm run build` plus the manual checklist at the end.

Behavioral spec: after the user clicks **Give access** (end of `step1`), query `calendarReset/status`. If applicable, show a `reset` step with a primary "Delete and re-create calendar" button and a secondary "Keep my calendar as it is" button. The primary POSTs the reset; the secondary does nothing server-side — completing the subsequent activation swaps the token, which is the decline memory. Either choice then proceeds with the normal activation sequence (extracted into `doActivate()`, unchanged in behavior). If the status call fails, activation proceeds as before — the reset is best-effort, never a blocker.

- [ ] **Step 1: Rewrite ConsentFlow.vue**

Replace the `<template>` block of `src/components/ConsentFlow.vue` with (only change: the secondary button):

```html
<template>
	<div class="consent-flow">
		<template v-if="activeUser && step === 'idle'">
			<p class="consent-flow__message">
				{{ t('sendentsynchroniser', 'You have already succesfully provided your consent for syncing your data using the Nextcloud Exchange Connector.') }}
			</p>
		</template>
		<template v-else-if="!activeUser && step === 'idle'">
			<p class="consent-flow__message">
				{{ t('sendentsynchroniser', 'To ensure the seamless operation of the Nextcloud Exchange Connector, we need your permission to synchronize your Outlook with Nextcloud. This process consists of one or two simple step(s) and should only take a minute of your time.') }}
			</p>
		</template>

		<div class="consent-flow__content">
			<h3 v-if="title">
				{{ title }}
			</h3>
			<p v-if="text">
				{{ text }}
			</p>

			<div v-if="showButton" class="consent-flow__actions">
				<button class="primary" @click="handleClick">
					{{ buttonLabel }}
				</button>
				<button v-if="secondaryLabel" @click="handleSecondary">
					{{ secondaryLabel }}
				</button>
			</div>
		</div>
	</div>
</template>
```

Replace the `<script setup lang="ts">` block with:

```ts
<script setup lang="ts">
import { ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

type Step = 'idle' | 'step1' | 'reset' | 'step2' | 'complete'

const props = defineProps<{
	activeUser: boolean
	isModal?: boolean
}>()

const emit = defineEmits<{
	close: []
	'consent-changed': []
}>()

const step = ref<Step>('idle')
const title = ref('')
const text = ref('')
const buttonLabel = ref('')
const secondaryLabel = ref('')
const showButton = ref(true)

// Set initial state based on activeUser
if (props.activeUser) {
	title.value = t('sendentsynchroniser', 'Give consent')
	text.value = t('sendentsynchroniser', 'You can refresh your consent by clicking the button below.')
	buttonLabel.value = t('sendentsynchroniser', 'Refresh consent')
} else {
	title.value = ''
	text.value = t('sendentsynchroniser', 'Please click the button below to sync your Outlook appointments, contacts, and tasks with Nextcloud.')
	buttonLabel.value = t('sendentsynchroniser', 'Start consent flow')
}

/**
 * Activates sync for the user and advances to the mail step or completion.
 */
async function doActivate() {
	try {
		const url = generateUrl('/apps/sendentsynchroniser/api/1.0/user/activate')
		const response = await axios.get(url)
		if (response.status !== 200) return

		// activate() silently ensures default collections exist (admin-configured)
		// No user selection — collections are managed by the administrator

		if (response.data.shouldAskMailSync) {
			const domain = response.data.emailDomain as string
			const accountsUrl = generateUrl('/apps/mail/api/accounts')
			const accountsResp = await axios.get(accountsUrl)

			if (accountsResp.data.length === 0) {
				step.value = 'step2'
				title.value = t('sendentsynchroniser', 'Step 2: Set up mail')
				text.value = t('sendentsynchroniser', "Your Outlook appointments, contacts, and tasks are now synchronised with Nextcloud. By clicking the button below you'll be redirected to the Mail application to set it up.")
				buttonLabel.value = t('sendentsynchroniser', 'Finish')
			} else {
				const account = accountsResp.data[0]
				if (account.emailAddress.endsWith(domain)) {
					step.value = 'complete'
					title.value = t('sendentsynchroniser', 'Configuration complete')
					text.value = t('sendentsynchroniser', 'Your Outlook appointments, contacts, and tasks are now synchronised with Nextcloud. And, your Exchange mailbox seems properly setup in the Mail application. You may close this window')
					buttonLabel.value = t('sendentsynchroniser', 'Close')
				} else {
					step.value = 'step2'
					title.value = t('sendentsynchroniser', 'Step 2: Set up mail')
					text.value = t('sendentsynchroniser', "Your Outlook appointments, contacts, and tasks are now synchronised with Nextcloud. But, your Exchange mailbox doesn't seem properly setup in the Mail application. Please click the button below to grant permission for accessing your Exchange mailbox.")
					buttonLabel.value = t('sendentsynchroniser', 'Finish')
				}
			}
		} else {
			step.value = 'complete'
			title.value = t('sendentsynchroniser', 'Configuration complete')
			text.value = t('sendentsynchroniser', 'Your account is fully configured for Exchange synchronization. You may close this window')
			buttonLabel.value = t('sendentsynchroniser', 'Close')
			showButton.value = !props.isModal
		}

		emit('consent-changed')
	} catch (err) {
		console.warn('Error during consent flow activation', err)
	}
}

/**
 * Declines the one-time calendar reset and continues with activation. No
 * server call needed: activation swaps the app-token name, which is what
 * prevents the offer from appearing again.
 */
async function handleSecondary() {
	if (step.value !== 'reset') return
	secondaryLabel.value = ''
	await doActivate()
}

/**
 * Advances the consent flow.
 */
async function handleClick() {
	if (step.value === 'idle') {
		step.value = 'step1'
		title.value = t('sendentsynchroniser', 'Step 1: Set up appointments, contacts, and tasks')
		text.value = t('sendentsynchroniser', 'Please click the button below to allow synchronisation of your Outlook appointments, contacts, and tasks with Nextcloud.')
		buttonLabel.value = t('sendentsynchroniser', 'Give access')
		return
	}

	if (step.value === 'step1') {
		// One-time offer for legacy sync users: reset the synced calendar
		// before activation so the new sync starts from a clean calendar.
		try {
			const statusUrl = generateUrl('/apps/sendentsynchroniser/api/1.0/user/calendarReset/status')
			const statusResp = await axios.get(statusUrl)
			if (statusResp.data.applicable) {
				step.value = 'reset'
				title.value = t('sendentsynchroniser', 'One-time calendar clean-up')
				text.value = t('sendentsynchroniser', 'We found appointments from a previous version of the Exchange synchronisation in your calendar. To avoid duplicate appointments, we recommend deleting and re-creating this calendar before continuing. Warning: this permanently removes all events in the calendar — including ones created in Nextcloud — and removes any shares on it. Your Outlook calendar is not affected and will be synchronised into the new calendar afterwards.')
				buttonLabel.value = t('sendentsynchroniser', 'Delete and re-create calendar')
				secondaryLabel.value = t('sendentsynchroniser', 'Keep my calendar as it is')
				return
			}
		} catch (err) {
			console.warn('Calendar reset status check failed, continuing without it', err)
		}
		await doActivate()
		return
	}

	if (step.value === 'reset') {
		try {
			await axios.post(generateUrl('/apps/sendentsynchroniser/api/1.0/user/calendarReset'))
		} catch (err) {
			console.warn('Calendar reset failed', err)
		}
		secondaryLabel.value = ''
		await doActivate()
		return
	}

	if (step.value === 'step2') {
		window.open(generateUrl('/apps/mail'), '_self')
		return
	}

	if (step.value === 'complete') {
		emit('close')
	}
}
</script>
```

Leave the `<style scoped>` block unchanged, but add one rule inside it for button spacing:

```css
.consent-flow__actions button + button {
	margin-inline-start: 8px;
}
```

- [ ] **Step 2: Lint and build**

Run: `npm run lint:script`
Expected: no errors for `src/components/ConsentFlow.vue` (warnings that already existed elsewhere are acceptable).
Run: `npm run build`
Expected: webpack exits 0 and emits bundles into `js/`.

- [ ] **Step 3: Commit**

```bash
git add src/components/ConsentFlow.vue js/
git commit -m "feat: offer one-time calendar reset in consent flow"
```

(If this repo's convention is not to commit built `js/` output, drop `js/` from the add — check `git log --stat` for whether previous commits include it, and match.)

---

### Task 8: Version bump, changelog + final verification

**Files:**
- Modify: `appinfo/info.xml`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Bump the app version**

In `appinfo/info.xml`, change `<version>2.0.4</version>` to `<version>2.1.0</version>`.

- [ ] **Step 2: Add a changelog entry**

At the top of `CHANGELOG.md`, following the existing entry format in that file, add under an "Unreleased" (or 2.1.0) heading:

```markdown
### Added
- One-time calendar clean-up in the consent flow for users of the legacy sync client: when legacy `X-SENDENT` data is detected in the sync calendar, the user is offered (once) to delete and re-create it. The re-created calendar keeps the same URI, display name, colour and timezone.

### Changed
- New app tokens are named `sendent-synchronization`. Users still holding a token with the old name are asked to go through the consent flow once more — this is what surfaces the calendar clean-up offer to existing users; completing the flow replaces the token. Retracting consent revokes tokens of both names. Deploy together with the new connector: until a user re-consents, the connector still syncs with their legacy token.
```

- [ ] **Step 3: Full verification**

Run each and confirm:
- `for f in lib/Constants.php lib/Service/SyncUserService.php lib/Service/CalendarResetService.php lib/Controller/UserController.php lib/Controller/SettingsController.php lib/Controller/CalendarResetController.php appinfo/routes.php; do php -l $f; done` → no syntax errors, plus `xmllint --noout appinfo/info.xml`.
- `npm run lint:script` → clean for changed files.
- `npm run build` → exit 0.
- Inside a NC checkout (or via CI after push): `composer run test` → all unit tests pass, including the 23 new ones.

- [ ] **Step 4: Commit and push (CI is the authoritative test run)**

```bash
git add appinfo/info.xml CHANGELOG.md
git commit -m "chore: bump to 2.1.0 with changelog for legacy calendar reset"
git push
```

Then watch the "PHP Tests" workflow (`gh run watch` or GitHub UI) — it runs the suite against NC stable30–33. Do not declare the feature done until that workflow is green.

- [ ] **Step 5: Manual smoke test (needs a NC dev instance with the app enabled)**

1. **Create a legacy user**: consent as a test user on the *previous* app version (2.0.4) so their app token is named `sendentsynchroniser` (check Personal Settings → Security), and put an event containing a line starting with `X-SENDENT-ID:test` in their `personal` calendar (via CalDAV PUT or the `occ` shell).
2. **Upgrade the app** to this build. On the user's next page load the consent modal must reappear (they are ACTIVE but on a legacy token).
3. Click through: the "One-time calendar clean-up" step must appear after "Give access".
4. Click "Delete and re-create calendar", complete the flow: the personal calendar must be empty afterwards, keep its display name/colour, **not** appear in the Calendar app's trashbin; the Security page must now show a token named `sendent-synchronization` and no legacy-named token; the modal must not reappear on reload.
5. Repeat 1–3 with a second user and click "Keep my calendar as it is", complete the flow: calendar untouched, token swapped, modal and offer never reappear — including via "Refresh consent" on the personal settings page.
6. **Abandon check**: with a third legacy user, open the flow to the clean-up step, then close the modal without choosing. On next page load the modal and the offer must appear again (nothing was recorded — intended).
7. **Retract-consent check (dual-name)**: as the user from step 4, retract consent — the `sendent-synchronization` token must disappear from the Security page. As a still-legacy user, call `POST /api/1.0/user/invalidate` (external-service path) — the legacy-named token must disappear.
8. **New-architecture user check**: a freshly created user consenting for the first time must never see the clean-up step.
9. **iMIP check (research left this genuinely uncertain — do not skip):** create an event with an external attendee (point the instance at a mail catcher such as Mailpit), run the reset, and confirm **no cancellation e-mail** is sent. Backend-level `deleteCalendar()` bypasses Sabre's iMIP plugin, so none is expected — but nextcloud/server#45677 (double cancellations around trashbin operations) is still open, so verify on a live instance.
10. **Scheduling default check:** after the reset, send the user a fresh invitation (iMIP or from another NC user) and confirm it lands in the re-created calendar — the server-side `schedule-default-calendar-URL` setting references the old calendar, and NC's fallback should resolve to the same `personal` URI again.

---

## Post-plan notes for the reviewer (not tasks)

- **Trashbin:** intentionally bypassed via `forceDeletePermanently = true` — simpler and stronger than delete-then-purge, and matches the product requirement ("delete calendar and then delete calendar in trashbin"). Never soft-delete in this flow: a trashbinned calendar still occupies its URI (unique index on `principaluri`+`uri`), so `createCalendar()` at that URI fails until the trashbinned row is purged — `findCalendar()`/`reset()` handle this. On servers without the PR #50034 fix (merged NC 31, backported 29/30) a trashbinned default calendar can also be silently purged by a mere client PROPFIND on `schedule-default-calendar-URL`. Force-delete avoids the entire class of problems.
- **No cancellation mails:** backend-level `deleteCalendar()` does not pass through Sabre's iMIP/iTip plugins, so attendees are not e-mailed. `purgeCalendarInvitations()` is called internally by NC.
- **Connected CalDAV clients** (iOS/DAVx5/Thunderbird) see the collection disappear and a new one appear at the same URI; they re-download from scratch on next sync (RFC 6578 full-resync fallback). DAVx5 verifiably deletes server-absent local copies; iOS/Thunderbird/Outlook behavior on same-URI recreate was not verified by the research — worth one manual pass with an iOS device if those clients are common among customers.
- **DAVx5 dirty-upload nuance:** mobile clients with *pending unsynced local edits* to legacy events will upload those events into the fresh calendar on their next sync. Rare, self-limiting, not preventable server-side.
- **Scan performance watch-item:** nextcloud/server#48405 measured ~90s to select `calendardata` blobs for a ~3000-event calendar on NC 28.0.10. Only legacy-token holders reach the scan, it early-exits on first match, and it stops running once the user completes consent — but until then it re-runs per flow entry. If support reports consent-flow timeouts on very large calendars, replace `containsSendentData()` with a single `IDBConnection` query: `SELECT id FROM oc_calendarobjects WHERE calendarid = ? AND calendardata LIKE '%X-SENDENT%' LIMIT 1`.
- **Dormant users (accepted):** users who never open Nextcloud keep their legacy token — and the connector keeps syncing them — indefinitely. If that tail needs closing later, a small OCC command or cron invalidating remaining legacy-token holders after a grace period can be added without touching this design.
- **Not restored on purpose (YAGNI for v1, revisit if support tickets appear):** calendar shares/public links, per-user reminder overrides, and the calendar's publish state. Share recipients are logged before deletion.
- **Pre-existing bug worth a follow-up ticket (out of scope here):** `CollectionService::ensureDefaultCollections()`/`createCalendar()` filter out trashbinned calendars when checking existence, then call `CalDavBackend::createCalendar()` — which hits the same unique-constraint violation if the user web-UI-deleted their `personal` calendar. Every consent activation is exposed to this today, independent of the reset feature. Consider reusing `CalendarResetService::findCalendar()`-style trashbin awareness there.
- **`OC\Authentication\Token\IProvider` is private API** (`OC\`, not `OCP\`) — the app already depends on it in `UserController` and `SyncUserService`, so this plan adds no new coupling, but it is the piece most likely to need attention on future NC major upgrades.
