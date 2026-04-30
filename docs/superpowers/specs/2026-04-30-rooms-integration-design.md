# Rooms Integration Design

**Status:** Draft for review
**Date:** 2026-04-30
**Owner:** Sendent
**Source material:** `RoomVox-main` repository (third-party Nextcloud app for room booking)

## 1. Goal

Bring room-booking functionality (currently provided by the standalone RoomVox app) into the Sendent Sync app, so customers install one Nextcloud app and get both per-user Exchange sync and room booking.

The Microsoft Exchange sync engine for room mailboxes does **not** ship inside Sendent Sync — it lives in the existing Nextcloud Exchange Connector (the ASP.NET service that already powers per-user Exchange sync). Sendent Sync owns the local Nextcloud-side concept of rooms (data model, UI, CalDAV resource exposure, scheduling, permissions); the Connector owns Exchange-side bytes.

The integration includes a **free tier** (manual rooms, no Connector required) and a **licensed tier** (Exchange-linked rooms via the Connector).

## 2. Non-goals

- Replicating the Microsoft Graph / EWS client inside Sendent Sync.
- Adding webhook receivers, delta query handling, or Exchange-side state to Sendent Sync.
- Maintaining a parallel licensing system for rooms — Sendent Sync's existing `LicenseManager` is reused.
- Migrating from an existing RoomVox install. This is a fresh integration; if a RoomVox migration is later required it gets its own design.
- Visual room browser (RoomVox's calendar-app patch). Out of scope unless explicitly added later.

## 3. Architecture

### 3.1 Topology

Sendent Sync runs **inside** Nextcloud as a regular Nextcloud app and talks to Nextcloud's services in-process (PHP DI, Sabre, CalDAV, DB). The NC Exchange Connector is an **external** ASP.NET service that reaches in to Nextcloud over CalDAV and to Sendent Sync over a small REST API.

```
┌──────────────────────────────── Nextcloud server ────────────────────────────────┐
│                                                                                  │
│  ┌─────────────────────────────────┐         ┌─────────────────────────────┐    │
│  │  Sendent Sync app               │         │  Nextcloud core             │    │
│  │                                 │  in-    │                             │    │
│  │  • Rooms DB (manual + linked)   │  proc   │  • CalDAV (Sabre)           │    │
│  │  • RoomBackend → IRoom          │ ◄─────► │  • Calendar resources       │    │
│  │  • RoomSchedulingPlugin         │         │  • Users / app passwords    │    │
│  │  • Hidden UserBackend           │         │  • IAppConfig, DB           │    │
│  │  • Room admin UI (Vue 3)        │         └──────────────▲──────────────┘    │
│  │                                 │                        │                   │
│  │  REST endpoints exposed for     │                        │ CalDAV / OCS      │
│  │  the Connector to consume:      │                        │ (Connector uses   │
│  │   GET  /rooms/sync-targets      │                        │  its admin /      │
│  │   POST /rooms/{id}/sync-status  │                        │  service-account  │
│  │                                 │                        │  credential)      │
│  └──────────▲──────────────────────┘                        │                   │
│             │                                               │                   │
└─────────────┼───────────────────────────────────────────────┼───────────────────┘
              │ REST (Connector calls in)                     │
              │                                               │
       ┌──────┴───────────────────────────────────────────────┴──────────┐
       │  NC Exchange Connector  (separate ASP.NET service / container)  │
       │                                                                 │
       │  • Reads room sync targets from Sendent Sync API                │
       │  • Authenticates to Exchange (app-only OAuth, EWS or Graph)     │
       │  • Authenticates to Nextcloud CalDAV with its admin /           │
       │    service-account credential, per room's principal URI         │
       │  • Bidirectional sync engine (transport choice is private)      │
       │  • Reports status back to Sendent Sync                          │
       └──────────────────────────────┬──────────────────────────────────┘
                                      │
                                      │ EWS or Graph (its choice)
                                      ▼
                                ┌───────────┐
                                │ Exchange  │
                                └───────────┘
```

### 3.2 Boundaries

- **Sendent Sync ↔ Nextcloud (in-process).** Sendent Sync uses Nextcloud's DI to register the room `IBackend`, register Sabre plugins, run migrations, manage users and app passwords, and expose admin UI.
- **Sendent Sync ↔ Connector (REST, Connector is the client).** Two endpoints, described in §6. Authenticated using the same shared-secret/bearer scheme the Connector already uses against Sendent Sync's existing user-sync endpoints.
- **Connector ↔ Nextcloud CalDAV.** Connector authenticates over CalDAV with its admin/service-account credential (see §3.3) and addresses each room's calendar via the principal URI Sendent Sync supplied.
- **Connector ↔ Exchange.** Private to the Connector. Transport (EWS / Graph), webhook subscriptions, delta queries, retries, and throttling are not Sendent Sync's concern.

### 3.3 Backing accounts

Every room — free or linked — has a hidden Nextcloud user account that owns its CalDAV calendar. Hidden accounts give the calendar a stable principal URI separate from human users and keep room calendars out of human users' calendar lists.

| Room type | Backing principal | Provisioned by | Calendar contents |
|---|---|---|---|
| Manual (free) | NC user `_room_<id>` | Sendent Sync | Local Nextcloud bookings only |
| Exchange-linked | NC user `_room_<id>` | Sendent Sync | Mirror of Exchange room mailbox (Connector keeps it in sync) |

A **hidden UserBackend** (lifted from RoomVox) prevents these accounts from appearing in the human-user list. Sendent Sync owns the lifecycle of these accounts uniformly: create on room creation, delete on room deletion.

**Connector authentication:** the Connector authenticates to Nextcloud CalDAV using a **single admin/service-account credential** — not per-room app passwords. The Connector likely already has such a credential for per-user sync; rooms reuse it. With admin rights it can read and write any room's calendar (addressed via the room's principal URI). This avoids the operational overhead of provisioning and rotating one app password per room. Sendent Sync stores no Connector-side credentials; the Connector keeps its own service-account credential on its side.

