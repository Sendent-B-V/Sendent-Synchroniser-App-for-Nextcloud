# Rooms — Connector ⇄ Sendent Sync API Spec

**Status:** Draft for handoff to NC Exchange Connector team
**Date:** 2026-04-30
**Audience:** Engineers implementing room-mailbox sync inside the NC Exchange Connector (ASP.NET service).
**Companion document:** [`2026-04-30-rooms-integration-design.md`](2026-04-30-rooms-integration-design.md) — full architectural context.

## 1. What this API is for

Sendent Sync is adding a rooms feature: customers will manage meeting rooms inside the Sendent Sync UI, and rooms can optionally be linked to an Exchange room mailbox so bookings stay in sync between Nextcloud and Exchange.

**Sendent Sync does not contain a Microsoft Graph or EWS client.** The NC Exchange Connector is responsible for all Exchange-side work for rooms — exactly as it already does for user mailboxes today. Rooms are a new sync target alongside users.

This document specifies the **complete and only** REST surface the Connector consumes against Sendent Sync for room-mailbox sync. Two endpoints. There is no other API contract for rooms; webhooks, delta queries, tokens, schedules, and subscriptions are all internal to the Connector.

## 2. Conventions

| | |
|---|---|
| **Base URL** | `{nextcloud-host}/index.php/apps/sendentsynchroniser` |
| **Authentication** | `Authorization: Bearer <shared-secret>` |
| **Shared secret source** | The same shared secret already used for user-sync, configured in Sendent Sync via `POST /api/1.0/settings/sharedSecret`. The Connector should read it from the same place it reads the user-sync secret. |
| **Content type** | `application/json`, UTF-8 |
| **Timestamps** | ISO-8601 with explicit `Z` (UTC), second precision |
| **Encoding** | All identifiers (`id`, `mailbox`, principal URIs) are ASCII; treat them as opaque strings |
| **Idempotency** | All endpoints are safe to retry. `GET /sync-targets` is naturally idempotent. `POST /sync-status` is "last write wins" per `(roomId, linkVersion)` — Sendent Sync silently ignores reports older than its current `linkVersion`. |

## 3. `GET /api/1.0/sync/rooms/sync-targets`

**Purpose.** Returns the complete current list of rooms the Connector should be syncing. The Connector calls this on its sync tick to (re)discover targets and detect configuration changes.

### 3.1 Request

```http
GET /index.php/apps/sendentsynchroniser/api/1.0/sync/rooms/sync-targets HTTP/1.1
Host: nc.example.com
Authorization: Bearer <shared-secret>
```

No body, no query parameters.

### 3.2 Response — `200 OK`

```json
{
  "rooms": [
    {
      "id": "boardroom-a",
      "mailbox": "boardroom-a@contoso.com",
      "backingPrincipalUri": "principals/users/_room_boardroom-a",
      "backingCalendarUri": "/remote.php/dav/calendars/_room_boardroom-a/personal/",
      "linkVersion": 3,
      "initialSyncRequested": true,
      "active": true
    },
    {
      "id": "studio-b",
      "mailbox": "studio-b@contoso.com",
      "backingPrincipalUri": "principals/users/_room_studio-b",
      "backingCalendarUri": "/remote.php/dav/calendars/_room_studio-b/personal/",
      "linkVersion": 1,
      "initialSyncRequested": false,
      "active": true
    }
  ]
}
```

### 3.3 Field reference

| Field | Type | Description |
|---|---|---|
| `id` | string | Stable room identifier in Sendent Sync. Use this in the `/sync-status` URL path. **Never changes** for the lifetime of a room. The Connector should key its per-room state by this id. |
| `mailbox` | string | Exchange / M365 calendar address (UPN or SMTP). Sendent Sync does **not** validate it; the Connector verifies the resource exists during initial sync and reports `state: failed` with an error message if not. |
| `backingPrincipalUri` | string | Nextcloud principal URI of the hidden NC user that owns this room's CalDAV calendar. The Connector references this principal when constructing CalDAV requests. |
| `backingCalendarUri` | string | Absolute URL of the room's CalDAV calendar collection. Connector PUTs and DELETEs iCal objects here, authenticating with its admin/service-account credential. |
| `linkVersion` | int | Monotonically increasing version, incremented by Sendent Sync on **any** change that should make the Connector reset its work for this room: mailbox change, `active` toggle, or admin-clicked "Retry initial sync". The Connector keeps a per-room last-seen `linkVersion`; any advance means **reset everything for this room and re-bootstrap**. |
| `initialSyncRequested` | bool | `true` when Sendent Sync wants the Connector to perform a full Exchange→CalDAV pull (rather than a delta). Set on first link, mailbox change, or admin retry. The Connector clears it by POSTing `state: completed` with `initialSyncRequested: false` once the full pull finishes. |
| `active` | bool | If `false`, the Connector should **pause** syncing for this room (admin disabled the link without deleting the room). Keep internal state but stop doing Exchange-side work. If it later returns to `true` (with the same `linkVersion`), resume from where you left off. |

