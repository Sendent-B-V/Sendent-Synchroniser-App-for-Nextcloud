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