## 4. Code structure inside Sendent Sync

Namespaces rebrand `OCA\RoomVox\` → `OCA\SendentSynchroniser\`.

### 4.1 Lifted ~verbatim from RoomVox (renames + DI fixups only)

| RoomVox path | Sendent Sync path | Purpose |
|---|---|---|
| `lib/Connector/Room/Room.php` | `lib/Calendar/Resource/Room.php` | `IRoom` impl |
| `lib/Connector/Room/RoomBackend.php` | `lib/Calendar/Resource/RoomBackend.php` | `IBackend` impl |
| `lib/Dav/SchedulingPlugin.php` | `lib/Sabre/RoomSchedulingPlugin.php` | Booking accept/decline + conflict detection |
| `lib/Dav/RoomVisibilityPlugin.php` | `lib/Sabre/RoomVisibilityPlugin.php` | Visibility filtering |
| `lib/Service/PermissionService.php` | `lib/Service/Room/PermissionService.php` | Role-based permissions |
| `lib/Service/RoomGroupService.php` | `lib/Service/Room/RoomGroupService.php` | Room groups |
| `lib/Service/ImportExportService.php` | `lib/Service/Room/ImportExportService.php` | CSV import/export |
| `lib/Service/MailService.php` | `lib/Service/Room/MailService.php` | Email notifications |
| `lib/Service/CalDAVService.php` | `lib/Service/Room/CalDAVService.php` | Calendar provisioning |
| `lib/UserBackend/` | `lib/UserBackend/` | Hidden user backend for room accounts |
| `lib/Controller/RoomApiController.php` | `lib/Controller/RoomApiController.php` | Room CRUD (Exchange-link write path adapted) |
| `lib/Controller/RoomGroupApiController.php` | `lib/Controller/RoomGroupApiController.php` | Room group CRUD |
| `lib/Controller/BookingApiController.php` | `lib/Controller/BookingApiController.php` | Booking management |
| `lib/Controller/PublicApiController.php` | `lib/Controller/PublicApiController.php` | Public REST API |
| `lib/Controller/PersonalApiController.php` | `lib/Controller/PersonalApiController.php` | Per-user views |
| `lib/Controller/ApiTokenController.php` | `lib/Controller/ApiTokenController.php` | Bearer token management |
| `src/views/`, `src/components/`, `src/services/api.js` | merged into Sendent Sync's existing `src/` tree | Vue 3 admin UI |

### 4.2 Rewritten (was `IAppConfig` JSON, now DB-backed)

- `lib/Service/RoomService.php` — wraps new mappers.
- `lib/Db/{Room,RoomMapper,RoomGroup,RoomGroupMapper,RoomPermission,RoomPermissionMapper,RoomSyncLink,RoomSyncLinkMapper}.php` — new entities + mappers.
- `lib/Migration/Version020100Date<ts>.php` — new tables.

#### Schema

```
sendent_rooms              (id pk, name, email, capacity int null,
                            room_number varchar null, floor varchar null,
                            address text null, room_type varchar default 'meeting-room',
                            description text null,
                            backing_principal_uri varchar, backing_calendar_uri varchar,
                            group_id fk null, active bool default true,
                            created_at, updated_at)

