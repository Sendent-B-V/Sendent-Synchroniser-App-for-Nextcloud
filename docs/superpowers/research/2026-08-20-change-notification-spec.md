# sendent-sync — Change Notification Architecture (SPEC, Draft v0.1)

> Source spec for the implementation plan in
> `docs/superpowers/plans/2026-08-20-change-notification-architecture.md`.
> Owner: Luc (Sendent B.V.), 2026-08-20.

**Component:** `sendent-sync` (Nextcloud PHP app) + Nextcloud Exchange Connector (.NET)

## 1. Goal

Give the Exchange Connector a scalable, low-latency signal that *something changed* in a Nextcloud calendar or address book, so it can fetch the delta itself (CalDAV/CardDAV `sync-collection`, RFC 6578) — the same "signal, then pull" model as EWS streaming subscriptions and Graph change notifications.

Non-goals: carrying change *contents* in the signal; guaranteeing exactly-once delivery of signals (the authoritative state is always fetched).

### 1.1 Why not the built-in `webhook_listeners` app

`webhook_listeners` serialises the full event object per change — for calendar-object events that includes the entire iCalendar blob — one HTTP POST per event per matching user, dispatched via cron workers. No identifier-only payload option, no batching. Unworkable at 300,000 mailboxes.

### 1.2 Design principles

1. **Signals are hints, never truth.** The Connector always fetches via `sync-collection`. A lost, duplicated, reordered or forged signal can only cost a wasted idempotent fetch.
2. **References, not contents.** A signal carries `(principal, collection type, collection URI, sync-token)` and nothing else.
3. **One signal, many users.** Signals are batched; one message may reference 1..N collections across 1..N users.
4. **Durable ledger underneath every transport.** A cursor-based change ledger lets the Connector catch up after any gap.
5. **Degrade gracefully.** Preferred transport `notify_push`; otherwise the same ledger is polled. Same payload shape either way.

## 2. Architecture overview

Two transports share one ledger and one payload schema:

| | A — notify_push (preferred) | B — change-feed polling (fallback) |
|---|---|---|
| Direction | Connector opens websocket to `/push/ws` | Connector polls HTTPS |
| Latency | Sub-second to a few seconds (batch window) | Poll interval (default 30 s, min 5 s) |
| Delivery | At-most-once hint + ledger catch-up | At-least-once via ledger cursor |
| Requirements | notify_push app + daemon + Redis | Nothing beyond the app |
| Nextcloud egress | No | No |

Optional outbound webhook (Nextcloud → Connector) in §7.3; not baseline.

## 3. Event capture

### 3.1 Events

Registered via `IRegistrationContext::registerEventListener()`:

- CalDAV object: `CalendarObjectCreated/Updated/Deleted/MovedToTrash/RestoredEvent` → principal, calendar URI, sync-token
- `CalendarObjectMovedEvent` → both source and target calendar
- CalDAV collection: `CalendarCreated/Updated/Deleted`, `CalendarShareUpdatedEvent` → flag `collection_changed=true`
- CardDAV object: `CardCreated/Updated/DeletedEvent`
- CardDAV collection: `AddressBookCreated/Updated/Deleted`, `AddressBookShareUpdatedEvent` → `collection_changed=true`

Principal from the collection's `principaluri`, not the acting session user. Extracted fields only: principal, type, uri, sync_token, collection_changed. `calendardata`/`carddata` never read.

### 3.2 Change ledger (DB)

Table `oc_sendent_sync_dirty` — one row per collection, upserted: principal_uri, collection_type, collection_uri, sync_token, cursor (monotonic, Redis `INCR`), collection_changed, updated_at. Unique index on the collection triple; index on cursor. Table bounded by number of collections; no pruning. Redis-less fallback: single-row sequence table with `SELECT … FOR UPDATE`. Cost per DAV write ~1–2 ms, in-request.

### 3.3 Batch window (Redis)

Leading-edge emit + trailing batch. Accumulate refs in a Redis hash (`HSET`, latest wins); first event in window flushes immediately (`SET NX EX`); window-boundary crossers flush under a `flush_lock`. Default window 2 s. `TimedJob` sweeper (60 s) flushes leftovers. Cap `max_refs_per_signal` (default 500): over the cap → `refs: []`, `truncated: true`, Connector reads `/changes`.

## 4. Transport A — notify_push

### 4.1 Mechanism

`$queue->push('notify_custom', ['user' => botUser, 'message' => 'sendent_sync', 'body' => $signal])`. The `user` is the recipient (bot). Wire format `sendent_sync {json}`. Connector authenticates over `wss://<host>/push/ws` with bot username + app password, awaits `"authenticated"`.

### 4.2 Bot account

Dedicated user `sendent-sync` (configurable), created by admin or `occ sendent-sync:setup`. App password generated in the admin settings (IProvider/app-token API), shown once. Same credentials authorise `/changes` and `/config`.