### 3.4 Rooms that disappear from the response

If a room previously listed is **no longer present** in a subsequent response, the link has been removed entirely (admin cleared the mailbox, or deleted the room). The Connector must:

1. Cancel any Exchange-side webhook subscription / stop polling for that mailbox.
2. GC its per-room state (delta tokens, sync indexes, queued jobs).
3. **NOT** delete CalDAV events. Sendent Sync handles its own room-deletion cleanup.

### 3.5 Manual rooms are not listed here

Sendent Sync also supports "manual" rooms (free-tier — local-only, no Exchange linkage). Those rooms exist in Sendent Sync's database but **never appear** in this response. The Connector only sees rooms that have an active mailbox link.

### 3.6 Error responses

| Status | Meaning | Connector action |
|---|---|---|
| `401 Unauthorized` | Bad / missing shared secret | Stop calling, alert admin |
| `403 Forbidden` | Feature is license-disabled tenant-wide | Backoff and retry — Sendent Sync may transition out |
| `5xx` | Sendent Sync transient error | Standard exponential backoff |

## 4. `POST /api/1.0/sync/rooms/{id}/sync-status`

**Purpose.** Connector reports the current sync state of one room. Sendent Sync persists the latest report into its database and surfaces it in the admin UI (room editor → "Exchange sync status" field).

### 4.1 Request

```http
POST /index.php/apps/sendentsynchroniser/api/1.0/sync/rooms/boardroom-a/sync-status HTTP/1.1
Host: nc.example.com
Authorization: Bearer <shared-secret>
Content-Type: application/json

{
  "linkVersion": 3,
  "state": "completed",
  "lastSyncedAt": "2026-04-30T10:15:00Z",
  "error": null,
  "initialSyncRequested": false,
  "stats": {
    "eventsPushed": 5,
    "eventsPulled": 2
  }
}
```

The `{id}` in the path is the same `id` returned by `/sync-targets`.

### 4.2 When to call

POST on **state transitions** and at the end of each sync batch. Not on every individual event push/pull — that would be noise.

| Trigger | `state` to send |
|---|---|
| Connector observed new `linkVersion`, started bootstrap | `pending` (optional — Sendent Sync already set this; only useful as a "yes, I see it" signal) |
| Initial pull or delta pull is in flight | `syncing` |
| Pull completed cleanly | `completed` |
| Pull failed with a non-recoverable error | `failed` (with `error` populated) |
| Sync paused because `active: false` was observed | `idle` |

### 4.3 Field reference

| Field | Type | Required | Description |
|---|---|---|---|
| `linkVersion` | int | yes | The `linkVersion` the Connector observed when it started this work. Used by Sendent Sync to detect stale reports — if the admin bumped `linkVersion` mid-sync, this report is for a now-superseded config and Sendent Sync ignores it (returns 204 anyway, no error). |
| `state` | enum | yes | One of `pending`, `syncing`, `completed`, `failed`, `idle`. See state machine in §5. |
| `lastSyncedAt` | ISO-8601 UTC | required when `state = completed` | The moment the Connector finished this batch. |
| `error` | string \| null | required when `state = failed`, else `null` | Human-readable error message. **Surfaced to the admin verbatim** — do not include access tokens, stack traces, or internal identifiers. |
| `initialSyncRequested` | bool | optional | The Connector's view of whether an initial sync is still pending. Set to `false` on the `completed` POST that finishes the initial pull. If omitted from the body, Sendent Sync leaves the field unchanged. |
| `stats` | object \| null | optional | Last-batch counts. `eventsPushed` = events written CalDAV → Exchange in this batch; `eventsPulled` = events written Exchange → CalDAV in this batch. Sendent Sync stores only the latest values — there is no history. |

### 4.4 Response — `204 No Content` on success

### 4.5 Error responses

