# Change notifications

This document explains how the Sendent Synchroniser app tells the Sendent
Exchange Connector that a calendar or address book changed, how to set it
up on Nextcloud AIO, Helm, Docker or bare metal, what every admin setting
does, and how to monitor and troubleshoot it in production.

It assumes you already run the Sendent Synchroniser app and are configuring
it for use with the Exchange Connector. It does not cover the Connector's
own configuration — see `docs/connector-change-feed-contract.md` for the
wire contract the Connector implements against.

## 1. What it is

The Exchange Connector needs to know when a Nextcloud calendar or address
book changed so it can pull the delta and mirror it into Exchange. Rather
than push the changed data itself, the app follows the same "signal, then
pull" model EWS streaming subscriptions and Microsoft Graph change
notifications use: a signal only ever says *something changed here*, never
what changed. The Connector always fetches the authoritative state itself
over CalDAV/CardDAV `sync-collection`. A signal carries a principal, a
collection type, a collection URI and a sync token — never an iCalendar or
vCard body, never a subject line, never a contact's details.

Two transports exist and share one underlying change ledger. When
`notify_push` (Nextcloud's push/websocket app) is installed and healthy,
signals reach the Connector over a websocket within a second or two of the
batch window closing. When it is not available, or is not installed at
all, the Connector reads the same ledger by polling an HTTP endpoint. Both
transports return exactly the same payload shape, and the ledger is what
makes the whole thing durable: whichever transport is in use, a client
that reconnects after any gap — a restart, a dropped websocket, a missed
poll — can always catch up by reading forward from its last known
position. Nothing is ever only in a transport; the ledger is the source of
truth and the transports are just two ways of hearing about it sooner.

A handful of design decisions shape everything that follows, and it is
worth stating them plainly:

**Signals are hints, never truth.** The Connector always fetches by
`sync-collection`, so a signal that is lost, duplicated, reordered, or even
forged, costs nothing worse than one wasted idempotent fetch. Nothing in
this design tries to guarantee exactly-once delivery of signals, because
the Connector was never meant to trust them — only to be told to go look.

**References, not contents.** A signal is a `(principal, collection type,
collection URI, sync token)` tuple and nothing else. Calendar and contact
data is never read to build one, so an admin worried about signal payloads
carrying sensitive information can be reassured on that point without
qualification.

**One signal, many users.** Signals are batched. A single message —
websocket frame or `/changes` page — can reference anywhere from one
collection to several hundred, across as many different users, all
described by the batch window that closed to produce it.

**A durable ledger underneath every transport.** Every reference that gets
batched into a signal was first upserted into a database table, stamped
with a monotonically increasing sequence number. That table is what a
Connector reads from `/notify/changes`, it is what a Connector reads to
catch up after any gap regardless of which transport delivered the
original hint, and it is what makes signals themselves genuinely
disposable — nothing is lost even if every signal in flight is.

**Degrade gracefully.** `notify_push` is the preferred transport because it
is faster, but it depends on the `notify_push` app being installed, its
daemon being reachable, and (in the fully-featured configuration) Redis
being configured as the distributed cache. None of that is guaranteed on
every deployment. When any part of it is missing or unhealthy, the app
falls back automatically to change-feed polling against the same ledger,
with the same payload shape. The Connector does not need separate code
paths for the two transports; from the pull side they look identical.

## 2. Setup on Nextcloud AIO

Nextcloud's All-in-One (AIO) distribution ships `notify_push` preinstalled
and already wired up — its websocket endpoint at `/push/ws` is routed for
you, and there is nothing to enable. On AIO you only need to do two things:
create the bot account the Connector will authenticate as, and tell the
app about it.

Create a dedicated Nextcloud user for the Connector — `sendent-sync` is a
reasonable name, but any username works as long as you tell the app what
you chose. This account only ever receives change signals and reads the
change feed; it needs no group memberships, no quota, and no calendars or
address books of its own. You can create it through Users administration
in the web UI, through `occ user:add`, or let the setup command below
create it for you.

Once the account exists, generate an app password for it — this is the
credential the Connector authenticates with, both for the HTTP endpoints
and for the `notify_push` websocket. Nextcloud's app-password flow is
per-account, so you need to actually be logged in as the bot user to
generate one: log in as the bot once, go to Settings → Security, and
create a new app password under "Devices & sessions". Note it down; it is
shown only once. There is deliberately no "generate app password" button
in the admin settings page — doing that from an admin session for another
user would mean depending on more of Nextcloud's private token API than
this app already uses elsewhere, so the two-step manual flow is the
supported path for now.

With the account and its app password in hand, configure the app from the
command line:

```bash
occ sendentsynchroniser:cn-setup --bot-user=sendent-sync --transport=auto
```

If you would rather have the command create the bot account for you (with
a random, throwaway login password — you still generate the app password
manually afterwards), add `--create`:

```bash
occ sendentsynchroniser:cn-setup --bot-user=sendent-sync --transport=auto --create
```

`--transport=auto` is the recommended setting almost everywhere: the app
prefers `notify_push` when it is healthy and falls back to polling
automatically when it is not, without you having to intervene. You can
also set `--transport=notify_push` or `--transport=polling` to pin a
specific transport if you have a reason to.

If your Nextcloud instance sits behind an external reverse proxy — common
in AIO setups that terminate TLS at a separate load balancer or gateway in
front of the AIO stack — make sure that proxy forwards WebSocket upgrade
requests for the `/push/ws` path (the `Upgrade` and `Connection` headers,
and enough idle timeout to hold the connection open). A proxy that does
not forward the upgrade will make `notify_push` look unreachable even
though AIO's own routing is correct, and the app will (correctly) fall
back to polling — which works, but at poll-interval latency instead of
near-real-time.

## 3. Setup on Helm/Docker/bare-metal

Outside of AIO, `notify_push` is not installed by default. If you want the
faster transport, install the "Client Push" app from the Nextcloud app
store, enable it, and run its own setup command once as an administrator:

```bash
occ notify_push:setup
```

This registers `notify_push`'s daemon and websocket endpoint with your
Nextcloud instance; consult `notify_push`'s own documentation for the
daemon deployment itself (a small Rust binary that needs to be reachable
both from Nextcloud's PHP process and from the outside, over the same
domain or a routed sub-path). Once that is done, the same
`cn-setup --transport=auto` flow described above applies.

Nothing about this app requires `notify_push`, though. If you run without
Redis — or without any distributed cache at all, which is common on
smaller bare-metal or single-node Docker installs using only APCu — the
app transparently falls back to change-feed polling, and the Connector
never needs to know the difference. The one thing to be aware of on this
path: the monotonic sequence counter that stamps every ledger row prefers
a distributed cache (`IMemcache::inc`) for speed, but that only works when
`memcache.distributed` is explicitly configured in `config.php` — an
APCu-only install does not count, because APCu's cache is local to each
PHP-FPM worker and the cron process, so a counter kept there would not
actually be shared. Without an explicit `memcache.distributed` setting the
app uses a portable database sequence table instead (insert-and-read the
autoincrement id), which is slightly slower per event but exactly as
correct, and requires no configuration at all. In practice: if you have
not configured Redis (or another `IMemcache` implementation) as
`memcache.distributed`, you are on the DB sequence path automatically, and
that is a supported, tested configuration — not a degraded one.

## 4. The admin settings page

The change-notification settings live in Settings → Administration →
Sendent Sync, under the Synchronization Management tab, in a "Change
notifications" section. Every field there maps directly onto an app config
value with the following bounds and defaults.

**Transport** is a dropdown with three choices: *Automatic (prefer
notify_push)*, *Force notify_push*, and *Force polling*. Automatic is the
default and the recommended setting for almost every deployment — it uses
`notify_push` when it is healthy and silently falls back to polling when
it is not, re-checking periodically so it can recover on its own once
`notify_push` comes back. Forcing `notify_push` when it is not actually
healthy does not break anything — the Connector still catches up over the
change feed — but the settings page shows a persistent warning in that
state so you notice.

**notify_push status** is not a setting but a live diagnostic panel next
to the transport dropdown, populated by the "Run test" button. It shows
three checks and the resulting effective transport: whether the
`notify_push` app is enabled, whether a real (non-null) message queue is
available — this is what tells you whether Redis is actually configured
and working as the distributed cache, not just installed — and whether the
`notify_push` daemon answered a reachability probe at its
`{base_endpoint}/test/cookie` URL. If all three pass, "Run test" also
fires a fourth check: a publish test. This asks the server to push a ping
message addressed to the bot account through the transport and times how
long the HTTP round trip to publish it takes. It is deliberately *not* an
end-to-end delivery check — the admin's own browser session cannot see a
message addressed to a different (bot) user's websocket connection, so
this only confirms and times the publish side. Genuine end-to-end
confirmation that a message reaches a connected Connector is something
only the Connector itself can verify at its own startup.

**Service account (bot user)** is the username the Connector authenticates
as — the same one you set up in section 2 or 3 above. Changing it here
takes effect immediately for future publishes and for the change-feed
endpoints' authorization check.

**Batching** has three numeric fields. *Batch window* controls how long the
app waits, after the first event of a burst, before it is willing to
publish a signal again for the next burst; it ranges from 0 to 10 seconds
and defaults to 2. A lower value produces lower latency but more, smaller
signals; a higher value batches more aggressively at the cost of latency.
*Max references per signal* caps how many collection references a single
signal payload carries before the app gives up trying to fit them all in
and instead marks the signal `truncated` with an empty reference list,
telling the Connector to read the change feed directly instead; it
defaults to 500. *Poll interval* is only relevant when the Connector is
polling rather than using a websocket — it is the interval, in seconds,
between polls that the app advertises to the Connector through
`/notify/config`; it ranges from 5 to 300 seconds and defaults to 30.

**Diagnostics** shows live numbers: collections currently tracked in the
ledger, the current cursor position, and — once the Connector has called
`/notify/ack` at least once — the cursor it last acknowledged and the lag
between that and the current cursor. A "Refresh" button re-reads these,
and a "Flush now" button force-publishes whatever is above the last
published watermark, ignoring the batch window; it is meant for verifying
the pipeline end to end during setup, not routine operation. Once flush
metrics are being tracked (see the occ and monitoring sections below),
this panel also shows a rollup of the last hour's signal activity: how
many flushes happened, the average number of references per signal, the
largest single signal, and how many were truncated.

## 5. occ commands

Four commands are available under the `sendentsynchroniser:cn-*` prefix.

**`occ sendentsynchroniser:cn-status`** prints the current state of the
whole pipeline as plain `key: value` lines — the same numbers the admin
settings' diagnostics panel shows, plus a few that are only useful from a
terminal or a monitoring script. A representative run looks like this:

```
transport_mode: auto
effective_transport: notify_push
notify_push_app: enabled
notify_push_queue: available
notify_push_daemon: ok
bot_user: sendent-sync
batch_window_s: 2
max_refs_per_signal: 500
ledger_collections: 1240118
cursor: 1849233
flushed_seq: 1849233
ack_cursor: 1849190
connector_lag: 43
last_signal_at: 1755676700
flushes_last_hour: 3412
refs_last_hour: 63463
truncated_last_hour: 2
max_refs_in_one_signal_last_hour: 500
```

Reading it top to bottom: `transport_mode` is what the admin configured
(`auto`, `notify_push`, or `polling`); `effective_transport` is what is
actually in use right now, which only diverges from the mode in `auto`
when `notify_push` is unhealthy. `notify_push_app`, `notify_push_queue`
and `notify_push_daemon` are the same three availability checks the "Run
test" button performs — app enabled, a real message queue resolvable
(not the null queue Nextcloud falls back to without Redis), and the
daemon's own reachability probe. `bot_user` is the configured service
account. `batch_window_s` and `max_refs_per_signal` are the current
batching settings. `ledger_collections` is the number of distinct
collections tracked in the ledger table — bounded by roughly two to three
times your user count, since it is one row per calendar or address book,
not per event, and never grows with change volume. `cursor` is the
server's current sequence position; `flushed_seq` is the watermark up to
which signals have actually been published (normally equal to `cursor`,
since a publish immediately advances it); `ack_cursor` is the highest
cursor value the Connector has told the server it processed via
`/notify/ack`, and `connector_lag` is simply `cursor - ack_cursor` — a
rough, self-reported measure of how far behind the Connector's own
processing is, not a measure of anything being lost. `last_signal_at` is
the timestamp of the most recent published signal. The final four lines
are an hour-bucketed rollup of signal activity, useful for spotting
whether the batch window is behaving as expected under real load or
whether signals are being truncated more often than you would like (which
would suggest raising `max_refs_per_signal`, or that the Connector should
lean more on `/notify/changes` than on parsing full signal payloads).

**`occ sendentsynchroniser:cn-flush`** force-publishes whatever is above
the last published watermark right now, bypassing the batch window
entirely. It prints either the cursor and reference count of what it
published, or a message that there was nothing to flush (which also
covers the case where publishing itself failed — check the Nextcloud log
in that case). Useful when verifying the pipeline after initial setup, or
when demonstrating that a specific change reaches the Connector without
waiting for the batch window or the sweeper cron to catch it.

**`occ sendentsynchroniser:cn-setup`** is the unattended-install command
already covered in sections 2 and 3: `--bot-user`, `--create`, and
`--transport` configure the service account and transport mode. A fourth
flag, `--reseed`, lifts the app's sequence counter above the ledger's
current high-water mark. You need this only in one specific situation:
the app's sequence counter — whether backed by Redis or by the database —
was reset or lost (for example, a Redis instance was flushed or replaced
without persistence, wiping the counter key) in a way that would make it
start handing out sequence numbers lower than ones already published and
seen by a Connector. Ordinary Redis evictions and restarts do not need
this — the app already detects a counter that resets below the ledger's
watermark and repairs itself automatically on the next request (see
Failure modes, below). `--reseed` is the manual equivalent for cases where
you want to force it, or where the underlying database sequence itself
needs lifting rather than the cache counter.

**`occ sendentsynchroniser:cn-loadtest`** drives synthetic change events
through the ledger and batch-window code path at a configurable target
rate, without creating any real DAV objects, and reports the achieved
event rate and per-event write latency (p50/p95/p99/max). It takes
`--events` (target events per second, default 350), `--seconds` (duration,
default 60) and `--users` (distinct synthetic users, default 10000). This
command writes real rows into your ledger table using a reserved
`principals/users/cn-loadtest-` principal prefix so they are easy to
identify and remove afterwards — run it against a staging environment,
never production, and clean up the synthetic rows when you are done:

```sql
DELETE FROM oc_sndntsync_dirty WHERE principal_uri LIKE 'principals/users/cn-loadtest-%';
```

## 6. Monitoring

The app exposes `GET /apps/sendentsynchroniser/api/1.0/notify/health`,
authenticated the same way as every other change-feed endpoint (Basic
auth as the bot user, or an admin session). It returns:

- `transport` — the effective transport in use right now.
- `notify_push_ok` — whether the cached `notify_push` daemon check last
  passed.
- `last_signal_at` — Unix timestamp of the most recent published signal.
- `ledger_rows` — number of collections currently tracked.
- `cursor` — the server's current sequence position.
- `ack_cursor` / `ack_at` — the cursor and timestamp the Connector last
  acknowledged via `/notify/ack`.
- `connector_lag` — `cursor - ack_cursor`, clamped at zero.
- `signals_last_hour` — an object with `flushes`, `refs`, `truncated` and
  `max_refs`, the same hour-bucketed rollup `cn-status` prints.

This is the endpoint to point external monitoring at if you want to alert
on, for example, `connector_lag` growing unboundedly (a sign the Connector
has stopped acknowledging, though not necessarily stopped processing,
since `/notify/ack` is optional and only feeds this display) or
`notify_push_ok` flipping to false persistently.

On the `notify_push` side, its own `occ notify_push:metrics` command
reports the daemon's own view of activity, including a "Messages sent
(custom)" counter — that number tracks all `notify_custom` messages the
daemon has relayed, which includes this app's signals alongside anything
else on the instance using the same mechanism. It is a useful
cross-check that the daemon is actually seeing and forwarding what this
app publishes, independent of anything this app reports about itself.

## 7. Failure modes

**The `notify_push` daemon goes down.** In `auto` transport mode, the app
does not find this out synchronously — the self-test background job
re-checks daemon reachability on its own schedule (every five minutes) and
caches the result, so the DAV write path never blocks on an HTTP probe.
Within that same window, roughly five minutes worst case, `auto` mode
notices the daemon is unreachable and the effective transport flips to
polling automatically. No signals are lost in the meantime — they simply
were not published over the (dead) websocket, and the Connector's own
regular catch-up reads against `/notify/changes` pick them up regardless
of what transport was nominally in use. When the daemon comes back, the
same self-test job notices on its next run and flips the effective
transport back.

**Redis is flushed, restarted, or evicts the sequence counter.** The
app's cursor is meant to be monotonic, and it defends that property
actively: if the distributed cache's counter comes back at a value lower
than the ledger's own high-water mark — which is exactly what happens
after a flush, since `INCR` on a missing key restarts at 1 — the app
detects that on the next request and reseeds the counter above the
ledger's watermark before handing out the next value, retrying under
contention if necessary. In the worst case, where the cache genuinely
cannot be repaired within a bounded number of attempts, it falls back to
lifting the database sequence table instead and continues from there. In
no case does a reader ever see the cursor go backwards or hand out a
sequence number a Connector might have already seen.

**Signals are lost or never delivered.** This is expected to happen
occasionally and is designed around rather than prevented: `notify_push`
delivery is fire-and-forget, per-connection debounce may apply, proxies
cut idle sockets, and none of that is guaranteed reliable. Two mechanisms
bound the damage. First, the Connector is required (see the wire contract)
to re-read a bounded overlap on every `/changes` request and to run
periodic overlap catch-up pages even while otherwise living on the
websocket, which surfaces rows that would otherwise have committed too
late to appear in any signal. Second, independent of transport health
entirely, the Connector is required to reconcile every collection via its
own `sync-collection` on a slow cadence — six hours, spread out — so a
signal that never arrived by any path is still bounded by that reconcile
interval, not unbounded.

**Trailing-latency bound.** The batch window's leading edge is fast — the
first event after a window closes flushes essentially immediately — but
events that lose the race to flush are only guaranteed to be published by
whichever comes first: the next event after the window reopens, or the
sweeper cron job, which runs on every cron invocation and publishes
whatever is still pending. With Nextcloud's recommended system cron
(minute intervals) that bound is commonly around five minutes in a
burst-then-silence traffic pattern; with webcron or AJAX cron it can be
closer to a minute. This is a deliberate trade-off, not a bug: it is
significantly wider than the spec's original sub-two-second target for
the trailing edge, in exchange for needing no separate distributed lock
around the flush path. Since signals are hints and the overlap-read and
reconcile mechanisms above already bound staleness independently, this
wider trailing edge does not compromise correctness — only how quickly a
quiet instance's last few changes get their own dedicated signal, as
opposed to being picked up by the Connector's regular catch-up reads.

**Nextcloud 31 firing some DAV events twice.** Some Nextcloud versions are
known to occasionally dispatch the same underlying DAV event more than
once. This is harmless here: every reference is written into the ledger
as an upsert keyed on the collection's identity (principal, type, URI),
so a duplicate event simply upserts the same row again — at worst
advancing its sequence number and sync token redundantly, never creating
a second row or a spurious duplicate signal. The Connector's own fetch is
idempotent regardless, so a duplicate event costs nothing beyond a
sequence number that gets consumed slightly faster than the raw event
count would suggest.