### 4.3 Known notify_push properties absorbed

Fire-and-forget (ledger catch-up on reconnect); every bot connection receives every signal (Connector shards); per-connection debounce possibly applies to Custom (harmless — cursor gap detection); proxies may cut idle sockets (reconnect + catch-up); `max_connection_time` (same reconnect path).

### 4.4 Availability detection

1. notify_push app enabled; 2. `IQueue` resolvable and not `NullQueue`; 3. self-test passed within 24 h; 4. round-trip check from settings page. All four → `transport = notify_push`; else `change_feed`. Admin can pin either.

## 5. Connector side (.NET — separate repo)

Transport client (websocket or poller; always catch-up before trusting live signals; gap heuristic `sig.cursor > last_cursor + len(refs)`); batch splitting + shard filter (`hash(principal) mod replicas`) + dedup on `(instance, principal, type, uri)`; per-user scheduler (per-mailbox concurrency 1, global ceiling 64, priorities, backpressure, circuit breaker); reconcile every collection each ~6 h; delta fetch via `sync-collection` with the Connector's own stored token.

## 6. Signal schema

```json
{ "v": 1, "instance": "f1a2…", "cursor": 1849233, "truncated": false,
  "refs": [ { "p": "principals/users/alice", "t": "caldav", "u": "personal", "s": 9651, "c": false } ] }
```

~90 bytes/ref; 500 refs ≈ 45 KB.

## 7. Transport B — change-feed polling

### 7.1 Endpoints (OCS, bot or admin; others 403)

- `GET /config` → `{transport, ws_url, poll_interval, cursor, batch_window, bot_user, app_version}`
- `GET /changes?since=<cursor>&limit=<n>` → `{v, instance, cursor, refs[], has_more}`, rows with `cursor > since`, limit ≤ 1000
- `POST /ack?cursor=<n>` → 204 (optional; lag display)
- `GET /health` → `{transport, notify_push_ok, last_signal_at, ledger_rows, connector_lag}`

### 7.2 Polling behaviour

Default 30 s (5–300). Also the catch-up path in mode A — no untested fallback.

### 7.3 Optional outbound webhook

POST the same JSON, HMAC-SHA256 over raw body in `X-Sendent-Signature`, timestamp + nonce, via `QueuedJob` with retries. Hint only. Disabled by default.

## 8. Admin settings

Transport radio (notify_push recommended, with live status lines: app enabled / Redis queue / self-test / round-trip, "Run test" button; polling with interval); mode selector Automatic / Force notify_push / Force polling (forcing unhealthy notify_push shows persistent warning). Service account: bot user dropdown + create; Generate app password (shown once); permissions note. Batching: window 0–10 s, max refs, sweeper info. Optional webhook: URL, secret (sensitive), toggle, send test. Diagnostics: ledger rows, cursor, acked cursor + lag, signals last hour (flushes / avg refs / max / truncated). `occ sendent-sync:status|flush|setup`.

## 9. Capacity model

300k mailboxes, 20 writes/day, peak 5× → ~350 events/s sustained peak. Listener ~1.5 ms in-request (~0.5 PHP-FPM worker at peak); ledger bounded (~900k rows); ~0.5 signals/s at 2 s window (mostly truncated at peak → Connector pages ledger). Signal volume decoupled from event volume.

## 10. Security

Signals contain no calendar/contact data. Websocket authenticity via bot app password over TLS. `notify_custom` publishable only by server-side code. Secrets as sensitive IAppConfig, never logged. Phase-2 principal allow-list if URIs considered sensitive.

## 11. Operational notes

AIO: notify_push preinstalled, `/push/ws` routed; external proxies must forward websocket upgrades. Helm/Docker: enable + `notify_push:setup`. Bare-metal without Redis: transport B, DB sequence. notify_push is optional dependency (resolve inside try/catch); API versioned. Monitoring: `/health`, `occ` counters, `notify_push:metrics`.

## 12. Open items

1. Does notify_push debounce `MessageType::Custom`? (read `src/connection.rs`, test two flushes <1 s apart)
2. Slow-client behaviour of the daemon's per-connection channel (bounded? drop or block?)
3. Does `CalendarObjectDeletedEvent` fire reliably on NC 31–34?
4. `principaluri` availability on every event type (esp. share-updated)
5. Portable upsert through `IQueryBuilder`

## 13. Phasing

- Phase 0 — Spike (1 wk): listener + `notify_custom` to bot; throwaway websocket client; answer items 1–2
- Phase 1 — MVP: ledger, batch window, `/config` + `/changes`, Connector transport client with catch-up, admin settings
- Phase 2 — Scale: sharding, scheduler limits, backpressure, diagnostics, `occ`, optional webhook
- Phase 3 — Hardening: allow-list, metrics export, load test at 350 ev/s, docs
