# Suppress iTip & iMIP for Active-Group Members in Graph API Mode

**Status:** Approved
**Date:** 2026-05-25
**Author:** Sendent Sync team

## Problem

When Graph API mode is enabled, Nextcloud's own scheduling subsystem must stay out of the way: Microsoft Graph is the source of truth for invitations on synced accounts. The existing `SchedulingSuppressorPlugin` is meant to enforce this, but it does not actually stop delivery.

Concrete repro: user2 invites user3, both local NC accounts in the Sendent Sync active group, Graph API mode on. The invitation still appears in user3's Nextcloud calendar.

## Root cause

Two defects compound:

1. **Wrong `scheduleStatus` code.** [SchedulingSuppressorPlugin.php:54](../../../lib/Sabre/SchedulingSuppressorPlugin.php#L54) sets `scheduleStatus = '1.1; suppressed...'`. In iCalendar status semantics, `1.x` codes mean "pending — please proceed." Nextcloud's `IMipPlugin` skips only when the status does **not** start with `1`. So `1.1` actively tells iMIP to deliver.
2. **No propagation halt.** Sabre's `CalDAV\Schedule\Plugin::scheduleLocalDelivery` (the listener that writes the invitation into user3's calendar/inbox on the same server) does not consult `scheduleStatus` before delivering. Setting any status code at all is insufficient — the listener must be prevented from running.

The current plugin returns normally, so every downstream `schedule` listener still fires.

A separate issue noticed during analysis (not in scope, recorded for awareness): `shouldSuppress` checks `!$syncUser->getActive()`, which incorrectly treats `USER_STATUS_NOCONSENT` (= 2) as eligible-for-suppression. This becomes moot once we move to group-membership-only.

## Goals

- When Graph API mode is on **and** the authenticated user is a member of any group in `activeGroups`, suppress **both** internal iTip local delivery and outbound iMIP email for that user's scheduling messages.
- Preserve all current behavior when Graph API mode is off, or when the user is not in any active group.
- Keep the suppression mechanism narrow and intentional — no calendar PUT rewriting, no calendar-object mutation.

## Non-goals

- No change to the Connector's CalDAV write path.
- No change to `RoomSchedulingPlugin` (rooms remain on their own path).
- No introduction of new admin settings; reuse existing `graphApiMode` and `activeGroups`.
- No per-calendar carve-outs. If a user is in an active group while Graph API mode is on, all their `schedule` events are suppressed.

## Design

### Eligibility gate

`SchedulingSuppressionService::shouldSuppress(?string $uid, string $requestPath): bool` returns true when all of:

1. App-config `graphApiMode` equals `'true'`.
2. `$uid` is non-null/non-empty.
3. `$uid` is a member of at least one group whose GID is in the JSON-decoded `activeGroups` app-config value.

The `$requestPath` parameter is retained on the signature for source-compatibility with the existing plugin call site, but is no longer consulted. The previous SyncUser lookup and calendar-URI match are removed.

