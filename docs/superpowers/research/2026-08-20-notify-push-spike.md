# notify_push custom-message verification — findings

Date: 2026-08-20
Method: source verification of nextcloud/notify_push (main branch) — the
plan's Task 1 live spike was not runnable in this environment; both open
items are answerable from source. The live round-trip remains part of the
Task 18 E2E checklist.

## Open item 1 — is MessageType::Custom debounced?

No. `SendQueue::item_mut()` maps `PushMessage::Custom(_, _)` to `None`, and
`push()` returns the message immediately when no queue slot exists — Custom
messages bypass `max_debounce_time` entirely and are sent as they arrive.

**Impact:** none to absorb — the design's tolerance for coalescing is simply
never needed. Leading-edge signal latency is daemon-limited, not debounced.

## Open item 2 — slow-client behaviour of the per-connection channel

Bounded: `broadcast::channel(4)` per connection; tokio broadcast semantics
drop the OLDEST buffered message when a lagging receiver overflows.

**Impact:** a Connector that stalls while >4 frames arrive loses the oldest
frames. Exactly the loss mode the design covers: every frame carries `prev`,
so the first frame after a loss reveals the gap and the Connector pages
/changes. With the 2 s batch window, >4 outstanding frames implies ~10 s of
reader stall — rare, detected, and self-healing.

## Additional contract verifications

- Channel `notify_custom`; daemon struct `{user, message, body}` with
  `#[serde(default)]` body (arbitrary JSON) — matches NotifyPushTransport's
  payload exactly.
- Wire frame is `"<message> <json>"` (space-joined); body-less on versions
  predating custom bodies — contract now covers that frame as a bare hint.
- No daemon-side body size limit; our 45 KB signals are safe.
- `OCA\NotifyPush\Queue\NullQueue implements IQueue` with a noop `push()` —
  confirming NotifyPushAvailability must (and does) reject it.

## Go / no-go on transport A

GO. All four §4.1/§4.3 assumptions verified against source; the two
unknowns resolved in the design's favor (no debounce) or exactly as
budgeted (bounded drop-oldest channel + prev-gap catch-up).

## Live E2E verification (2026-09-01, Docker: nextcloud:31.0.14 + MariaDB 11.4 + Redis 7 + notify_push 1.4.0)

Full loop confirmed against a real stack:

- `occ app:enable` clean; migration created `oc_sndntsync_dirty`/`oc_sndntsync_seq` with exact schema and both indexes.
- CalDAV PUT as alice → ledger row `principals/users/alice / caldav / personal` (plus the auto-created default collections captured as structural events); Redis-INCR sequence numbers live.
- `/changes`, `/config`, `/health`, `/ack` all per contract; non-bot user gets 403.
- Websocket as the bot received, ~3 s after a PUT:
  `sendent_sync {"cursor":7,"prev":5,"refs":[{"p":"principals/users/alice",...}],...}`
- Event DELETE on NC 31 signalled (spec §12 open item 3 ✓) — with the predicted dual OCA+OCP dispatch consuming two seqs, collapsed by the upsert.
- `cn-setup`, `cn-status`, `cn-flush`, the sweeper and self-test cron jobs all exercised.
- **PHPUnit: 183 tests, 378 assertions, OK** — first real execution of the suite.

One real defect found and fixed by this pass: notify_push ≥ 1.x guards `/test/cookie`
with a per-run token (its self-test shares it over Redis), so our probe's bare GET got
HTTP 400 — and Nextcloud's HTTP client additionally throws on 4xx by default. The probe
now passes `http_errors => false` and treats any `< 500` response as liveness
(4xx from the token guard proves a daemon is answering; 5xx is a proxy fronting a dead
backend). `NotifyPushAvailabilityTest` pins all four cases.