sendent_room_facilities    (room_id fk, facility varchar(64))
                           -- many per room, replaces JSON array column

sendent_room_groups        (id pk, name, description text null)

sendent_room_permissions   (id pk, room_id fk null, group_id fk null,
                            role enum('viewer','booker','manager'),
                            principal_type enum('user','group'),
                            principal_id varchar)
                           -- one row per granted principal; either room_id or group_id is set

sendent_room_sync_link     (room_id pk fk, mailbox varchar,
                            link_version int, state varchar,
                            last_synced_at datetime null,
                            last_error text null,
                            initial_sync_requested bool default false,
                            last_events_pushed int default 0,
                            last_events_pulled int default 0)
```

No JSON-blob columns. `facilities` is normalized into a join table; permissions are already per-row.

### 4.3 Dropped — moves entirely to the Connector

These are deleted from the lift, not copied:

- `lib/Service/Exchange/{GraphApiClient,ExchangeSyncService,WebhookService,SyncResult,ExchangeApiException}.php`
- `lib/Controller/{ExchangeApiController,WebhookController}.php`
- `lib/BackgroundJob/{ExchangeSyncJob,InitialExchangeSyncJob,WebhookSyncJob,WebhookRenewalJob}.php`

### 4.4 Replaced by Sendent Sync's existing equivalents

- RoomVox's `LicenseService` / `LicenseController` → reuse `LicenseManager` + `LicenseApiController`.
- RoomVox's `TelemetryService` → dropped entirely. No room telemetry is added.

### 4.5 New (Sendent Sync–side surface for the Connector)

- `lib/Controller/RoomSyncApiController.php` — exposes the two endpoints in §6.

## 5. Sabre plugin coexistence

**Both plugins are essential.** They address different problems and both stay.

| Plugin | Origin | Why it exists | Scope |
|---|---|---|---|
| `SchedulingSuppressorPlugin` | Existing in Sendent Sync | Suppresses Nextcloud's default iTIP send for human users whose mail/calendar is being handled by the Connector — without it, Nextcloud and the Connector both send iMIP invites for the same event (the duplicate-invite issue Sendent Sync already fixes). | Human users that Sendent Sync has activated for Connector sync |
| `RoomSchedulingPlugin` | Lifted from RoomVox | Implements the room-booking semantics: auto-accept non-conflicting bookings, decline conflicts, queue approvals when a room requires manager sign-off. Free-tier rooms need this just as much as linked ones — it's the local Nextcloud booking logic, independent of any Exchange sync. | Room principals registered by `RoomBackend` |

Their scopes are **disjoint**: each plugin's first action is "is this iTIP addressed to one of my principals?" — if no, it returns early without touching the scheduled object. They never act on the same iTIP message.

Both register at high Sabre priority (RoomVox uses 99) so they run before Nextcloud's default scheduling. Order between them is irrelevant — disjoint targets.

**Why we can't collapse them into one plugin:** the suppressor's job is *negative* (do nothing, prevent default behavior) and applies to humans; the room plugin's job is *positive* (decide accept/decline/needs-action) and applies to rooms. Different lifecycles too — the suppressor is gated on whether a user is currently Connector-synced; the room plugin is always active.

## 6. REST contract: Sendent Sync → Connector

Two endpoints. Authentication uses the existing shared-secret/bearer scheme the Connector already uses against Sendent Sync's `/api/1.0/...` namespace.

### 6.1 Discover sync targets

```
GET /index.php/apps/sendentsynchroniser/api/1.0/rooms/sync-targets
→ 200 OK
{
  "rooms": [
    {
      "id": "boardroom-a",
      "mailbox": "boardroom-a@contoso.com",
      "backingPrincipalUri": "principals/users/_room_boardroom-a",
      "backingCalendarUri": "/remote.php/dav/calendars/_room_boardroom-a/personal/",
      "linkVersion": 3,
      "initialSyncRequested": false,
      "active": true
    }
  ]
}
```

- Connector polls at its existing user-sync cadence.
- `linkVersion` increments on any link-config change. Connector compares against last-seen and re-bootstraps when it advances.
- `initialSyncRequested` is `true` after an admin enables linkage or hits "retry initial sync"; Connector clears it via the status endpoint when the full pull completes.
- The Connector authenticates to Nextcloud CalDAV with its own admin/service-account credential (see §3.3). Sendent Sync does not hand over per-room credentials.
- Manual (free-tier) rooms are **not** in this response. The endpoint only lists rooms with an active mailbox link.

### 6.2 Report sync status

```
POST /index.php/apps/sendentsynchroniser/api/1.0/rooms/{id}/sync-status
{
  "linkVersion": 3,
  "state": "pending" | "syncing" | "completed" | "failed" | "idle",
  "lastSyncedAt": "2026-04-30T10:15:00Z",
  "error": null,
  "stats": { "eventsPushed": 5, "eventsPulled": 2 }
}
→ 204 No Content
```

Sendent Sync writes this into `sendent_room_sync_link` (state, last_synced_at, last_error, last_events_pushed, last_events_pulled).

The admin UI shows the current `state` on **page load** and via a manual "Refresh" button. There is no automatic polling — the Sendent Sync admin UI for user sync has no live progress indicator either, and rooms match that pattern.

That is the entire surface between Sendent Sync and the Connector for rooms. No webhook plumbing, no Graph types, no token storage on the Sendent Sync side.

## 7. End-to-end data flow

### 7.1 Booking from Outlook → linked room → reflected in Nextcloud

1. User in Outlook adds `boardroom-a@contoso.com` to a meeting → Exchange room mailbox accepts/declines.
2. Connector's delta query / webhook on that mailbox fires.
3. Connector authenticates to Nextcloud CalDAV with its admin/service-account credential and addresses `_room_boardroom-a`'s calendar via the principal URI Sendent Sync supplied.
4. Connector PUTs the iCal into the room's calendar.
5. Connector POSTs `state: completed` to Sendent Sync.

`RoomSchedulingPlugin` does **not** run — the event arrives via authenticated CalDAV write, not iTIP.

### 7.2 Booking from Nextcloud Calendar → room (works for free OR linked)

1. User saves an event with the room as an attendee/resource.
2. `RoomSchedulingPlugin` runs: conflict-checks the room's calendar, replies `ACCEPTED` / `DECLINED` / `NEEDS-ACTION`.
3. If accepted, the event lands in the room's CalDAV calendar.
4. **Linked room only:** Connector picks up the new event via whatever outbound mechanism it uses today for user calendars (polling / change feed / its own bookkeeping) and pushes it to Exchange. Posts status.
5. **Free room:** stops at step 3.

### 7.3 Admin enables Exchange linkage on a room

1. Admin types mailbox in room editor, saves.
2. License gate (§8) — if not licensed, rejected with HTTP 402.
3. Sendent Sync writes `mailbox`, increments `link_version`, sets `initial_sync_requested = true`, `state = pending`.
4. Next Connector poll picks it up, bootstraps subscription / initial pull, posts status updates.

## 8. Free vs licensed gate

Single check, single location: `RoomService::setSyncLink(roomId, mailbox)`.

```php
if ($mailbox !== null && !$this->licenseManager->hasEntitlement('rooms.sync')) {
    throw new LicenseException('Exchange room sync requires a Sendent Sync license.');
}
```

- All other room operations (create manual room, set permissions, CSV import, public API tokens, scheduling, notifications) skip the license check.
- UI: room editor's mailbox field shows a "Premium" badge + tooltip when entitlement is absent; the input is disabled. No paywall on save.

## 9. Migration & rollout phases

### Phase 1 — free tier (shippable on its own)

- New DB tables + migrations.
- Lift `Room`/`RoomBackend`, hidden `UserBackend`, basic CRUD, room editor UI, room groups, permissions.
- `RoomSchedulingPlugin` (auto-accept + conflict decline).
- No mailbox UI yet. The data model has the field, but the UI hides it.

### Phase 2 — Connector contract (unlocks the licensed path)

- Implement the two REST endpoints in §6.
- Hidden NC user provisioning at link time (no per-room credential handover — Connector reuses its existing admin/service-account credential).
- Mailbox field surfaced in UI (license-gated).
- Connector implementation (parallel work on the .NET side — out of scope for the Sendent Sync repo).

### Phase 3 — parity polish

- CSV import/export.
- Email notifications + per-room SMTP.
- Public REST API + Bearer tokens.
- Approval workflow.

### Out of scope

- RoomVox's calendar-app patch (visual room browser).
- Custom room types beyond what `IRoom` exposes today.
- Migration tooling for existing RoomVox installs.

## 10. Open questions

None blocking. The following are intentionally deferred:

- Whether the Connector polls or subscribes (Sendent Sync's contract supports either; Connector decides).
- Live progress indicator vs page-load only — defaulting to page-load only; revisit if admins request real-time feedback.
- Migration from a deployed RoomVox install — own design when needed.