`activeGroups` parsing is defensive: if the app-config value is `''`, `'null'`, or not valid JSON, treat as empty list and return false. Mirror what `SettingsController::shouldShowDialog` already does at [SettingsController.php:287](../../../lib/Controller/SettingsController.php#L287).

Group membership is resolved via `OCP\IGroupManager::isInGroup($uid, $gid)`.

**Implication, by explicit design choice:** users in the active group whose `SyncUser` status is `INACTIVE` or `NOCONSENT` will also have their iTip/iMIP suppressed. The admin is trusted to scope `activeGroups` correctly. Documented here so it is not a surprise.

### Suppression mechanism

`SchedulingSuppressorPlugin::onSchedule(Message $iTipMessage)` changes:

- When `shouldSuppress` returns true:
  - Set `$iTipMessage->scheduleStatus = '2.0;Success - suppressed by Sendent Sync (Graph API mode)'`.
    A `2.x` code signals "delivered/handled" so any code that inspects status post-emit sees a settled state. The current `'1.1; suppressed...'` is replaced.
  - `return false`. Sabre's `\Sabre\Event\EventEmitter::emit()` halts dispatch when any listener returns `false`. Because this plugin already registers at priority 50 (lower number = called earlier in Sabre/Event), both `Sabre\CalDAV\Schedule\Plugin::scheduleLocalDelivery` (default priority 100) and `OCA\DAV\CalDAV\Schedule\IMipPlugin` (default priority 100) are skipped.
- When `shouldSuppress` returns false: return `null` as today, allowing normal propagation.

The plugin's `onSchedule` signature changes from `void` to `?bool` to permit halting.

### Dependency wiring

`SchedulingSuppressionService` constructor adds:
- `OCP\IGroupManager $groupManager`

`SchedulingSuppressionService` constructor removes:
- `SyncUserMapper $syncUserMapper` (no longer used)

DI is resolved automatically by the Nextcloud container; no `Application::register` changes.

### Files touched

- `lib/Service/SchedulingSuppressionService.php` — replace gate logic, swap dependency.
- `lib/Sabre/SchedulingSuppressorPlugin.php` — change status code, return `false`, update signature, refresh class doc-comment.
- `tests/Unit/Service/SchedulingSuppressionServiceTest.php` — rewrite around new gate.
- `tests/Unit/Sabre/SchedulingSuppressorPluginTest.php` — new file.

## Testing

### `SchedulingSuppressionServiceTest` (rewritten)

| # | Scenario | Expected |
|---|----------|----------|
| 1 | Graph API mode disabled | false; `IGroupManager` not called |
| 2 | `$uid` null | false; `IGroupManager` not called |
| 3 | `$uid` empty string | false |
| 4 | `activeGroups` is `''` | false |
| 5 | `activeGroups` is `'null'` | false |
| 6 | `activeGroups` is malformed JSON | false (and no exception) |
| 7 | User is in an active group | true |
| 8 | User is in no active group | false |
| 9 | `activeGroups` has multiple entries; user is in second | true |
| 10 | Path contains leading slash / unusual shape | result unaffected (path is ignored) |

### `SchedulingSuppressorPluginTest` (new)

| # | Scenario | Expected |
|---|----------|----------|
| 1 | Service returns true | `onSchedule` returns `false`; `scheduleStatus` starts with `'2.0'` and contains `'suppressed by Sendent Sync (Graph API mode)'` |
| 2 | Service returns false | `onSchedule` returns `null`; `scheduleStatus` unchanged from input |
| 3 | `userSession->getUser()` returns null | UID passed to service is `null`; behavior follows service |

Mock `IUserSession` and `SchedulingSuppressionService`. Construct a real `Sabre\VObject\ITip\Message`; assert on its mutated state after the call.

### Manual verification

After implementation, with Graph API mode on and user2/user3 both in an active group:
- user2 invites user3 from the NC calendar UI → user3's NC calendar must **not** receive the event.
- Check NC mail spool / logs → no iMIP email sent.
- Toggle Graph API mode off → user3 receives the event normally (regression guard).
- Add a user outside `activeGroups` as user4; invite user4 → user4 receives the event normally.

## Risks and mitigations

- **Risk:** Group lookup on every CalDAV `schedule` is a hot path. **Mitigation:** `IGroupManager::isInGroup` is backed by the NC group cache; the gate short-circuits on the `graphApiMode` check first, so when the feature is off there is zero overhead.
- **Risk:** Returning `false` from a Sabre event handler is a strong action. **Mitigation:** Plugin runs at a tight scope (graph-api-mode AND in-group). Unit test on the plugin pins the return-value contract.
- **Risk:** Pre-consent group members lose iTip with no fallback. **Mitigation:** Documented as explicit design choice. Admin owns `activeGroups`.

## Out of scope (future work)

- The `!$syncUser->getActive()` truthiness bug on the tri-state status is moot once we move to group-only, but other callers (`SettingsController::shouldShowDialog`, `SyncUserService`) compare correctly with `=== USER_STATUS_ACTIVE` and don't need touching here.
- Cascade behavior between `activeGroups` removal and existing `SyncUser` rows: existing code in `SettingsController::setActiveGroups` already invalidates orphaned users. Not changed.