| Status | Meaning | Connector action |
|---|---|---|
| `400 Bad Request` | Body malformed, unknown `state`, or required field missing | **Don't retry** — fix the payload |
| `401 Unauthorized` | Bad shared secret | Stop |
| `404 Not Found` | Room id no longer exists (was deleted) | Treat as "stop syncing this room" — drop subscription, GC state, don't retry the report |
| `409 Conflict` | `linkVersion` in body is older than what Sendent Sync currently has | **Don't retry** — re-fetch `/sync-targets` and pick up the new `linkVersion` |
| `5xx` | Transient | Exponential backoff, retry |

## 5. State machine

This is **Sendent Sync's view of one room's sync state**. The Connector advances states by calling `/sync-status`. Each room has its own independent state machine.

```
                  admin links mailbox in Sendent Sync UI
                                 │
                                 ▼
                            ┌─────────┐
                            │ pending │  set by Sendent Sync on save
                            └────┬────┘
              Connector starts   │
              bootstrapping      │
                                 ▼
                            ┌──────────┐    fatal error mid-sync     ┌────────┐
                            │ syncing  │ ──────────────────────────► │ failed │
                            └────┬─────┘                              └───┬────┘
                                 │                                        │
                                 │ batch finished cleanly                 │ admin clicks
                                 ▼                                        │ "Retry"
                            ┌────────────┐  admin sets active=false       │ (linkVersion++,
                            │ completed  │ ──────────────────────┐        │  state=pending)
                            └────┬───────┘                        │        │
                                 │ next delta tick starts         │        │
                                 │                                ▼        │
                                 │                           ┌──────┐      │
                                 │                           │ idle │      │
                                 │                           └──┬───┘      │
                                 │                              │          │
                                 └──────────┐                   │          │
                                            ▼                   │          │
                                         (back to syncing on next batch)
```

## 6. Multi-room operation

This is where things matter most. Sendent Sync deployments will typically have many rooms (1 to several hundred), and the Connector must handle them all reliably.

### 6.1 Discovery is whole-list, not delta

Every `GET /sync-targets` response is the **complete current set** of Exchange-linked rooms. The Connector should treat the response as authoritative and reconcile against its in-memory view:

```
On each /sync-targets response:
    let received = { id → roomConfig }
    let known    = { id → connectorState }

    for id in received:
        if id not in known:
            // NEW: provision per-room state, plan an initial sync
            knownState = newConnectorState(received[id])
        else:
            // EXISTING: did config change?
            if received[id].linkVersion > known[id].linkVersion:
                // RESET: cancel subscription, drop delta token, plan re-bootstrap
                resetConnectorState(known[id])
                known[id].config = received[id]
            else:
                // unchanged
                if received[id].active != known[id].active:
                    pauseOrResume(known[id])

    for id in known:
        if id not in received:
            // GONE: cancel subscription, GC state
            disposeConnectorState(known[id])
```

There is no per-room "delete" notification — disappearance from the list is the signal.

### 6.2 Rooms are independent

Per-room state must be isolated:

- **One subscription per room** (or one polling slot — Connector's choice).
- **One delta token per room.** Resetting room A must not affect room B's delta.
- **One state machine per room.** Room A in `failed` does not block room B's progress.
- **One outstanding `/sync-status` POST in flight per room is enough.** If multiple state transitions happen for one room before the previous POST returns, debounce — only the latest matters.

A failure in one room must never propagate to other rooms. Log it, post `state: failed` for that room, and continue working on the rest.

### 6.3 Parallelism and ordering

The Connector may sync rooms in parallel. Recommended:

- **Bootstrapping (initial sync)**: bounded parallelism (e.g. 5 concurrent initial pulls). Initial syncs can be expensive (potentially hundreds of events per room) and are better queued than fanned out.
- **Delta sync** triggered by webhook fire: process inline or with a small worker pool. One room's delta is cheap.
- **Recurring full reconciliation**: stagger across the polling interval — don't hit Exchange for all rooms in the same second.

Ordering across rooms doesn't matter. Within one room, mutations must be serialized (no two concurrent batches for the same room).

### 6.4 Rate limiting and quotas

Microsoft Graph / EWS throttle **per app**, not per resource — so all rooms share the same quota. The Connector must respect Graph's `Retry-After` headers globally and back off across all rooms when throttled, not per-room.

If the Connector is doing N rooms, **all share one app-only credential**, so all share one set of throttle limits. Plan accordingly.

### 6.5 Single CalDAV credential, N calendars

The Connector authenticates to Nextcloud CalDAV with a **single admin/service-account credential** for all rooms. It addresses each room's calendar via the room's `backingCalendarUri`. The credential is configured on the Connector side (same place it stores its existing service-account credential for user sync). Sendent Sync does not hand out per-room credentials.

### 6.6 Initial sync queue / fairness

When many rooms are linked at the same time (e.g. an admin imports a CSV of 100 rooms with mailboxes), all of them arrive with `initialSyncRequested: true` in the next `/sync-targets` poll. The Connector should:

- **Queue** initial syncs, don't fan out blindly.
- Process the queue with bounded concurrency (e.g. 5 at a time).
- Post `state: syncing` when a room starts work, `state: completed` when it finishes, so the Sendent Sync admin UI shows accurate per-room progress.
- Order within the queue does not need guarantees — first-come-first-served is fine.

### 6.7 Failure isolation example

100 rooms linked. Room #37's mailbox doesn't exist in Exchange. Expected behavior:

1. Connector tries to validate room #37 → Graph returns 404.
2. Connector POSTs for room #37: `state: failed`, `error: "Mailbox not found in Exchange tenant"`, `linkVersion: 1`.
3. Connector continues processing rooms #38–100 normally.
4. Admin sees room #37 in error in the Sendent Sync UI, fixes the mailbox, hits Retry. Sendent Sync increments `linkVersion` to 2, sets `initialSyncRequested: true`.
5. Next Connector poll picks up the new `linkVersion`, retries from scratch.

### 6.8 Connector startup

On startup the Connector has no in-memory state. First `/sync-targets` poll returns all current rooms; the Connector compares against any persisted per-room state it had on disk:

- **Same `linkVersion`** for known rooms: resume — don't re-do initial sync, don't re-create subscriptions if they're still valid.
- **New `linkVersion`** for known rooms: treat as config change, reset and re-bootstrap.
- **New rooms** (not in persisted state): plan an initial sync.
- **Persisted rooms not in the response**: GC.

If the Connector has no persistence (or persistence was lost), every room looks new — plan initial syncs for all of them. This will be expensive but correct; the `initialSyncRequested` flag from Sendent Sync is **not** the only trigger to do a full pull. The Connector decides based on its own state.

### 6.9 What happens during initial sync

While Sendent Sync's `state` for a room is `pending` or `syncing`, **bookings created locally in Nextcloud might be pushed into a calendar that's still being filled from Exchange**. To avoid double-bookings, Sendent Sync's `RoomSchedulingPlugin` declines new bookings on a room whose `state ∈ {pending, syncing}`.

This is invisible to the Connector — but it means the Connector should advance to `state: completed` promptly when the initial pull is done, otherwise admins/users will see a "room temporarily unavailable" message longer than necessary.

## 7. Lifecycle walkthroughs

These show the API in concrete sequences. Each room follows the same pattern independently.

### 7.1 Admin enables Exchange link on a fresh room

1. Admin saves the mailbox in the Sendent Sync room editor.
2. Sendent Sync sets `mailbox`, `linkVersion = 1`, `initialSyncRequested = true`, `state = pending`.
3. Connector's next `/sync-targets` poll returns the new room.
4. Connector validates the mailbox via Graph/EWS, creates a webhook subscription (or schedules polling), POSTs:
   ```json
   { "linkVersion": 1, "state": "syncing", "initialSyncRequested": true }
   ```
5. Connector pulls all events from the Exchange room mailbox, writes them as iCal objects into `backingCalendarUri` via authenticated CalDAV PUTs.
6. Connector POSTs:
   ```json
   { "linkVersion": 1, "state": "completed", "initialSyncRequested": false,
     "lastSyncedAt": "2026-04-30T10:15:00Z",
     "stats": { "eventsPushed": 0, "eventsPulled": 137 } }
   ```

### 7.2 Recurring delta sync

1. Webhook fires (or polling tick triggers).
2. Connector applies the changeset to CalDAV.
3. Connector POSTs:
   ```json
   { "linkVersion": 1, "state": "completed",
     "lastSyncedAt": "2026-04-30T10:25:00Z",
     "stats": { "eventsPushed": 1, "eventsPulled": 3 } }
   ```
   `initialSyncRequested` is omitted (already cleared).

### 7.3 Outbound sync (booking made in Nextcloud → Exchange)

1. A user adds the room to a Nextcloud Calendar event. Sendent Sync's `RoomSchedulingPlugin` accepts it; the iCal lands in the room's CalDAV calendar.
2. Connector picks up the new event via whatever outbound mechanism it uses today for user calendars (CalDAV change feed, polling, or its own bookkeeping — same pattern as users).
3. Connector pushes the event to the Exchange room mailbox.
4. Connector POSTs status:
   ```json
   { "linkVersion": 1, "state": "completed",
     "lastSyncedAt": "...", "stats": { "eventsPushed": 1, "eventsPulled": 0 } }
   ```

### 7.4 Admin changes the mailbox

1. Admin edits the mailbox field, saves.
2. Sendent Sync sets `mailbox = new`, `linkVersion = 2`, `initialSyncRequested = true`, `state = pending`.
3. Connector's next `/sync-targets` shows `linkVersion: 2`.
4. Connector compares against last-seen `1` → linkVersion advanced → reset:
   - Cancel old Exchange subscription
   - Clear room's delta token
   - Optionally clear stale CalDAV events (or let the new full pull overwrite by UID — depends on the Connector's strategy)
5. Treat as fresh initial sync (§7.1) with `linkVersion: 2`.

### 7.5 Admin clicks "Retry initial sync" after a failure

Same as 7.4 — `linkVersion` increments, `initialSyncRequested = true`, `state = pending`.

### 7.6 Admin pauses linkage (without deleting)

1. Admin toggles "active" to false.
2. Sendent Sync sets `active = false` (linkVersion may or may not increment — Connector should react to either signal).
3. Connector sees `active: false` → pauses sync, POSTs `state: idle`.
4. Connector keeps subscriptions and delta state — when admin re-enables, resume cleanly.

### 7.7 Admin disables the link entirely

1. Admin clears the mailbox field, saves.
2. Sendent Sync drops the row from `sendent_room_sync_link`.
3. Connector's next `/sync-targets` no longer includes the room. Cancel subscription, forget per-room state.
4. **No `/sync-status` POST is required.**

### 7.8 Admin deletes the room

1. Sendent Sync deletes the room, the hidden NC user, and the CalDAV calendar.
2. Same as 7.7 — room drops from `/sync-targets`.
3. If the Connector has an in-flight batch and POSTs `/sync-status` after deletion, it gets `404` — drop the report, GC state.

## 8. What's NOT in this contract

The Connector handles all of these privately. None of them are exposed in this API:

- Microsoft Graph / EWS authentication (tenant ID, client ID, secret).
- Webhook subscription creation / renewal / receiving.
- Delta-token storage.
- Throttling, rate limiting, retry policy for Exchange-side calls.
- Mapping Exchange event IDs ↔ CalDAV UIDs (Connector's internal sync index).
- Conflict resolution semantics when both sides changed the same event.
- Per-tenant Exchange config beyond the mailbox address.
- Operational visibility (which mailboxes the Connector is tracking, last delta timestamps, queue depths). If the Connector exposes such an API, that's a Connector-side admin surface — Sendent Sync deliberately doesn't mirror it.

## 9. Implementation checklist

For the Connector team:

- [ ] Reuse the existing shared-secret authentication primitive (same as user-sync targets).
- [ ] Add a periodic poller for `GET /api/1.0/sync/rooms/sync-targets` — recommended interval 1–5 min, can match the user-sync target poll.
- [ ] Reconcile each response against persisted per-room state (§6.1) — handle new, changed (`linkVersion` advance), unchanged, paused (`active: false`), and gone rooms.
- [ ] For each per-room workflow, post to `POST /api/1.0/sync/rooms/{id}/sync-status` on transitions: starting, completed, failed, idle.
- [ ] Keep one subscription / delta token per room; never share state across rooms.
- [ ] Handle Microsoft Graph/EWS throttling globally across rooms (single app credential = single quota).
- [ ] Bound concurrency for initial syncs (§6.6) — 5 concurrent is a reasonable default.
- [ ] Use the room's `backingCalendarUri` and the existing admin/service-account NC credential for all CalDAV writes — no per-room credentials.
- [ ] On `404` from `/sync-status`, drop the room from internal state without retrying.
- [ ] On `409` from `/sync-status`, do not retry — re-fetch `/sync-targets`.
- [ ] Sanitize `error` messages before posting (no tokens, stack traces, or PII).
- [ ] Failure in one room must not block other rooms.

## 10. Versioning

This API lives under `/api/1.0/...`. Backward-compatible additions (new optional fields in responses, new optional fields in POST bodies) will not bump the version. Breaking changes will introduce a parallel `/api/2.0/...` namespace and a transition window where both are served.
