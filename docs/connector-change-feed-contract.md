# Connector ↔ sendent-sync change-feed contract (v1)

## Authentication
Basic auth with the bot user and its app password on every HTTP endpoint.
The same credentials authenticate the notify_push websocket (username frame,
then app-password frame, expect the literal text frame `authenticated`).

## Endpoints
Base: <nextcloud>/index.php/apps/sendentsynchroniser/api/1.0

| Method | Path | Meaning |
|---|---|---|
| GET | /notify/config | Transport negotiation. Call at startup and on reconnect. |
| GET | /notify/changes?since=<cursor>&limit=<n> | One ledger page. limit is clamped to 1000. |
| POST | /notify/ack (body: cursor=<n>) | Optional. Feeds the admin lag display. |
| GET | /notify/health | Monitoring. |
| PUT | /notify/allowlist (JSON body: {"principals": [...]}) | Optional privacy hardening; see below. |

## /notify/config response
{ "transport": "notify_push" | "polling",
  "ws_url": "wss://host/push/ws" | null,
  "message_name": "sendent_sync",
  "poll_interval": 30, "batch_window": 2, "reread_overlap": 100,
  "cursor": 1849233, "bot_user": "sendent-sync",
  "instance": "<instanceid>", "app_version": "2.1.0" }

## Signal payload (websocket body, webhook body)
{ "v": 1, "instance": "...", "prev": 1849200, "cursor": 1849233,
  "truncated": false,
  "refs": [ { "p": "principals/users/alice", "t": "caldav", "u": "personal",
              "s": 9651, "c": false } ] }
The /changes response has the same shape minus "prev", plus "has_more": bool.
Websocket frames are the text `sendent_sync {json}` — split on the first space.

Custom messages are exempt from notify_push's debounce (sent immediately),
and each websocket connection buffers only 4 outbound messages (oldest
dropped when a reader lags) — a dropped frame surfaces as a prev-gap on the
next one.

Field meanings: prev = the feed position BEFORE this signal (the watermark the
flush started from — your gap detector), cursor = the feed position after it,
p = principal URI (owner of the collection), t = caldav|carddav,
u = collection URI, s = latest sync token seen (informational — always sync
with the token YOU last stored), c = collection itself changed
(created/deleted/share updated) since your `since`.

Sequence numbers count EVENTS while refs are deduped per COLLECTION, so
cursor - prev routinely exceeds len(refs). That is normal, not loss.

## Required client behaviour
1. Maintain last_cursor per instance. EVERY /changes request — startup
   catch-up, steady-state polling, and gap recovery alike — uses
   since = max(0, last_cursor - reread_overlap) (reread_overlap from /config).
   The overlap absorbs rows whose DB transaction committed after a
   higher-numbered row was already read; you WILL see duplicate refs — dedup
   on (instance, p, t, u), fetches are idempotent.
2. Websocket gap detection is exact via prev: if a frame's prev > last_cursor,
   frames were missed — page /changes per rule 1 before processing further
   frames. Also page /changes on truncated=true (such frames carry no refs).
   A frame carrying no JSON body at all (very old notify_push versions) is a
   bare hint: page /changes as in rule 1.
3. While on the websocket, additionally run one overlap catch-up page on a
   slow timer (recommended every 5 min): a row that committed late lands
   BELOW the server's watermark and never appears in any later frame — only
   an overlap read surfaces it.
4. Persist last_cursor after each fully processed page/frame.
5. Signals are hints. Reconcile every collection via sync-collection on a
   slow cadence (recommended 6 h, spread) regardless of transport.
6. Sequence numbers are monotonic but NOT gapless or contiguous. Only ever
   compare with `>`. Never infer loss from arithmetic on cursor values;
   prev (rule 2) is the only gap signal.

## Known limitation — shared collections
A change signals the OWNER's principal only. The Connector must expand
owner principal → all mailboxes it syncs that map to that collection
(sharees included) using its own mapping. Sharee-side signals are an open
item; do not assume they exist.

## Allow-list semantics
PUT /notify/allowlist replaces the stored list. It filters /changes only
after the admin enables it (`occ sendentsynchroniser:cn-setup --allowlist=on`).
Enabled + empty list = empty feed (fail closed). Upload before enabling.
