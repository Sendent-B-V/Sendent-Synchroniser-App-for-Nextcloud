# Change Notification Architecture — Nextcloud App Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the Sendent Nextcloud Exchange Connector a batched, contents-free "something changed" signal for CalDAV/CardDAV collections, delivered over notify_push where available and over a durable, cursor-paged change feed everywhere else.

**Architecture:** Every DAV write event is reduced to a `CollectionReference` (principal, type, URI, sync-token) and upserted into a bounded ledger table `oc_sndntsync_dirty`, one row per collection, stamped with a monotonic sequence. A leading-edge batch window (one atomic distributed-cache `add()` per event) elects at most one flush per window; the flush reads the ledger rows above the last flushed watermark and publishes them as a single `notify_custom` message addressed to a bot account. The same ledger backs `GET /api/1.0/notify/changes`, which the Connector polls when notify_push is unavailable and always uses to catch up after a gap.

**Tech Stack:** PHP 8.1+, Nextcloud app framework (NC 28–34), `OCP\AppFramework\Db\QBMapper`, `OCP\ICacheFactory`/`IMemcache`, `OCA\DAV\Events\*`, optional `OCA\NotifyPush\Queue\IQueue`, PHPUnit 10+, Vue 3 + `@nextcloud/axios` for admin settings.

---

## Scope

This plan covers **only the Nextcloud PHP app** in this repository (`sendentsynchroniser`). The .NET Connector side of the spec (§5) is a separate plan in the Connector repository. This plan does produce the wire contract the Connector implements against (Task 27), so the two can proceed in parallel.

Phases 0–3 of the spec's §13 are all covered, restricted to their PHP-side scope:

| Phase | Tasks | Outcome |
|---|---|---|
| 0 — Spike | 1 | Open items 1–2 answered, go/no-go on transport A |
| 1 — MVP | 2–18 | Ledger, window, signals, change feed, admin settings — works on AIO (A) and bare-metal (B) |
| 2 — Scale | 19–24 | Truncation, metrics, occ commands, diagnostics, optional webhook, self-test job |
| 3 — Hardening | 25–27 | Principal allow-list, load-test harness, docs + wire contract |

---

## Deviations from the spec (deliberate, reviewed)

These are places where the spec's design does not survive contact with this codebase or with Nextcloud's public API. Each is a considered change, not an omission.

1. **§3.3 batch accumulation moves from a Redis hash to the ledger itself.** The spec accumulates refs in `HSET sendent_sync:batch`. Nextcloud's `OCP\ICache` / `OCP\IMemcache` expose no hash or set operations, and reaching Redis directly means using `\OC\RedisFactory`, a private class. Instead the flush reads `WHERE change_seq > :watermark ORDER BY change_seq LIMIT :max+1` from the ledger, which already has dedup (upsert), already has the OR-ed structural flag, and already survives a cache flush. The distributed cache is then used only for operations `IMemcache` genuinely supports: `inc` (cursor) and `add` with TTL (window election).

2. **The batch window needs no separate flush lock — at the cost of a looser trailing edge.** `IMemcache::add($key, 1, $windowSeconds)` is atomic set-if-absent with TTL. The first event after each window boundary wins it and flushes; every other event in the window loses and does nothing, so leading-edge latency matches the spec (sub-second on a quiet instance). The trailing edge is weaker than the spec's: events that lose the election are only published by the next event after the window expires, or by the cron sweeper — with Nextcloud's recommended 5-minute system cron, a burst-then-silence workload has a worst-case trailing latency of ~5 minutes, not ~2 s. Accepted: signals are hints, the Connector's overlap reads (deviation 5) and reconcile bound the staleness, and the admin doc states the real bound.

3. **`collection_changed` becomes `structural_seq`.** The spec stores a sticky boolean meaning "structural change since last read", which either never resets or is destroyed by a re-read. Storing the sequence number of the last structural change lets `c` be computed per request as `structural_seq > since` — exact, idempotent, and safe to re-read.

4. **The cursor column is named `change_seq`.** `CURSOR` is a reserved word in MySQL 8 and PostgreSQL. The JSON field stays `cursor` in the wire contract.

5. **The Connector re-reads a bounded overlap.** Sequence numbers are allocated before the DB write commits, so a row can become visible after a higher-numbered row. `/config` therefore returns `reread_overlap` (default 100) and the contract tells the Connector to request `since = max(0, last_cursor - reread_overlap)`. A handful of duplicate refs costs one idempotent fetch — design principle #1 — and this avoids a commit-grace delay that would destroy the sub-second leading-edge latency.

6. **API paths follow this app's existing convention, not OCS.** The app already serves `/apps/sendentsynchroniser/api/1.0/...` through `appinfo/routes.php` with `ApiController`. The change feed lives at `/apps/sendentsynchroniser/api/1.0/notify/...`.

7. **`isActive()` does not require the browser round-trip check (spec §4.4 item 4), and the check itself is publish-side only.** Requiring it would mean a fresh install can never reach transport A until an admin opens the settings page. Checks 1–3 (app enabled, real queue, daemon reachable at `{base_endpoint}/test/cookie`) gate `isActive()`. The settings-page test is a **publish test** — it confirms the signal reaches notify_push's queue and measures that latency; it cannot confirm delivery to a websocket, because bot-addressed frames are only visible to a socket authenticated as the bot. End-to-end confirmation is the Connector's own startup check. The UI labels it "Publish test" accordingly.

8. **Sharee collections are not signalled.** Per §3.1 the principal comes from the collection's `principaluri`, so a change to a shared calendar signals the *owner's* collection only. The Connector maps owner principal → all affected mailboxes. Recorded as an open item in Task 27's contract document.

9. **No "Generate app password" button in the settings (spec §8).** Nextcloud's token API (`OC\Authentication\Token\IProvider`, which this app already uses in `UserController::activate()`) is private API, and generating a token *for another user* from an admin session couples us to more of it. Phase 1 instead documents the two-step manual flow (log in as the bot once → Settings → Security → app password). If field feedback demands one-click, it is an additive Phase 2+ follow-up using the same `IProvider` pattern as `UserController`. Bot-user *creation* is covered: `occ sendentsynchroniser:cn-setup --create` (Task 20).

10. **The signal carries `prev`, because the cursor counts events, not refs.** Sequence numbers are allocated per event while a signal's refs are deduped per collection, so the spec §5 gap heuristic (`cursor > last_cursor + len(refs)`) would false-positive on nearly every frame under normal load. Every signal therefore carries `prev` — the watermark before the flush — and the reader detects a gap exactly (`prev > last_cursor`) instead of heuristically.

11. **Renamings and minor mechanics.** Table `sndntsync_dirty` (not the spec's `oc_sendent_sync_dirty`) and commands `occ sendentsynchroniser:cn-*` (not `sendent-sync:*`) follow this app's existing table and command prefixes. The Redis-less sequence uses insert + `lastInsertId` instead of the spec's `SELECT … FOR UPDATE` — same monotonicity guarantee, no row lock held on the DAV write path.

---

## Test environment

Unit tests use `tests/bootstrap.php`, which requires `../../../tests/bootstrap.php` — the Nextcloud **server** test bootstrap. Tests therefore only run with the app checked out at `<nextcloud-server>/apps/sendentsynchroniser`. All test commands in this plan are run from the app directory inside such a checkout:

```bash
php vendor/bin/phpunit -c phpunit.xml --filter <TestClassOrMethod>
```

On the maintainer's Windows box `php` is not on PATH and eslint is not installed; the frontend compile check is:

```powershell
$env:NODE_ENV='production'; node node_modules/webpack/bin/webpack.js --config webpack.prod.js
```

PHP tasks assume the NC dev container.

---

## File Structure

**Created — value objects and data access**

| File | Responsibility |
|---|---|
| `lib/ChangeNotification/CollectionReference.php` | Immutable `(principal, type, uri, syncToken, collectionChanged)` value object; the unit of every signal |
| `lib/Db/DirtyCollection.php` | Entity for one ledger row |
| `lib/Db/DirtyCollectionMapper.php` | Portable upsert, sequence-paged read, high-water mark, row count |
| `lib/Db/SequenceMapper.php` | Distributed-cache-free monotonic sequence (insert + `lastInsertId` + config offset) |
| `lib/Migration/Version000004Date20260820.php` | Creates `sndntsync_dirty` and `sndntsync_seq` |

**Created — services** (namespace `OCA\SendentSynchroniser\Service\ChangeNotification`)

| File | Responsibility |
|---|---|
| `ChangeNotificationConfig.php` | Typed, clamped accessor over `IAppConfig` for every `cn*` key |
| `CursorService.php` | Monotonic sequence: `IMemcache::inc` with DB fallback and reseed |
| `ChangeLedgerService.php` | Dedup a batch of refs, stamp each with a sequence, upsert; page reads back |
| `DavEventReferenceExtractor.php` | Pure array → `CollectionReference`; no Nextcloud classes, fully unit-testable |
| `BatchWindowService.php` | Window election (`add` with TTL) and the flushed watermark |
| `SignalBuilder.php` | Assembles the wire payload (`v`, `instance`, `cursor`, `truncated`, `refs`) |
| `NotifyPushAvailability.php` | The availability checks, the resolved queue, the transport decision |
| `NotifyPushTransport.php` | Publishes one `notify_custom` message; never throws |
| `SignalPublisher.php` | `flushIfDue()` / `flush()` — reads the ledger above the watermark and publishes |
| `SignalMetrics.php` | Hour-bucketed flush/ref/truncation counters (Phase 2) |
| `WebhookSigner.php` | HMAC-SHA256 canonical string + header value (Phase 2) |
| `PrincipalAllowList.php` | Optional allow-list filtering for `/changes` (Phase 3) |
| `ChangeFeedGuard.php` | Bot-or-admin authorisation for the API |

**Created — listener, jobs, controller, commands, frontend**

| File | Responsibility |
|---|---|
| `lib/Listener/DavChangeListener.php` | `instanceof` dispatch over every DAV event → refs → ledger → maybe flush |
| `lib/Cron/FlushSignalBatch.php` | `TimedJob` sweeper: flushes what a stopped traffic stream left behind |
| `lib/Cron/NotifyPushSelfTest.php` | `TimedJob`: refreshes the cached daemon reachability result (Phase 2) |
| `lib/BackgroundJob/SendWebhookSignal.php` | `QueuedJob` with retry for the optional webhook (Phase 2) |
| `lib/Controller/ChangeFeedApiController.php` | `/notify/config`, `/notify/changes`, `/notify/ack`, `/notify/health` |
| `lib/Controller/ChangeNotificationSettingsController.php` | Admin writes: mode, bot user, window, limits, app password, webhook, ping result |
| `lib/Command/ChangeNotificationStatus.php` | `occ sendentsynchroniser:cn-status` (Phase 2) |
| `lib/Command/ChangeNotificationFlush.php` | `occ sendentsynchroniser:cn-flush` (Phase 2) |
| `lib/Command/ChangeNotificationSetup.php` | `occ sendentsynchroniser:cn-setup` (Phase 2) |
| `lib/Command/ChangeNotificationLoadTest.php` | `occ sendentsynchroniser:cn-loadtest` (Phase 3) |
| `src/components/ChangeNotificationsSection.vue` | Admin UI: transport, service account, batching, webhook, diagnostics |
| `docs/change-notifications.md` | Admin-facing operations doc (Phase 3) |
| `docs/connector-change-feed-contract.md` | Wire contract for the .NET team (Phase 3) |

**Modified**

| File | Change |
|---|---|
| `lib/Constants.php` | `CN_*` config keys, defaults, bounds, collection type constants |
| `lib/AppInfo/Application.php` | Register `DavChangeListener` for every DAV event |
| `lib/Settings/Admin.php` | Provide change-notification initial state |
| `appinfo/routes.php` | New `notify/*` and `changeNotification/*` routes |
| `appinfo/info.xml` | Register the two `TimedJob`s and four `occ` commands |
| `src/components/AdminSettings.vue` | Hosts `ChangeNotificationsSection` in the existing Synchronization Management tab |
| `src/settings.ts` | Pass the new initial-state values as props |

---

## Phase 0 — Spike

### Task 1: Answer notify_push open items 1 and 2

Throwaway code. Its only product is a research note; the command file is deleted in Task 18.

**Files:**
- Create (throwaway): `lib/Command/CnSpike.php`
- Modify: `appinfo/info.xml` (add the command, removed again in Task 18)
- Create: `docs/superpowers/research/2026-08-20-notify-push-spike.md`

- [ ] **Step 1: Write the throwaway spike command**

Create `lib/Command/CnSpike.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Command;

use OCP\IServerContainer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * THROWAWAY. Deleted in Task 18 of the change-notification plan.
 * Pushes N notify_custom messages to one user with a configurable gap so the
 * receiving websocket client can show whether Custom messages are debounced.
 */
class CnSpike extends Command {

	public function __construct(private IServerContainer $container) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:cn-spike')
			->setDescription('THROWAWAY notify_push custom-message spike')
			->addOption('user', null, InputOption::VALUE_REQUIRED, 'Recipient uid')
			->addOption('count', null, InputOption::VALUE_REQUIRED, 'How many messages', '2')
			->addOption('gap-ms', null, InputOption::VALUE_REQUIRED, 'Gap between messages in ms', '200');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$user = (string)$input->getOption('user');
		if ($user === '') {
			$output->writeln('<error>--user is required</error>');
			return 1;
		}

		try {
			$queue = $this->container->get('OCA\\NotifyPush\\Queue\\IQueue');
		} catch (\Throwable $e) {
			$output->writeln('<error>IQueue unavailable: ' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln('queue class: ' . get_class($queue));

		$count = max(1, (int)$input->getOption('count'));
		$gapUs = max(0, (int)$input->getOption('gap-ms')) * 1000;

		for ($i = 1; $i <= $count; $i++) {
			$queue->push('notify_custom', [
				'user' => $user,
				'message' => 'sendent_sync',
				'body' => ['v' => 1, 'seq' => $i, 'sent_at_ms' => (int)(microtime(true) * 1000)],
			]);
			$output->writeln('pushed #' . $i);
			if ($i < $count && $gapUs > 0) {
				usleep($gapUs);
			}
		}

		return 0;
	}
}
```

- [ ] **Step 2: Register the command temporarily**

In `appinfo/info.xml`, inside the existing `<commands>` element, add:

```xml
		<command>OCA\SendentSynchroniser\Command\CnSpike</command>
```

- [ ] **Step 3: Create a bot user and an app password on the spike instance**

```bash
OC_PASS='SpikeBotPassword123!' php occ user:add --password-from-env sendent-sync-bot
php occ user:setting sendent-sync-bot core lang en
```

Generate an app password through the web UI (Settings → Security → Create new app password) as `sendent-sync-bot`, and record it.

- [ ] **Step 4: Run a throwaway websocket client**

In a scratch directory outside the repo:

```bash
npm init -y && npm install ws
cat > listen.js <<'JS'
const WebSocket = require('ws')
const [,, url, user, pass] = process.argv
const ws = new WebSocket(url)
ws.on('open', () => { ws.send(user); ws.send(pass) })
ws.on('message', (d) => {
  console.log(new Date().toISOString(), String(d))
})
ws.on('close', (c, r) => console.log('closed', c, String(r)))
JS
node listen.js "wss://<host>/push/ws" sendent-sync-bot "<app-password>"
```

Expect `authenticated` first.

- [ ] **Step 5: Observe debounce behaviour (open item 1)**

```bash
php occ sendentsynchroniser:cn-spike --user=sendent-sync-bot --count=5 --gap-ms=200
```

Record how many `sendent_sync {...}` frames arrive and with what `seq` values. All five arriving means `MessageType::Custom` is exempt from `max_debounce_time`; fewer means it is debounced and the design's cursor-gap heuristic carries the load.

- [ ] **Step 6: Observe slow-client behaviour (open item 2)**

Add `ws.pause()` after `authenticated` in `listen.js` (or run it under `SIGSTOP`), then:

```bash
php occ sendentsynchroniser:cn-spike --user=sendent-sync-bot --count=2000 --gap-ms=0
```

Resume the client and record whether it receives all 2000, a truncated set, or the connection is closed. Also read `notify_push`'s `src/connection.rs` to confirm whether the per-connection channel is bounded and whether it drops or blocks.

- [ ] **Step 7: Write the research note**

Create `docs/superpowers/research/2026-08-20-notify-push-spike.md`:

```markdown
# notify_push custom-message spike — findings

Date: 2026-08-20
notify_push version: <fill in from `occ app:list`>
Nextcloud version: <fill in>

## Open item 1 — is MessageType::Custom debounced?

Command: `occ sendentsynchroniser:cn-spike --user=sendent-sync-bot --count=5 --gap-ms=200`

Frames received: <n of 5>
seq values seen: <list>
Source reading (`src/connection.rs`): <quote the relevant branch>

**Conclusion:** <debounced / exempt>
**Impact on the design:** <none expected — SignalPublisher stamps every signal
with the current cursor and the Connector pages /changes on a gap>

## Open item 2 — slow-client behaviour

Command: `occ sendentsynchroniser:cn-spike --user=sendent-sync-bot --count=2000 --gap-ms=0`
with the client paused.

Frames received after resume: <n>
Connection closed by daemon: <yes/no, close code>
Channel type in source: <bounded/unbounded>, on full: <drop/block>

**Conclusion:** <...>
**Impact on the design:** <...>

## Go / no-go on transport A

<go / no-go, with reasoning>
```

- [ ] **Step 8: Commit the research note (not the spike command)**

```bash
git add docs/superpowers/research/2026-08-20-notify-push-spike.md
git commit -m "docs: record notify_push custom-message spike findings"
```

---

## Phase 1 — MVP

### Task 2: Constants and the typed config accessor

**Files:**
- Modify: `lib/Constants.php`
- Create: `lib/Service/ChangeNotification/ChangeNotificationConfig.php`
- Test: `tests/Unit/Service/ChangeNotification/ChangeNotificationConfigTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Service/ChangeNotification/ChangeNotificationConfigTest.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ChangeNotificationConfigTest extends TestCase {

	/** @var IAppConfig&MockObject */
	private $appConfig;

	private ChangeNotificationConfig $config;

	/** @var array<string, string> */
	private array $values = [];

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getAppValue')->willReturnCallback(
			fn (string $key, $default = '') => $this->values[$key] ?? $default
		);
		$this->appConfig->method('setAppValue')->willReturnCallback(
			function (string $key, string $value): void {
				$this->values[$key] = $value;
			}
		);
		$this->config = new ChangeNotificationConfig($this->appConfig);
	}

	public function testDefaults(): void {
		$this->assertSame('auto', $this->config->transportMode());
		$this->assertSame('', $this->config->botUser());
		$this->assertSame(2, $this->config->batchWindow());
		$this->assertSame(500, $this->config->maxRefsPerSignal());
		$this->assertSame(30, $this->config->pollInterval());
		$this->assertSame(100, $this->config->rereadOverlap());
		$this->assertSame(0, $this->config->flushedSeq());
	}

	public function testBatchWindowIsClamped(): void {
		$this->values['cnBatchWindow'] = '-4';
		$this->assertSame(0, $this->config->batchWindow());

		$this->values['cnBatchWindow'] = '99';
		$this->assertSame(10, $this->config->batchWindow());
	}

	public function testPollIntervalIsClamped(): void {
		$this->values['cnPollInterval'] = '1';
		$this->assertSame(5, $this->config->pollInterval());

		$this->values['cnPollInterval'] = '9999';
		$this->assertSame(300, $this->config->pollInterval());
	}

	public function testMaxRefsIsClamped(): void {
		$this->values['cnMaxRefsPerSignal'] = '0';
		$this->assertSame(1, $this->config->maxRefsPerSignal());

		$this->values['cnMaxRefsPerSignal'] = '100000';
		$this->assertSame(5000, $this->config->maxRefsPerSignal());
	}

	public function testTransportModeFallsBackToAutoOnGarbage(): void {
		$this->values['cnTransportMode'] = 'wat';
		$this->assertSame('auto', $this->config->transportMode());

		$this->values['cnTransportMode'] = 'polling';
		$this->assertSame('polling', $this->config->transportMode());
	}

	public function testFlushedSeqRoundTrips(): void {
		$this->config->setFlushedSeq(1234);
		$this->assertSame(1234, $this->config->flushedSeq());
	}

	public function testFlushedSeqNeverGoesBackwards(): void {
		$this->config->setFlushedSeq(1234);
		$this->config->setFlushedSeq(12);
		$this->assertSame(1234, $this->config->flushedSeq());
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter ChangeNotificationConfigTest`
Expected: FAIL — `Class "OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig" does not exist`

- [ ] **Step 3: Add the constants**

Append inside the `Constants` class body in `lib/Constants.php`, after the `SENDENT_PROPERTY_PREFIX` line:

```php

	// ─── Change notifications ────────────────────────────────────────────
	// Wire-format version of the signal payload. Bump only on a breaking change.
	public const CN_SIGNAL_VERSION = 1;

	// notify_push message name; the Connector filters incoming frames on it.
	public const CN_MESSAGE_NAME = 'sendent_sync';
	public const CN_PING_MESSAGE_NAME = 'sendent_sync_ping';
	public const CN_NOTIFY_PUSH_APPID = 'notify_push';

	public const COLLECTION_TYPE_CALDAV = 'caldav';
	public const COLLECTION_TYPE_CARDDAV = 'carddav';

	public const TRANSPORT_NOTIFY_PUSH = 'notify_push';
	public const TRANSPORT_POLLING = 'polling';

	// 'auto' prefers notify_push and falls back; the other two pin a transport.
	public const CN_TRANSPORT_MODE_KEY = 'cnTransportMode';
	public const CN_TRANSPORT_MODE_DEFAULT = 'auto';
	public const CN_TRANSPORT_MODES = ['auto', self::TRANSPORT_NOTIFY_PUSH, self::TRANSPORT_POLLING];

	public const CN_BOT_USER_KEY = 'cnBotUser';

	public const CN_BATCH_WINDOW_KEY = 'cnBatchWindow';
	public const CN_BATCH_WINDOW_DEFAULT = 2;
	public const CN_BATCH_WINDOW_MIN = 0;
	public const CN_BATCH_WINDOW_MAX = 10;

	public const CN_MAX_REFS_KEY = 'cnMaxRefsPerSignal';
	public const CN_MAX_REFS_DEFAULT = 500;
	public const CN_MAX_REFS_MIN = 1;
	public const CN_MAX_REFS_MAX = 5000;

	public const CN_POLL_INTERVAL_KEY = 'cnPollInterval';
	public const CN_POLL_INTERVAL_DEFAULT = 30;
	public const CN_POLL_INTERVAL_MIN = 5;
	public const CN_POLL_INTERVAL_MAX = 300;

	// How far back the Connector re-reads /changes to absorb late-committing rows.
	public const CN_REREAD_OVERLAP_KEY = 'cnRereadOverlap';
	public const CN_REREAD_OVERLAP_DEFAULT = 100;

	// Hard cap on ?limit= for /changes.
	public const CN_CHANGES_LIMIT_MAX = 1000;
	public const CN_CHANGES_LIMIT_DEFAULT = 500;

	// Watermark of the last sequence number a live signal carried.
	public const CN_FLUSHED_SEQ_KEY = 'cnFlushedSeq';

	// Offset added to sndntsync_seq ids when there is no distributed cache.
	public const CN_SEQ_OFFSET_KEY = 'cnSeqOffset';

	// Cached daemon reachability probe: {"ok":bool,"at":int,"message":string}
	public const CN_DAEMON_CHECK_KEY = 'cnDaemonCheck';
	public const CN_DAEMON_CHECK_TTL = 300;

	// Browser round-trip result: {"ok":bool,"at":int,"ms":int}
	public const CN_ROUND_TRIP_KEY = 'cnRoundTrip';

	public const CN_ACK_CURSOR_KEY = 'cnAckCursor';
	public const CN_ACK_AT_KEY = 'cnAckAt';
	public const CN_LAST_SIGNAL_AT_KEY = 'cnLastSignalAt';

	public const CN_WEBHOOK_ENABLED_KEY = 'cnWebhookEnabled';
	public const CN_WEBHOOK_URL_KEY = 'cnWebhookUrl';
	public const CN_WEBHOOK_SECRET_KEY = 'cnWebhookSecret';

	public const CN_ALLOWLIST_ENABLED_KEY = 'cnAllowListEnabled';
	public const CN_ALLOWLIST_KEY = 'cnAllowList';
```

- [ ] **Step 4: Write the config accessor**

Create `lib/Service/ChangeNotification/ChangeNotificationConfig.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\Constants;
use OCP\AppFramework\Services\IAppConfig;

/**
 * Typed, clamped read/write access to every change-notification app-config key.
 *
 * Everything that reads configuration goes through here so bounds live in one
 * place: an admin (or an occ command) writing a nonsense value can never widen
 * a window, uncap a signal or shrink a poll interval past what the design
 * tolerates.
 */
class ChangeNotificationConfig {

	public function __construct(
		private IAppConfig $appConfig,
	) {}

	public function transportMode(): string {
		$mode = (string)$this->appConfig->getAppValue(
			Constants::CN_TRANSPORT_MODE_KEY,
			Constants::CN_TRANSPORT_MODE_DEFAULT
		);
		return in_array($mode, Constants::CN_TRANSPORT_MODES, true)
			? $mode
			: Constants::CN_TRANSPORT_MODE_DEFAULT;
	}

	public function setTransportMode(string $mode): void {
		if (!in_array($mode, Constants::CN_TRANSPORT_MODES, true)) {
			throw new \InvalidArgumentException('Unknown transport mode: ' . $mode);
		}
		$this->appConfig->setAppValue(Constants::CN_TRANSPORT_MODE_KEY, $mode);
	}

	public function botUser(): string {
		return (string)$this->appConfig->getAppValue(Constants::CN_BOT_USER_KEY, '');
	}

	public function setBotUser(string $uid): void {
		$this->appConfig->setAppValue(Constants::CN_BOT_USER_KEY, $uid);
	}

	public function batchWindow(): int {
		return $this->clamped(
			Constants::CN_BATCH_WINDOW_KEY,
			Constants::CN_BATCH_WINDOW_DEFAULT,
			Constants::CN_BATCH_WINDOW_MIN,
			Constants::CN_BATCH_WINDOW_MAX
		);
	}

	public function setBatchWindow(int $seconds): void {
		$this->appConfig->setAppValue(Constants::CN_BATCH_WINDOW_KEY, (string)$seconds);
	}

	public function maxRefsPerSignal(): int {
		return $this->clamped(
			Constants::CN_MAX_REFS_KEY,
			Constants::CN_MAX_REFS_DEFAULT,
			Constants::CN_MAX_REFS_MIN,
			Constants::CN_MAX_REFS_MAX
		);
	}

	public function setMaxRefsPerSignal(int $max): void {
		$this->appConfig->setAppValue(Constants::CN_MAX_REFS_KEY, (string)$max);
	}

	public function pollInterval(): int {
		return $this->clamped(
			Constants::CN_POLL_INTERVAL_KEY,
			Constants::CN_POLL_INTERVAL_DEFAULT,
			Constants::CN_POLL_INTERVAL_MIN,
			Constants::CN_POLL_INTERVAL_MAX
		);
	}

	public function setPollInterval(int $seconds): void {
		$this->appConfig->setAppValue(Constants::CN_POLL_INTERVAL_KEY, (string)$seconds);
	}

	public function rereadOverlap(): int {
		return $this->clamped(Constants::CN_REREAD_OVERLAP_KEY, Constants::CN_REREAD_OVERLAP_DEFAULT, 0, 100000);
	}

	public function flushedSeq(): int {
		return max(0, (int)$this->appConfig->getAppValue(Constants::CN_FLUSHED_SEQ_KEY, '0'));
	}

	/**
	 * Monotonic by construction: a stale flusher that read an old watermark can
	 * never drag it backwards and cause the same refs to be published twice.
	 */
	public function setFlushedSeq(int $seq): void {
		if ($seq <= $this->flushedSeq()) {
			return;
		}
		$this->appConfig->setAppValue(Constants::CN_FLUSHED_SEQ_KEY, (string)$seq);
	}

	public function seqOffset(): int {
		return max(0, (int)$this->appConfig->getAppValue(Constants::CN_SEQ_OFFSET_KEY, '0'));
	}

	public function setSeqOffset(int $offset): void {
		$this->appConfig->setAppValue(Constants::CN_SEQ_OFFSET_KEY, (string)max(0, $offset));
	}

	public function ackCursor(): int {
		return max(0, (int)$this->appConfig->getAppValue(Constants::CN_ACK_CURSOR_KEY, '0'));
	}

	public function ackAt(): int {
		return max(0, (int)$this->appConfig->getAppValue(Constants::CN_ACK_AT_KEY, '0'));
	}

	public function setAck(int $cursor, int $at): void {
		$this->appConfig->setAppValue(Constants::CN_ACK_CURSOR_KEY, (string)$cursor);
		$this->appConfig->setAppValue(Constants::CN_ACK_AT_KEY, (string)$at);
	}

	public function lastSignalAt(): int {
		return max(0, (int)$this->appConfig->getAppValue(Constants::CN_LAST_SIGNAL_AT_KEY, '0'));
	}

	public function setLastSignalAt(int $at): void {
		$this->appConfig->setAppValue(Constants::CN_LAST_SIGNAL_AT_KEY, (string)$at);
	}

	/** @return array{ok: bool, at: int, message: string} */
	public function daemonCheck(): array {
		$raw = json_decode((string)$this->appConfig->getAppValue(Constants::CN_DAEMON_CHECK_KEY, ''), true);
		if (!is_array($raw)) {
			return ['ok' => false, 'at' => 0, 'message' => 'not checked yet'];
		}
		return [
			'ok' => (bool)($raw['ok'] ?? false),
			'at' => (int)($raw['at'] ?? 0),
			'message' => (string)($raw['message'] ?? ''),
		];
	}

	public function setDaemonCheck(bool $ok, int $at, string $message): void {
		$this->appConfig->setAppValue(
			Constants::CN_DAEMON_CHECK_KEY,
			json_encode(['ok' => $ok, 'at' => $at, 'message' => $message], JSON_THROW_ON_ERROR)
		);
	}

	/** @return array{ok: bool, at: int, ms: int} */
	public function roundTrip(): array {
		$raw = json_decode((string)$this->appConfig->getAppValue(Constants::CN_ROUND_TRIP_KEY, ''), true);
		if (!is_array($raw)) {
			return ['ok' => false, 'at' => 0, 'ms' => 0];
		}
		return [
			'ok' => (bool)($raw['ok'] ?? false),
			'at' => (int)($raw['at'] ?? 0),
			'ms' => (int)($raw['ms'] ?? 0),
		];
	}

	public function setRoundTrip(bool $ok, int $at, int $ms): void {
		$this->appConfig->setAppValue(
			Constants::CN_ROUND_TRIP_KEY,
			json_encode(['ok' => $ok, 'at' => $at, 'ms' => $ms], JSON_THROW_ON_ERROR)
		);
	}

	public function webhookEnabled(): bool {
		return $this->appConfig->getAppValue(Constants::CN_WEBHOOK_ENABLED_KEY, 'false') === 'true'
			&& $this->webhookUrl() !== '';
	}

	public function setWebhookEnabled(bool $enabled): void {
		$this->appConfig->setAppValue(Constants::CN_WEBHOOK_ENABLED_KEY, $enabled ? 'true' : 'false');
	}

	public function webhookUrl(): string {
		return (string)$this->appConfig->getAppValue(Constants::CN_WEBHOOK_URL_KEY, '');
	}

	public function setWebhookUrl(string $url): void {
		$this->appConfig->setAppValue(Constants::CN_WEBHOOK_URL_KEY, $url);
	}

	public function webhookSecret(): string {
		return (string)$this->appConfig->getAppValue(Constants::CN_WEBHOOK_SECRET_KEY, '');
	}

	public function setWebhookSecret(string $secret): void {
		$this->appConfig->setAppValue(Constants::CN_WEBHOOK_SECRET_KEY, $secret);
	}

	public function allowListEnabled(): bool {
		return $this->appConfig->getAppValue(Constants::CN_ALLOWLIST_ENABLED_KEY, 'false') === 'true';
	}

	public function setAllowListEnabled(bool $enabled): void {
		$this->appConfig->setAppValue(Constants::CN_ALLOWLIST_ENABLED_KEY, $enabled ? 'true' : 'false');
	}

	private function clamped(string $key, int $default, int $min, int $max): int {
		$raw = $this->appConfig->getAppValue($key, (string)$default);
		$value = is_numeric($raw) ? (int)$raw : $default;
		return max($min, min($max, $value));
	}
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter ChangeNotificationConfigTest`
Expected: PASS, 7 tests

- [ ] **Step 6: Commit**

```bash
git add lib/Constants.php lib/Service/ChangeNotification/ChangeNotificationConfig.php tests/Unit/Service/ChangeNotification/ChangeNotificationConfigTest.php
git commit -m "feat(cn): add change-notification constants and typed config accessor"
```

---

### Task 3: Migration for the ledger and sequence tables

**Files:**
- Create: `lib/Migration/Version000004Date20260820.php`
- Modify: `appinfo/info.xml` (version bump)

- [ ] **Step 1: Write the migration**

Create `lib/Migration/Version000004Date20260820.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Change-notification ledger.
 *
 * sndntsync_dirty holds one row per DAV collection, upserted on every change,
 * so the table is bounded by the number of collections on the instance
 * (roughly users x 2-3) rather than by event volume — no pruning is needed.
 *
 * sndntsync_seq is a portable monotonic counter used only when no distributed
 * cache is configured. It is an insert-and-read-last-id table; its rows are
 * pruned by the flush sweeper.
 *
 * The sequence column is called change_seq because CURSOR is a reserved word
 * in MySQL 8 and PostgreSQL.
 */
class Version000004Date20260820 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('sndntsync_dirty')) {
			$table = $schema->createTable('sndntsync_dirty');

			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('principal_uri', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('collection_type', Types::STRING, [
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('collection_uri', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('sync_token', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
				'length' => 20,
			]);
			$table->addColumn('change_seq', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
				'length' => 20,
			]);
			// Sequence number of the last structural (collection-level) change.
			// The feed reports c = structural_seq > since, which makes the flag
			// exact per request and safe to re-read.
			$table->addColumn('structural_seq', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
				'length' => 20,
			]);
			$table->addColumn('updated_at', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
				'length' => 20,
			]);

			$table->setPrimaryKey(['id']);
			// 255 + 8 + 255 chars = 2072 bytes under utf8mb4, inside InnoDB's
			// 3072-byte index limit with DYNAMIC row format.
			$table->addUniqueIndex(
				['principal_uri', 'collection_type', 'collection_uri'],
				'sndntsync_dirty_coll_uq'
			);
			$table->addIndex(['change_seq'], 'sndntsync_dirty_seq_ix');
		}

		if (!$schema->hasTable('sndntsync_seq')) {
			$table = $schema->createTable('sndntsync_seq');

			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('stamp', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
				'length' => 20,
			]);

			$table->setPrimaryKey(['id']);
		}

		return $schema;
	}
}
```

- [ ] **Step 2: Bump the app version so the migration runs**

In `appinfo/info.xml` change:

```xml
    <version>2.0.4</version>
```

to:

```xml
    <version>2.1.0</version>
```

- [ ] **Step 3: Run the migration**

```bash
php occ upgrade
php occ db:convert-type --help >/dev/null   # no-op; just proves occ still boots
```

Expected: `Updating database schema` mentioning `sndntsync_dirty` and `sndntsync_seq`, exit code 0.

- [ ] **Step 4: Verify the tables exist**

```bash
php occ db:add-missing-indices --dry-run
```

Expected: no missing index reported for `sndntsync_dirty`.

- [ ] **Step 5: Commit**

```bash
git add lib/Migration/Version000004Date20260820.php appinfo/info.xml
git commit -m "feat(cn): add sndntsync_dirty ledger and sndntsync_seq sequence tables"
```

---

### Task 4: The CollectionReference value object

**Files:**
- Create: `lib/ChangeNotification/CollectionReference.php`
- Test: `tests/Unit/ChangeNotification/CollectionReferenceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ChangeNotification/CollectionReferenceTest.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Constants;
use PHPUnit\Framework\TestCase;

class CollectionReferenceTest extends TestCase {

	public function testKeyIdentifiesTheCollection(): void {
		$ref = new CollectionReference(
			'principals/users/alice',
			Constants::COLLECTION_TYPE_CALDAV,
			'personal',
			9651,
			false
		);

		$this->assertSame("principals/users/alice\x00caldav\x00personal", $ref->key());
	}

	public function testKeyIgnoresSyncTokenAndFlag(): void {
		$a = new CollectionReference('principals/users/alice', 'caldav', 'personal', 1, false);
		$b = new CollectionReference('principals/users/alice', 'caldav', 'personal', 2, true);

		$this->assertSame($a->key(), $b->key());
	}

	public function testJsonSerializeUsesTheShortWireFieldNames(): void {
		$ref = new CollectionReference('principals/users/bob', 'carddav', 'contacts', 312, true);

		$this->assertSame(
			['p' => 'principals/users/bob', 't' => 'carddav', 'u' => 'contacts', 's' => 312, 'c' => true],
			$ref->jsonSerialize()
		);
	}

	public function testWithCollectionChangedReturnsANewInstance(): void {
		$ref = new CollectionReference('principals/users/bob', 'carddav', 'contacts', 312, false);
		$flagged = $ref->withCollectionChanged(true);

		$this->assertFalse($ref->collectionChanged);
		$this->assertTrue($flagged->collectionChanged);
		$this->assertSame($ref->key(), $flagged->key());
	}

	public function testKeyIsUnambiguousWhenAUriContainsThePipeCharacter(): void {
		$a = new CollectionReference('principals/users/x', 'caldav', 'y|caldav|z', 1, false);
		$b = new CollectionReference('principals/users/x|caldav|y', 'caldav', 'z', 1, false);

		$this->assertNotSame($a->key(), $b->key());
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter CollectionReferenceTest`
Expected: FAIL — `Class "OCA\SendentSynchroniser\ChangeNotification\CollectionReference" does not exist`

- [ ] **Step 3: Write the value object**

Create `lib/ChangeNotification/CollectionReference.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\ChangeNotification;

use JsonSerializable;

/**
 * One "this collection changed" reference — the only thing a signal ever
 * carries. Deliberately holds no calendar or contact data: principals, URIs
 * and integers only.
 *
 * Wire field names are single letters (roughly 90 bytes per ref) so a 500-ref
 * signal stays around 45 KB.
 */
final class CollectionReference implements JsonSerializable {

	public function __construct(
		public readonly string $principalUri,
		/** Constants::COLLECTION_TYPE_CALDAV or COLLECTION_TYPE_CARDDAV */
		public readonly string $collectionType,
		public readonly string $collectionUri,
		public readonly int $syncToken,
		/** True when the collection itself changed (created/deleted/shared), not just an object in it. */
		public readonly bool $collectionChanged,
	) {}

	/** Identity of the collection, used for dedup and as the ledger's unique key. */
	public function key(): string {
		return $this->principalUri . "\x00" . $this->collectionType . "\x00" . $this->collectionUri;
	}

	public function withCollectionChanged(bool $changed): self {
		return new self(
			$this->principalUri,
			$this->collectionType,
			$this->collectionUri,
			$this->syncToken,
			$changed
		);
	}

	/** @return array{p: string, t: string, u: string, s: int, c: bool} */
	public function jsonSerialize(): array {
		return [
			'p' => $this->principalUri,
			't' => $this->collectionType,
			'u' => $this->collectionUri,
			's' => $this->syncToken,
			'c' => $this->collectionChanged,
		];
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter CollectionReferenceTest`
Expected: PASS, 5 tests

- [ ] **Step 5: Commit**

```bash
git add lib/ChangeNotification/CollectionReference.php tests/Unit/ChangeNotification/CollectionReferenceTest.php
git commit -m "feat(cn): add CollectionReference value object"
```

---

### Task 5: The ledger entity and mapper

**Files:**
- Create: `lib/Db/DirtyCollection.php`
- Create: `lib/Db/DirtyCollectionMapper.php`
- Test: `tests/Unit/Db/DirtyCollectionTest.php`

The mapper's upsert and paging are exercised end-to-end in Task 18's manual verification (they need a real database); the unit test here covers the entity mapping and the reference conversion, which is where the wire-visible logic lives.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Db/DirtyCollectionTest.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Db;

use OCA\SendentSynchroniser\Db\DirtyCollection;
use PHPUnit\Framework\TestCase;

class DirtyCollectionTest extends TestCase {

	private function row(int $structuralSeq = 0): DirtyCollection {
		$entity = new DirtyCollection();
		$entity->setPrincipalUri('principals/users/alice');
		$entity->setCollectionType('caldav');
		$entity->setCollectionUri('personal');
		$entity->setSyncToken(9651);
		$entity->setChangeSeq(1849233);
		$entity->setStructuralSeq($structuralSeq);
		$entity->setUpdatedAt(1755676800);
		return $entity;
	}

	public function testToReferenceCarriesTheRowFields(): void {
		$ref = $this->row()->toReference(0);

		$this->assertSame('principals/users/alice', $ref->principalUri);
		$this->assertSame('caldav', $ref->collectionType);
		$this->assertSame('personal', $ref->collectionUri);
		$this->assertSame(9651, $ref->syncToken);
	}

	public function testCollectionChangedIsTrueWhenTheStructuralChangeIsNewerThanSince(): void {
		$this->assertTrue($this->row(500)->toReference(499)->collectionChanged);
	}

	public function testCollectionChangedIsFalseWhenTheStructuralChangeIsAlreadySeen(): void {
		$this->assertFalse($this->row(500)->toReference(500)->collectionChanged);
		$this->assertFalse($this->row(500)->toReference(900)->collectionChanged);
	}

	public function testCollectionChangedIsFalseWhenThereNeverWasAStructuralChange(): void {
		$this->assertFalse($this->row(0)->toReference(0)->collectionChanged);
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter DirtyCollectionTest`
Expected: FAIL — `Class "OCA\SendentSynchroniser\Db\DirtyCollection" does not exist`

- [ ] **Step 3: Write the entity**

Create `lib/Db/DirtyCollection.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Db;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCP\AppFramework\Db\Entity;

/**
 * One row of the change ledger: the current dirty state of a single DAV
 * collection. Upserted, never appended, so the table is bounded by the number
 * of collections on the instance.
 *
 * @method string getPrincipalUri()
 * @method void setPrincipalUri(string $principalUri)
 * @method string getCollectionType()
 * @method void setCollectionType(string $collectionType)
 * @method string getCollectionUri()
 * @method void setCollectionUri(string $collectionUri)
 * @method int getSyncToken()
 * @method void setSyncToken(int $syncToken)
 * @method int getChangeSeq()
 * @method void setChangeSeq(int $changeSeq)
 * @method int getStructuralSeq()
 * @method void setStructuralSeq(int $structuralSeq)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class DirtyCollection extends Entity {

	protected $principalUri;
	protected $collectionType;
	protected $collectionUri;
	protected $syncToken;
	protected $changeSeq;
	protected $structuralSeq;
	protected $updatedAt;

	public function __construct() {
		$this->addType('syncToken', 'integer');
		$this->addType('changeSeq', 'integer');
		$this->addType('structuralSeq', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	/**
	 * Converts the row to a wire reference relative to a reader's position.
	 *
	 * `collectionChanged` is computed, not stored: it is true exactly when the
	 * last structural change happened after the sequence number the reader has
	 * already seen. That keeps the flag correct on a re-read, which matters
	 * because readers deliberately overlap (see the plan's deviation 5).
	 */
	public function toReference(int $since): CollectionReference {
		return new CollectionReference(
			(string)$this->getPrincipalUri(),
			(string)$this->getCollectionType(),
			(string)$this->getCollectionUri(),
			(int)$this->getSyncToken(),
			(int)$this->getStructuralSeq() > $since
		);
	}
}
```

- [ ] **Step 4: Write the mapper**

Create `lib/Db/DirtyCollectionMapper.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Db;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<DirtyCollection>
 */
class DirtyCollectionMapper extends QBMapper {

	public const TABLE = 'sndntsync_dirty';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, DirtyCollection::class);
	}

	/**
	 * Portable upsert. Update first, because after the first event per
	 * collection every subsequent one is an update — the insert path runs once
	 * per collection in the instance's lifetime.
	 *
	 * IQueryBuilder has no cross-platform ON CONFLICT for arbitrary columns
	 * (insertOrUpdate() keys on the primary key, which we do not know here), so
	 * the unique index is the arbiter and a concurrent insert is caught and
	 * retried as an update.
	 */
	public function record(CollectionReference $ref, int $seq, int $now): void {
		if ($this->touch($ref, $seq, $now) > 0) {
			return;
		}

		try {
			$this->insertRow($ref, $seq, $now);
		} catch (Exception $e) {
			// Accept both the specific and the generic constraint reason:
			// which one a duplicate key maps to differs per DB driver.
			$reason = $e->getReason();
			if ($reason !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION
				&& $reason !== Exception::REASON_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			// Another request inserted the same collection between our UPDATE
			// and our INSERT. Fold into it.
			$this->touch($ref, $seq, $now);
		}
	}

	/**
	 * @return int number of rows updated (0 when the collection is new)
	 *
	 * change_seq/structural_seq are clamped with GREATEST so a slow writer
	 * whose UPDATE commits after a concurrent higher-seq writer can never
	 * regress the row below an already-published watermark (the deviation 5
	 * race, removed here at the source instead of merely being absorbed by
	 * the Connector's reread overlap).
	 *
	 * MySQL affected-rows note: updated_at gets a fresh timestamp and
	 * change_seq normally rises on every call (CursorService never reuses a
	 * seq), so the UPDATE virtually always changes a value and returns >= 1
	 * for an existing row. In the rare same-second lower-seq case it can
	 * return 0 and record() harmlessly falls through to the insert/conflict
	 * path — the row already holds the higher seq, which is the correct end
	 * state.
	 */
	private function touch(CollectionReference $ref, int $seq, int $now): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('sync_token', $qb->createNamedParameter($ref->syncToken, IQueryBuilder::PARAM_INT))
			->set('change_seq', $qb->func()->greatest('change_seq', $qb->expr()->literal($seq, IQueryBuilder::PARAM_INT)))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT));

		if ($ref->collectionChanged) {
			$qb->set('structural_seq', $qb->func()->greatest('structural_seq', $qb->expr()->literal($seq, IQueryBuilder::PARAM_INT)));
		}

		$qb->where($qb->expr()->eq('principal_uri', $qb->createNamedParameter($ref->principalUri)))
			->andWhere($qb->expr()->eq('collection_type', $qb->createNamedParameter($ref->collectionType)))
			->andWhere($qb->expr()->eq('collection_uri', $qb->createNamedParameter($ref->collectionUri)));

		return $qb->executeStatement();
	}

	private function insertRow(CollectionReference $ref, int $seq, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'principal_uri' => $qb->createNamedParameter($ref->principalUri),
			'collection_type' => $qb->createNamedParameter($ref->collectionType),
			'collection_uri' => $qb->createNamedParameter($ref->collectionUri),
			'sync_token' => $qb->createNamedParameter($ref->syncToken, IQueryBuilder::PARAM_INT),
			'change_seq' => $qb->createNamedParameter($seq, IQueryBuilder::PARAM_INT),
			'structural_seq' => $qb->createNamedParameter($ref->collectionChanged ? $seq : 0, IQueryBuilder::PARAM_INT),
			'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		]);
		$qb->executeStatement();
	}

	/**
	 * One indexed range scan over sndntsync_dirty_seq_ix.
	 *
	 * @return DirtyCollection[]
	 */
	public function page(int $since, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->gt('change_seq', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
			->orderBy('change_seq', 'ASC')
			->setMaxResults(max(1, $limit));

		return $this->findEntities($qb);
	}

	public function maxSeq(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('change_seq'))->from(self::TABLE);

		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();

		return is_numeric($value) ? (int)$value : 0;
	}

	public function countAll(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))->from(self::TABLE);

		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();

		return is_numeric($value) ? (int)$value : 0;
	}
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter DirtyCollectionTest`
Expected: PASS, 4 tests

- [ ] **Step 6: Commit**

```bash
git add lib/Db/DirtyCollection.php lib/Db/DirtyCollectionMapper.php tests/Unit/Db/DirtyCollectionTest.php
git commit -m "feat(cn): add ledger entity and mapper with portable upsert"
```

---

### Task 6: The sequence source

**Files:**
- Create: `lib/Db/SequenceMapper.php`
- Create: `lib/Service/ChangeNotification/CursorService.php`
- Test: `tests/Unit/Service/ChangeNotification/CursorServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Service/ChangeNotification/CursorServiceTest.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\Db\DirtyCollectionMapper;
use OCA\SendentSynchroniser\Db\SequenceMapper;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\CursorService;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IMemcache;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CursorServiceTest extends TestCase {

	/** @var ICacheFactory&MockObject */
	private $cacheFactory;

	/** @var IMemcache&MockObject */
	private $memcache;

	/** @var SequenceMapper&MockObject */
	private $sequence;

	/** @var DirtyCollectionMapper&MockObject */
	private $ledger;

	/** @var ChangeNotificationConfig&MockObject */
	private $config;

	/** @var IConfig&MockObject */
	private $serverConfig;

	protected function setUp(): void {
		parent::setUp();
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->memcache = $this->createMock(IMemcache::class);
		$this->sequence = $this->createMock(SequenceMapper::class);
		$this->ledger = $this->createMock(DirtyCollectionMapper::class);
		$this->config = $this->createMock(ChangeNotificationConfig::class);
		$this->serverConfig = $this->createMock(IConfig::class);
	}

	private function service(bool $distributedConfigured = true, bool $cacheAvailable = true): CursorService {
		$this->serverConfig->method('getSystemValue')->with('memcache.distributed', null)
			->willReturn($distributedConfigured ? '\\OC\\Memcache\\Redis' : null);
		$this->cacheFactory->method('isAvailable')->willReturn($cacheAvailable);
		$this->cacheFactory->method('createDistributed')->willReturn($this->memcache);

		return new CursorService(
			$this->cacheFactory,
			$this->serverConfig,
			$this->sequence,
			$this->ledger,
			$this->config,
		);
	}

	public function testNextUsesTheDistributedCacheCounter(): void {
		$this->config->method('flushedSeq')->willReturn(0);
		$this->memcache->method('inc')->willReturn(42);
		$this->sequence->expects($this->never())->method('next');

		$this->assertSame(42, $this->service()->next());
	}

	public function testNextFallsBackToTheDatabaseWithoutAConfiguredDistributedCache(): void {
		// APCu-only installs: createDistributed() silently falls back to the
		// LOCAL cache, whose counter is per-process (php-fpm workers and the
		// cron CLI each see their own). Explicit memcache.distributed config
		// is the only reliable signal the counter is actually shared.
		$this->sequence->method('next')->willReturn(7);

		$this->assertSame(7, $this->service(false)->next());
	}

	public function testNextFallsBackToTheDatabaseWhenIncFails(): void {
		$this->memcache->method('inc')->willReturn(false);
		$this->sequence->method('next')->willReturn(8);

		$this->assertSame(8, $this->service()->next());
	}

	public function testARestartedCounterIsReseededAboveTheWatermark(): void {
		// The cache was evicted: inc() recreates the key at 1 while the feed's
		// watermark is 5000. Handing out 1 would stamp rows below every
		// reader's `since` — permanently invisible — so the counter is lifted.
		$this->config->method('flushedSeq')->willReturn(5000);
		$this->ledger->method('maxSeq')->willReturn(5000);
		$this->memcache->method('inc')->willReturnOnConsecutiveCalls(1, 5002);
		$this->memcache->expects($this->once())
			->method('cas')
			->with($this->anything(), 1, 5001)
			->willReturn(true);

		$this->assertSame(5002, $this->service()->next());
	}

	public function testALostReseedRaceRetriesUntilAboveTheWatermark(): void {
		// Two requests race after an eviction: ours incs to 2, our first cas
		// loses to a concurrent writer, the re-inc lands at 3 (still below the
		// mark), the second cas wins. A single-attempt reseed would have
		// returned 3 — a below-watermark value no reader would ever see.
		$this->config->method('flushedSeq')->willReturn(5000);
		$this->ledger->method('maxSeq')->willReturn(5000);
		$this->memcache->method('inc')->willReturnOnConsecutiveCalls(2, 3, 5002);
		$this->memcache->method('cas')->willReturnOnConsecutiveCalls(false, true);

		$this->assertSame(5002, $this->service()->next());
	}

	public function testAnExhaustedReseedFallsBackToAReseededDbSequence(): void {
		// If the shared counter cannot be repaired within the retry budget,
		// never return a below-watermark value — lift the DB sequence above
		// the mark and use that instead.
		$this->config->method('flushedSeq')->willReturn(5000);
		$this->ledger->method('maxSeq')->willReturn(5000);
		$this->memcache->method('inc')->willReturn(1);
		$this->memcache->method('cas')->willReturn(false);
		$this->sequence->expects($this->once())->method('reseedAbove')->with(5000);
		$this->sequence->method('next')->willReturn(5001);

		$this->assertSame(5001, $this->service()->next());
	}

	public function testAFreshInstanceIsNotReseeded(): void {
		$this->config->method('flushedSeq')->willReturn(0);
		$this->ledger->method('maxSeq')->willReturn(0);
		$this->memcache->method('inc')->willReturn(1);
		$this->memcache->expects($this->never())->method('cas');

		$this->assertSame(1, $this->service()->next());
	}

	public function testCurrentReadsTheCounter(): void {
		$this->memcache->method('get')->willReturn('1849233');

		$this->assertSame(1849233, $this->service()->current());
	}

	public function testCurrentFallsBackToTheLedgerHighWaterMark(): void {
		$this->memcache->method('get')->willReturn(null);
		$this->ledger->method('maxSeq')->willReturn(4711);

		$this->assertSame(4711, $this->service()->current());
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter CursorServiceTest`
Expected: FAIL — `Class "OCA\SendentSynchroniser\Db\SequenceMapper" does not exist`

- [ ] **Step 3: Write the sequence mapper**

Create `lib/Db/SequenceMapper.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Db;

use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Portable monotonic counter for instances with no distributed cache.
 *
 * Insert a row, read the autoincrement id. This works identically on MySQL,
 * PostgreSQL and SQLite and needs no SELECT ... FOR UPDATE, so it never holds
 * a row lock on the DAV write path.
 *
 * A configured offset is added to every id so the sequence can be lifted above
 * the ledger's high-water mark if an instance loses its distributed cache
 * permanently (`occ sendentsynchroniser:cn-setup --reseed`).
 */
class SequenceMapper {

	public const TABLE = 'sndntsync_seq';

	public function __construct(
		private IDBConnection $db,
		private ChangeNotificationConfig $config,
		private ITimeFactory $time,
	) {}

	public function next(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'stamp' => $qb->createNamedParameter($this->time->getTime(), IQueryBuilder::PARAM_INT),
		]);
		$qb->executeStatement();

		return $this->config->seqOffset() + (int)$qb->getLastInsertId();
	}

	/**
	 * Lifts the emitted sequence above $target without rewriting autoincrement state.
	 *
	 * Deliberately not atomic: two racing callers each consume their own id via
	 * next() and compute the offset delta from that id, so whichever write lands
	 * last still guarantees offset + any future id > target for its own consumed
	 * value — a lost update can only produce a smaller-than-optimal (never
	 * insufficient) lift. Do not "fix" this with a lock; it does not need one.
	 */
	public function reseedAbove(int $target): void {
		$current = $this->next();
		if ($current <= $target) {
			$this->config->setSeqOffset($this->config->seqOffset() + ($target - $current) + 1);
		}
	}

	/** Keeps the table from growing without bound. Called by the flush sweeper. */
	public function prune(int $keep = 1000): int {
		// A negative $keep would delete every row (incl. the max-id row) and
		// break the never-empty invariant that prevents autoincrement reuse.
		$keep = max(0, $keep);
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('id'))->from(self::TABLE);
		$result = $qb->executeQuery();
		$max = $result->fetchOne();
		$result->closeCursor();

		if (!is_numeric($max) || (int)$max <= $keep) {
			return 0;
		}

		$delete = $this->db->getQueryBuilder();
		$delete->delete(self::TABLE)
			->where($delete->expr()->lt('id', $delete->createNamedParameter((int)$max - $keep, IQueryBuilder::PARAM_INT)));

		return $delete->executeStatement();
	}
}
```

- [ ] **Step 4: Write the cursor service**

Create `lib/Service/ChangeNotification/CursorService.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\Db\DirtyCollectionMapper;
use OCA\SendentSynchroniser\Db\SequenceMapper;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IMemcache;

/**
 * The monotonic sequence stamped onto every ledger row.
 *
 * Preferred source is an atomic INCR on the distributed cache (~50 us).
 * Without one, the DB sequence table is used instead — realistic only on small
 * instances, which are also the instances that fall back to polling anyway.
 *
 * Sequence numbers are monotonic but NOT gapless: a DAV write that rolls back
 * still consumed one. Readers only ever compare with `>`, so gaps are free.
 *
 * The one failure that must never happen is handing out a value at or below
 * the flushed watermark: a row stamped with it sits below every reader's
 * `since` forever. next() therefore treats any counter value <= flushedSeq
 * (a restarted/evicted counter) as broken and repairs it before returning.
 */
class CursorService {

	private const KEY = 'cursor';
	private const CACHE_PREFIX = 'sndntsync_cn/';
	private const RESEED_RETRIES = 3;

	public function __construct(
		private ICacheFactory $cacheFactory,
		private IConfig $serverConfig,
		private SequenceMapper $sequence,
		private DirtyCollectionMapper $ledger,
		private ChangeNotificationConfig $config,
	) {}

	public function next(): int {
		$cache = $this->memcache();
		if ($cache === null) {
			return $this->sequence->next();
		}

		$value = $cache->inc(self::KEY);
		if (!is_int($value)) {
			return $this->sequence->next();
		}

		// Restart detection. flushedSeq() is the cheap per-event gate (config
		// read); value === 1 additionally catches polling-only instances whose
		// watermark never advances. maxSeq() (one indexed MAX) only runs on
		// the rare repair path.
		$floor = $this->config->flushedSeq();
		if ($value <= $floor || $value === 1) {
			$seed = max($this->ledger->maxSeq(), $floor);
			for ($i = 0; $i < self::RESEED_RETRIES && $value <= $seed; $i++) {
				// cas() fails when a concurrent request raced the reseed with
				// its own inc(); re-inc and re-check rather than trusting a
				// single attempt — a lost race must never return a
				// below-watermark value.
				$cache->cas(self::KEY, $value, $seed + 1);
				$value = $cache->inc(self::KEY);
				if (!is_int($value)) {
					break;
				}
			}
			if (!is_int($value) || $value <= $seed) {
				// Shared counter unrepairable right now; the DB sequence,
				// lifted above the seed, is always safe.
				$this->sequence->reseedAbove($seed);
				return $this->sequence->next();
			}
		}

		return $value;
	}

	/** The highest sequence number handed out so far; cheap, read-only. */
	public function current(): int {
		$cache = $this->memcache();
		if ($cache !== null) {
			$value = $cache->get(self::KEY);
			if (is_numeric($value)) {
				return (int)$value;
			}
		}

		// Floor at the flushed watermark for symmetry with next()'s repair
		// path: under-reporting after a cache eviction is safe (readers only
		// re-see known rows) but pointless when the watermark is known higher.
		return max($this->ledger->maxSeq(), $this->config->flushedSeq());
	}

	private function memcache(): ?IMemcache {
		// ICacheFactory::createDistributed() silently falls back to the LOCAL
		// cache class when memcache.distributed is not configured (the common
		// APCu-only install). A local counter is per-process — php-fpm workers
		// and the cron CLI would hand out duplicate sequence numbers — so an
		// explicitly configured distributed cache is required; anything else
		// uses the DB sequence.
		if ($this->serverConfig->getSystemValue('memcache.distributed', null) === null) {
			return null;
		}
		if (!$this->cacheFactory->isAvailable()) {
			return null;
		}

		$cache = $this->cacheFactory->createDistributed(self::CACHE_PREFIX);

		return $cache instanceof IMemcache ? $cache : null;
	}
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter CursorServiceTest`
Expected: PASS, 9 tests

- [ ] **Step 6: Commit**

```bash
git add lib/Db/SequenceMapper.php lib/Service/ChangeNotification/CursorService.php tests/Unit/Service/ChangeNotification/CursorServiceTest.php
git commit -m "feat(cn): add monotonic cursor with distributed-cache and DB sources"
```

---

### Task 7: The DAV event reference extractor

A pure array-to-value-object mapper with no Nextcloud dependencies, so every shape of event payload the DAV backends produce can be tested exhaustively and cheaply.

**Files:**
- Create: `lib/Service/ChangeNotification/DavEventReferenceExtractor.php`
- Test: `tests/Unit/Service/ChangeNotification/DavEventReferenceExtractorTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Service/ChangeNotification/DavEventReferenceExtractorTest.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\Service\ChangeNotification\DavEventReferenceExtractor;
use PHPUnit\Framework\TestCase;

class DavEventReferenceExtractorTest extends TestCase {

	private DavEventReferenceExtractor $extractor;

	protected function setUp(): void {
		parent::setUp();
		$this->extractor = new DavEventReferenceExtractor();
	}

	public function testCalendarRowBecomesACaldavReference(): void {
		$ref = $this->extractor->fromCalendarRow([
			'id' => 42,
			'uri' => 'personal',
			'principaluri' => 'principals/users/alice',
			'synctoken' => 9651,
		], false);

		$this->assertNotNull($ref);
		$this->assertSame('principals/users/alice', $ref->principalUri);
		$this->assertSame('caldav', $ref->collectionType);
		$this->assertSame('personal', $ref->collectionUri);
		$this->assertSame(9651, $ref->syncToken);
		$this->assertFalse($ref->collectionChanged);
	}

	public function testAddressBookRowBecomesACarddavReference(): void {
		$ref = $this->extractor->fromAddressBookRow([
			'id' => 7,
			'uri' => 'contacts',
			'principaluri' => 'principals/users/bob',
			'synctoken' => 312,
		], true);

		$this->assertNotNull($ref);
		$this->assertSame('carddav', $ref->collectionType);
		$this->assertSame('contacts', $ref->collectionUri);
		$this->assertSame(312, $ref->syncToken);
		$this->assertTrue($ref->collectionChanged);
	}

	public function testSyncTokenIsReadFromTheSabreDavNamespacedKey(): void {
		$ref = $this->extractor->fromCalendarRow([
			'uri' => 'personal',
			'principaluri' => 'principals/users/alice',
			'{http://sabredav.org/ns}sync-token' => '77',
		], false);

		$this->assertSame(77, $ref->syncToken);
	}

	public function testSyncTokenStripsTheSabreSyncUrlPrefix(): void {
		$ref = $this->extractor->fromCalendarRow([
			'uri' => 'personal',
			'principaluri' => 'principals/users/alice',
			'{http://sabredav.org/ns}sync-token' => 'http://sabre.io/ns/sync/1234',
		], false);

		$this->assertSame(1234, $ref->syncToken);
	}

	public function testRawColumnWinsOverTheNamespacedKey(): void {
		$ref = $this->extractor->fromCalendarRow([
			'uri' => 'personal',
			'principaluri' => 'principals/users/alice',
			'synctoken' => 5,
			'{http://sabredav.org/ns}sync-token' => '9',
		], false);

		$this->assertSame(5, $ref->syncToken);
	}

	public function testMissingSyncTokenBecomesZero(): void {
		$ref = $this->extractor->fromCalendarRow([
			'uri' => 'personal',
			'principaluri' => 'principals/users/alice',
		], false);

		$this->assertSame(0, $ref->syncToken);
	}

	public function testNullRowYieldsNull(): void {
		$this->assertNull($this->extractor->fromCalendarRow(null, false));
	}

	public function testRowWithoutAPrincipalYieldsNull(): void {
		$this->assertNull($this->extractor->fromCalendarRow(['uri' => 'personal'], false));
	}

	public function testRowWithoutAUriYieldsNull(): void {
		$this->assertNull($this->extractor->fromCalendarRow(['principaluri' => 'principals/users/alice'], false));
	}

	public function testRowWithEmptyStringsYieldsNull(): void {
		$this->assertNull($this->extractor->fromCalendarRow([
			'uri' => '',
			'principaluri' => 'principals/users/alice',
		], false));
	}

	public function testSystemPrincipalsAreIgnored(): void {
		// Calendars owned by principals/system/* (birthday reminders, the
		// public calendar root) have no mailbox behind them.
		$this->assertNull($this->extractor->fromCalendarRow([
			'uri' => 'contact_birthdays',
			'principaluri' => 'principals/system/system',
		], false));
	}

	public function testGroupPrincipalsAreIgnored(): void {
		$this->assertNull($this->extractor->fromCalendarRow([
			'uri' => 'shared',
			'principaluri' => 'principals/groups/finance',
		], false));
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter DavEventReferenceExtractorTest`
Expected: FAIL — `Class "OCA\SendentSynchroniser\Service\ChangeNotification\DavEventReferenceExtractor" does not exist`

- [ ] **Step 3: Write the extractor**

Create `lib/Service/ChangeNotification/DavEventReferenceExtractor.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Constants;

/**
 * Turns a CalDAV/CardDAV collection row — as carried by every OCA\DAV event —
 * into a CollectionReference.
 *
 * Deliberately free of Nextcloud types: it sees plain arrays, so the whole
 * matrix of payload shapes across NC 28-34 is unit-testable without a server.
 *
 * The principal comes from the collection row, never from the acting session
 * user: a shared calendar edited by Bob has to signal Alice's collection.
 */
class DavEventReferenceExtractor {

	/** Only user principals map to a mailbox. */
	private const USER_PRINCIPAL_PREFIX = 'principals/users/';

	/** Sabre renders sync tokens as this URL; the ledger stores the integer. */
	private const SYNC_TOKEN_PREFIX = 'http://sabre.io/ns/sync/';

	/** @param array<string, mixed>|null $row */
	public function fromCalendarRow(?array $row, bool $structural): ?CollectionReference {
		return $this->fromRow($row, Constants::COLLECTION_TYPE_CALDAV, $structural);
	}

	/** @param array<string, mixed>|null $row */
	public function fromAddressBookRow(?array $row, bool $structural): ?CollectionReference {
		return $this->fromRow($row, Constants::COLLECTION_TYPE_CARDDAV, $structural);
	}

	/** @param array<string, mixed>|null $row */
	private function fromRow(?array $row, string $type, bool $structural): ?CollectionReference {
		if (!is_array($row)) {
			return null;
		}

		$principal = $row['principaluri'] ?? null;
		$uri = $row['uri'] ?? null;

		if (!is_string($principal) || !is_string($uri) || $uri === '') {
			return null;
		}

		if (!str_starts_with($principal, self::USER_PRINCIPAL_PREFIX)
			|| $principal === self::USER_PRINCIPAL_PREFIX) {
			return null;
		}

		return new CollectionReference($principal, $type, $uri, $this->syncToken($row), $structural);
	}

	/** @param array<string, mixed> $row */
	private function syncToken(array $row): int {
		$raw = $row['synctoken'] ?? null;
		if ($raw === null) {
			$raw = $row['{http://sabredav.org/ns}sync-token'] ?? null;
		}

		if (is_object($raw)) {
			// Some backends hand back a Sabre property object rather than a scalar.
			$raw = method_exists($raw, 'getValue') ? $raw->getValue() : null;
		}

		if (is_string($raw) && str_starts_with($raw, self::SYNC_TOKEN_PREFIX)) {
			$raw = substr($raw, strlen(self::SYNC_TOKEN_PREFIX));
		}

		return is_numeric($raw) ? (int)$raw : 0;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter DavEventReferenceExtractorTest`
Expected: PASS, 12 tests

- [ ] **Step 5: Commit**

```bash
git add lib/Service/ChangeNotification/DavEventReferenceExtractor.php tests/Unit/Service/ChangeNotification/DavEventReferenceExtractorTest.php
git commit -m "feat(cn): extract collection references from DAV event payloads"
```

---

### Task 8: The change ledger service

**Files:**
- Create: `lib/Service/ChangeNotification/ChangeLedgerService.php`
- Test: `tests/Unit/Service/ChangeNotification/ChangeLedgerServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Service/ChangeNotification/ChangeLedgerServiceTest.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Db\DirtyCollection;
use OCA\SendentSynchroniser\Db\DirtyCollectionMapper;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\CursorService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ChangeLedgerServiceTest extends TestCase {

	/** @var DirtyCollectionMapper&MockObject */
	private $mapper;

	/** @var CursorService&MockObject */
	private $cursor;

	/** @var ITimeFactory&MockObject */
	private $time;

	private ChangeLedgerService $ledger;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(DirtyCollectionMapper::class);
		$this->cursor = $this->createMock(CursorService::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(1755676800);
		$this->ledger = new ChangeLedgerService($this->mapper, $this->cursor, $this->time);
	}

	private function ref(string $uri, int $token = 1, bool $structural = false): CollectionReference {
		return new CollectionReference('principals/users/alice', 'caldav', $uri, $token, $structural);
	}

	public function testRecordStampsEachReferenceWithItsOwnSequence(): void {
		$this->cursor->method('next')->willReturnOnConsecutiveCalls(10, 11);

		$seen = [];
		$this->mapper->expects($this->exactly(2))
			->method('record')
			->willReturnCallback(function (CollectionReference $ref, int $seq, int $now) use (&$seen): void {
				$seen[] = [$ref->collectionUri, $seq, $now];
			});

		$highest = $this->ledger->record([$this->ref('personal'), $this->ref('work')]);

		$this->assertSame([['personal', 10, 1755676800], ['work', 11, 1755676800]], $seen);
		$this->assertSame(11, $highest);
	}

	public function testRecordCollapsesRepeatsOfTheSameCollection(): void {
		// One record() call can carry the same collection twice (e.g. a
		// CalendarObjectMovedEvent whose source and target calendar are the
		// same). One row, one sequence.
		$this->cursor->expects($this->once())->method('next')->willReturn(10);
		$this->mapper->expects($this->once())
			->method('record')
			->with($this->callback(fn (CollectionReference $r) => $r->syncToken === 2));

		$this->ledger->record([$this->ref('personal', 1), $this->ref('personal', 2)]);
	}

	public function testDedupKeepsTheStructuralFlagIfAnyDuplicateHadIt(): void {
		$this->cursor->method('next')->willReturn(10);
		$this->mapper->expects($this->once())
			->method('record')
			->with($this->callback(fn (CollectionReference $r) => $r->collectionChanged === true));

		$this->ledger->record([
			$this->ref('personal', 1, true),
			$this->ref('personal', 2, false),
		]);
	}

	public function testRecordOfNothingTouchesNothing(): void {
		$this->cursor->expects($this->never())->method('next');
		$this->mapper->expects($this->never())->method('record');

		$this->assertSame(0, $this->ledger->record([]));
	}

	public function testHighestSeqOfAPageIsTheLastRowsSequence(): void {
		$first = new DirtyCollection();
		$first->setChangeSeq(600);
		$second = new DirtyCollection();
		$second->setChangeSeq(742);

		$this->assertSame(742, $this->ledger->highestSeqOf([$first, $second]));
		$this->assertSame(0, $this->ledger->highestSeqOf([]));
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter ChangeLedgerServiceTest`
Expected: FAIL — `Class "OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService" does not exist`

- [ ] **Step 3: Write the service**

Create `lib/Service/ChangeNotification/ChangeLedgerService.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Db\DirtyCollection;
use OCA\SendentSynchroniser\Db\DirtyCollectionMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * The durable half of the design: every change lands here before any transport
 * is considered, so a lost, debounced or duplicated signal only ever costs a
 * reader one extra idempotent read.
 *
 * Cost is one cursor operation plus one upsert per collection reference (a
 * DAV event yields one ref, or two for a cross-calendar move). Nothing here is
 * deferred to a background job — a queued job per event is exactly the shape
 * this design exists to avoid.
 */
class ChangeLedgerService {

	public function __construct(
		private DirtyCollectionMapper $mapper,
		private CursorService $cursor,
		private ITimeFactory $time,
	) {}

	/**
	 * @param CollectionReference[] $refs
	 * @return int the highest sequence number written, 0 when nothing was recorded
	 */
	public function record(array $refs): int {
		$now = $this->time->getTime();
		$highest = 0;

		foreach ($this->dedupe($refs) as $ref) {
			$seq = $this->cursor->next();
			$this->mapper->record($ref, $seq, $now);
			$highest = max($highest, $seq);
		}

		return $highest;
	}

	/** @return DirtyCollection[] */
	public function rows(int $since, int $limit): array {
		return $this->mapper->page($since, $limit);
	}

	/** @param DirtyCollection[] $rows */
	public function highestSeqOf(array $rows): int {
		$highest = 0;
		foreach ($rows as $row) {
			$highest = max($highest, (int)$row->getChangeSeq());
		}

		return $highest;
	}

	public function highWaterMark(): int {
		return $this->mapper->maxSeq();
	}

	public function countCollections(): int {
		return $this->mapper->countAll();
	}

	/**
	 * Collapses references to the same collection, keeping the last sync token
	 * and OR-ing the structural flag.
	 *
	 * @param CollectionReference[] $refs
	 * @return CollectionReference[]
	 */
	private function dedupe(array $refs): array {
		/** @var array<string, CollectionReference> $byKey */
		$byKey = [];

		foreach ($refs as $ref) {
			$key = $ref->key();
			$structural = $ref->collectionChanged
				|| (isset($byKey[$key]) && $byKey[$key]->collectionChanged);
			$byKey[$key] = $ref->withCollectionChanged($structural);
		}

		return array_values($byKey);
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter ChangeLedgerServiceTest`
Expected: PASS, 5 tests

- [ ] **Step 5: Commit**

```bash
git add lib/Service/ChangeNotification/ChangeLedgerService.php tests/Unit/Service/ChangeNotification/ChangeLedgerServiceTest.php
git commit -m "feat(cn): add change ledger service with per-request dedup"
```

---

### Task 9: The DAV change listener

**Files:**
- Create: `lib/Listener/DavChangeListener.php`
- Modify: `lib/AppInfo/Application.php`
- Test: `tests/Unit/Listener/DavChangeListenerTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Listener/DavChangeListenerTest.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Listener;

use OCA\DAV\Events\AddressBookDeletedEvent;
use OCA\DAV\Events\CalendarCreatedEvent;
use OCA\DAV\Events\CalendarObjectCreatedEvent;
use OCA\DAV\Events\CalendarObjectMovedEvent;
use OCA\DAV\Events\CardUpdatedEvent;
use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Listener\DavChangeListener;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\DavEventReferenceExtractor;
use OCA\SendentSynchroniser\Service\ChangeNotification\SignalPublisher;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class DavChangeListenerTest extends TestCase {

	private const CALENDAR = [
		'id' => 42,
		'uri' => 'personal',
		'principaluri' => 'principals/users/alice',
		'synctoken' => 9651,
	];

	private const OTHER_CALENDAR = [
		'id' => 43,
		'uri' => 'work',
		'principaluri' => 'principals/users/alice',
		'synctoken' => 12,
	];

	private const ADDRESS_BOOK = [
		'id' => 7,
		'uri' => 'contacts',
		'principaluri' => 'principals/users/bob',
		'synctoken' => 312,
	];

	/** @var ChangeLedgerService&MockObject */
	private $ledger;

	/** @var SignalPublisher&MockObject */
	private $publisher;

	private DavChangeListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->ledger = $this->createMock(ChangeLedgerService::class);
		$this->publisher = $this->createMock(SignalPublisher::class);
		$this->listener = new DavChangeListener(
			$this->ledger,
			$this->publisher,
			new DavEventReferenceExtractor(),
			new NullLogger(),
		);
	}

	/** @return CollectionReference[] */
	private function captureRecordedRefs(Event $event): array {
		$captured = [];
		$this->ledger->method('record')->willReturnCallback(
			function (array $refs) use (&$captured): int {
				$captured = $refs;
				return 1;
			}
		);
		$this->listener->handle($event);

		return $captured;
	}

	public function testObjectEventRecordsANonStructuralReference(): void {
		$refs = $this->captureRecordedRefs(
			new CalendarObjectCreatedEvent(42, self::CALENDAR, [], ['uri' => 'a.ics'])
		);

		$this->assertCount(1, $refs);
		$this->assertSame('personal', $refs[0]->collectionUri);
		$this->assertSame('caldav', $refs[0]->collectionType);
		$this->assertFalse($refs[0]->collectionChanged);
	}

	public function testCollectionEventRecordsAStructuralReference(): void {
		$refs = $this->captureRecordedRefs(new CalendarCreatedEvent(42, self::CALENDAR));

		$this->assertCount(1, $refs);
		$this->assertTrue($refs[0]->collectionChanged);
	}

	public function testMovedEventRecordsBothSourceAndTarget(): void {
		$refs = $this->captureRecordedRefs(new CalendarObjectMovedEvent(
			42,
			self::CALENDAR,
			43,
			self::OTHER_CALENDAR,
			[],
			[],
			['uri' => 'a.ics']
		));

		$uris = array_map(static fn (CollectionReference $r) => $r->collectionUri, $refs);
		sort($uris);
		$this->assertSame(['personal', 'work'], $uris);
	}

	public function testCardEventRecordsACarddavReference(): void {
		$refs = $this->captureRecordedRefs(
			new CardUpdatedEvent(7, self::ADDRESS_BOOK, [], ['uri' => 'c.vcf'])
		);

		$this->assertCount(1, $refs);
		$this->assertSame('carddav', $refs[0]->collectionType);
		$this->assertFalse($refs[0]->collectionChanged);
	}

	public function testAddressBookEventRecordsAStructuralCarddavReference(): void {
		$refs = $this->captureRecordedRefs(new AddressBookDeletedEvent(7, self::ADDRESS_BOOK, []));

		$this->assertCount(1, $refs);
		$this->assertSame('carddav', $refs[0]->collectionType);
		$this->assertTrue($refs[0]->collectionChanged);
	}

	public function testUnrelatedEventsAreIgnored(): void {
		$this->ledger->expects($this->never())->method('record');
		$this->publisher->expects($this->never())->method('flushIfDue');

		$this->listener->handle(new class extends Event {
		});
	}

	public function testAFlushIsOfferedAfterRecording(): void {
		$this->ledger->method('record')->willReturn(1);
		$this->publisher->expects($this->once())->method('flushIfDue');

		$this->listener->handle(new CalendarObjectCreatedEvent(42, self::CALENDAR, [], ['uri' => 'a.ics']));
	}

	public function testAFailingLedgerNeverBreaksTheDavWrite(): void {
		// The listener runs inside CalDavBackend's still-open transaction; a
		// throw here would roll back the user's own calendar write.
		$this->ledger->method('record')->willThrowException(new \RuntimeException('db down'));

		$this->listener->handle(new CalendarObjectCreatedEvent(42, self::CALENDAR, [], ['uri' => 'a.ics']));

		$this->addToAssertionCount(1);
	}

	public function testAFailingPublisherNeverBreaksTheDavWrite(): void {
		$this->ledger->method('record')->willReturn(1);
		$this->publisher->method('flushIfDue')->willThrowException(new \RuntimeException('redis down'));

		$this->listener->handle(new CalendarObjectCreatedEvent(42, self::CALENDAR, [], ['uri' => 'a.ics']));

		$this->addToAssertionCount(1);
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter DavChangeListenerTest`
Expected: FAIL — `Class "OCA\SendentSynchroniser\Listener\DavChangeListener" does not exist`

- [ ] **Step 3: Write the listener**

Create `lib/Listener/DavChangeListener.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Listener;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\DavEventReferenceExtractor;
use OCA\SendentSynchroniser\Service\ChangeNotification\SignalPublisher;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Reduces every CalDAV/CardDAV write event to collection references, records
 * them in the ledger, and offers the batch window a chance to flush.
 *
 * Runs in-request, inside the DAV backend's still-open atomic() transaction,
 * so it must never throw: a throw here rolls back the user's own calendar or
 * contact write. Every failure is caught and logged.
 *
 * instanceof against a class that does not exist on the running Nextcloud
 * version evaluates to false, which is what lets one listener cover NC 28-34
 * (the move-to-trash and restore events changed namespace in NC 31.0.2/32).
 * On NC 31 both flavours are dispatched for one delete, arriving as two
 * separate handle() calls; the ledger's upsert makes the second one a
 * harmless re-touch of the same row.
 */
class DavChangeListener implements IEventListener {

	/** True once this request already logged a ledger/publisher failure. */
	private bool $failureLogged = false;

	public function __construct(
		private ChangeLedgerService $ledger,
		private SignalPublisher $publisher,
		private DavEventReferenceExtractor $extractor,
		private LoggerInterface $logger,
	) {}

	public function handle(Event $event): void {
		try {
			$refs = $this->references($event);
			if ($refs === []) {
				return;
			}

			$this->ledger->record($refs);
			$this->publisher->flushIfDue();
		} catch (\Throwable $e) {
			// Log the first failure per request at ERROR with the trace; any
			// further events in the same request degrade to a terse warning so
			// a dead DB cannot flood the log with duplicate stack traces. A
			// cross-request throttle is deliberately absent: it would add a
			// failable dependency (cache) to this never-throw path.
			if ($this->failureLogged) {
				$this->logger->warning('Further DAV change-recording failure in this request: ' . $e->getMessage(), [
					'app' => 'sendentsynchroniser',
				]);
				return;
			}
			$this->failureLogged = true;
			$this->logger->error('Failed to record a DAV change for the Connector: ' . $e->getMessage(), [
				'exception' => $e,
				'app' => 'sendentsynchroniser',
			]);
		}
	}

	/** @return CollectionReference[] */
	private function references(Event $event): array {
		// ── CalDAV, object level ────────────────────────────────────────
		if ($event instanceof \OCA\DAV\Events\CalendarObjectCreatedEvent
			|| $event instanceof \OCA\DAV\Events\CalendarObjectUpdatedEvent
			|| $event instanceof \OCA\DAV\Events\CalendarObjectDeletedEvent
			|| $event instanceof \OCA\DAV\Events\CalendarObjectMovedToTrashEvent
			|| $event instanceof \OCA\DAV\Events\CalendarObjectRestoredEvent
			|| $event instanceof \OCP\Calendar\Events\CalendarObjectMovedToTrashEvent
			|| $event instanceof \OCP\Calendar\Events\CalendarObjectRestoredEvent) {
			return $this->one($this->extractor->fromCalendarRow($event->getCalendarData(), false));
		}

		if ($event instanceof \OCA\DAV\Events\CalendarObjectMovedEvent) {
			return array_merge(
				$this->one($this->extractor->fromCalendarRow($event->getSourceCalendarData(), false)),
				$this->one($this->extractor->fromCalendarRow($event->getTargetCalendarData(), false)),
			);
		}

		// ── CalDAV, collection level ────────────────────────────────────
		// The OCP flavours of the collection-level trash events are included
		// defensively: instanceof on a class absent from the running NC
		// version is false, and if NC 32+ moved these events to OCP (as it
		// did the object-level ones) omitting them would silence calendar
		// trash/restore entirely there. Verified empirically in Task 18.
		if ($event instanceof \OCA\DAV\Events\CalendarCreatedEvent
			|| $event instanceof \OCA\DAV\Events\CalendarUpdatedEvent
			|| $event instanceof \OCA\DAV\Events\CalendarDeletedEvent
			|| $event instanceof \OCA\DAV\Events\CalendarMovedToTrashEvent
			|| $event instanceof \OCA\DAV\Events\CalendarRestoredEvent
			|| $event instanceof \OCP\Calendar\Events\CalendarMovedToTrashEvent
			|| $event instanceof \OCP\Calendar\Events\CalendarRestoredEvent
			|| $event instanceof \OCA\DAV\Events\CalendarShareUpdatedEvent
			|| $event instanceof \OCA\DAV\Events\CalendarPublishedEvent
			|| $event instanceof \OCA\DAV\Events\CalendarUnpublishedEvent) {
			return $this->one($this->extractor->fromCalendarRow($event->getCalendarData(), true));
		}

		// ── CardDAV, object level ───────────────────────────────────────
		if ($event instanceof \OCA\DAV\Events\CardCreatedEvent
			|| $event instanceof \OCA\DAV\Events\CardUpdatedEvent
			|| $event instanceof \OCA\DAV\Events\CardDeletedEvent) {
			return $this->one($this->extractor->fromAddressBookRow($event->getAddressBookData(), false));
		}

		// ── CardDAV, collection level ───────────────────────────────────
		if ($event instanceof \OCA\DAV\Events\AddressBookCreatedEvent
			|| $event instanceof \OCA\DAV\Events\AddressBookUpdatedEvent
			|| $event instanceof \OCA\DAV\Events\AddressBookDeletedEvent
			|| $event instanceof \OCA\DAV\Events\AddressBookShareUpdatedEvent) {
			return $this->one($this->extractor->fromAddressBookRow($event->getAddressBookData(), true));
		}

		return [];
	}

	/**
	 * @return CollectionReference[]
	 */
	private function one(?CollectionReference $ref): array {
		return $ref === null ? [] : [$ref];
	}
}
```

- [ ] **Step 4: Register the listener**

In `lib/AppInfo/Application.php`, add the import at the top with the other `use` statements:

```php
use OCA\SendentSynchroniser\Listener\DavChangeListener;
```

Then append inside `register()`, immediately before `$context->registerNotifierService(Notifier::class);`:

```php
		// Change notifications for the Exchange Connector. Every CalDAV/CardDAV
		// write is reduced to a collection reference and recorded in the ledger.
		// Class-strings for events that do not exist on the running Nextcloud
		// version are harmless: they are simply never dispatched. That is what
		// covers the NC 28-34 range, where move-to-trash and restore moved from
		// OCA\DAV\Events to OCP\Calendar\Events in NC 31.0.2.
		$changeEvents = [
			\OCA\DAV\Events\CalendarObjectCreatedEvent::class,
			\OCA\DAV\Events\CalendarObjectUpdatedEvent::class,
			\OCA\DAV\Events\CalendarObjectDeletedEvent::class,
			\OCA\DAV\Events\CalendarObjectMovedEvent::class,
			\OCA\DAV\Events\CalendarObjectMovedToTrashEvent::class,
			\OCA\DAV\Events\CalendarObjectRestoredEvent::class,
			\OCP\Calendar\Events\CalendarObjectMovedToTrashEvent::class,
			\OCP\Calendar\Events\CalendarObjectRestoredEvent::class,
			\OCA\DAV\Events\CalendarCreatedEvent::class,
			\OCA\DAV\Events\CalendarUpdatedEvent::class,
			\OCA\DAV\Events\CalendarDeletedEvent::class,
			\OCA\DAV\Events\CalendarMovedToTrashEvent::class,
			\OCA\DAV\Events\CalendarRestoredEvent::class,
			\OCP\Calendar\Events\CalendarMovedToTrashEvent::class,
			\OCP\Calendar\Events\CalendarRestoredEvent::class,
			\OCA\DAV\Events\CalendarShareUpdatedEvent::class,
			\OCA\DAV\Events\CalendarPublishedEvent::class,
			\OCA\DAV\Events\CalendarUnpublishedEvent::class,
			\OCA\DAV\Events\CardCreatedEvent::class,
			\OCA\DAV\Events\CardUpdatedEvent::class,
			\OCA\DAV\Events\CardDeletedEvent::class,
			\OCA\DAV\Events\AddressBookCreatedEvent::class,
			\OCA\DAV\Events\AddressBookUpdatedEvent::class,
			\OCA\DAV\Events\AddressBookDeletedEvent::class,
			\OCA\DAV\Events\AddressBookShareUpdatedEvent::class,
		];
		foreach ($changeEvents as $changeEvent) {
			$context->registerEventListener($changeEvent, DavChangeListener::class);
		}
```

- [ ] **Step 5: Run test to verify it passes**

`SignalPublisher` does not exist yet, so create a minimal stub now — Task 11 fills it in.

Create `lib/Service/ChangeNotification/SignalPublisher.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

/**
 * Publishes one batched signal per window. Filled in by Task 11.
 */
class SignalPublisher {

	/** @return array<string, mixed>|null the signal that was published */
	public function flushIfDue(): ?array {
		return null;
	}
}
```

Run: `php vendor/bin/phpunit -c phpunit.xml --filter DavChangeListenerTest`
Expected: PASS, 9 tests

- [ ] **Step 6: Verify the app still boots with the listeners registered**

```bash
php occ app:disable sendentsynchroniser && php occ app:enable sendentsynchroniser
php occ status
```

Expected: exit code 0, no exception about an unresolvable listener.

- [ ] **Step 7: Commit**

```bash
git add lib/Listener/DavChangeListener.php lib/Service/ChangeNotification/SignalPublisher.php lib/AppInfo/Application.php tests/Unit/Listener/DavChangeListenerTest.php
git commit -m "feat(cn): record every CalDAV/CardDAV change event in the ledger"
```

---

### Task 10: The batch window

**Files:**
- Create: `lib/Service/ChangeNotification/BatchWindowService.php`
- Test: `tests/Unit/Service/ChangeNotification/BatchWindowServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Service/ChangeNotification/BatchWindowServiceTest.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\Service\ChangeNotification\BatchWindowService;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IMemcache;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BatchWindowServiceTest extends TestCase {

	/** @var ICacheFactory&MockObject */
	private $cacheFactory;

	/** @var IMemcache&MockObject */
	private $memcache;

	/** @var ChangeNotificationConfig&MockObject */
	private $config;

	/** @var IConfig&MockObject */
	private $serverConfig;

	protected function setUp(): void {
		parent::setUp();
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->memcache = $this->createMock(IMemcache::class);
		$this->config = $this->createMock(ChangeNotificationConfig::class);
		$this->config->method('batchWindow')->willReturn(2);
		$this->serverConfig = $this->createMock(IConfig::class);
	}

	private function service(bool $distributedConfigured = true, bool $cacheAvailable = true): BatchWindowService {
		$this->serverConfig->method('getSystemValue')->with('memcache.distributed', null)
			->willReturn($distributedConfigured ? '\\OC\\Memcache\\Redis' : null);
		$this->cacheFactory->method('isAvailable')->willReturn($cacheAvailable);
		$this->cacheFactory->method('createDistributed')->willReturn($this->memcache);

		return new BatchWindowService($this->cacheFactory, $this->serverConfig, $this->config);
	}

	public function testFirstCallerInAWindowWinsTheFlush(): void {
		$this->memcache->expects($this->once())
			->method('add')
			->with($this->anything(), 1, 2)
			->willReturn(true);

		$this->assertTrue($this->service()->tryOpenWindow());
	}

	public function testLaterCallersInTheSameWindowLose(): void {
		$this->memcache->method('add')->willReturn(false);

		$this->assertFalse($this->service()->tryOpenWindow());
	}

	public function testAZeroWindowAlwaysFlushes(): void {
		// window 0 = batching off: every event may flush; add() is never asked.
		$config = $this->createMock(ChangeNotificationConfig::class);
		$config->method('batchWindow')->willReturn(0);
		$this->serverConfig->method('getSystemValue')->willReturn('\\OC\\Memcache\\Redis');
		$this->cacheFactory->method('isAvailable')->willReturn(true);
		$this->cacheFactory->method('createDistributed')->willReturn($this->memcache);
		$this->memcache->expects($this->never())->method('add');

		$service = new BatchWindowService($this->cacheFactory, $this->serverConfig, $config);

		$this->assertTrue($service->tryOpenWindow());
	}

	public function testWithoutAConfiguredDistributedCacheTheWindowNeverOpens(): void {
		// No distributed cache = no notify_push either; polling reads the
		// ledger directly, so an in-request flush would only burn CPU. Also
		// covers APCu-only installs, where createDistributed() falls back to
		// a per-process local cache whose add() would elect one "winner" per
		// php-fpm worker instead of one per window.
		$this->assertFalse($this->service(false)->tryOpenWindow());
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter BatchWindowServiceTest`
Expected: FAIL — `Class "OCA\SendentSynchroniser\Service\ChangeNotification\BatchWindowService" does not exist`

- [ ] **Step 3: Write the service**

Create `lib/Service/ChangeNotification/BatchWindowService.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IMemcache;

/**
 * Leading-edge batch window in a single atomic cache operation.
 *
 * IMemcache::add() is set-if-absent with a TTL. The first event after a window
 * boundary creates the key (add returns true) and gets to flush immediately —
 * that is the sub-second latency for a quiet instance. Every other event in
 * the window fails the add and does nothing; their changes ride along in the
 * next event's flush after the window expires, or in the sweeper's (Task 12).
 *
 * No separate flush lock is needed: winning the add IS the lock for this
 * window. The trailing edge is deliberately loose — a burst followed by
 * silence waits for the sweeper, i.e. up to one cron interval — an accepted
 * worst case that the Connector's overlap reads and reconcile also bound.
 */
class BatchWindowService {

	private const KEY = 'window_open';
	private const CACHE_PREFIX = 'sndntsync_cn/';

	public function __construct(
		private ICacheFactory $cacheFactory,
		private IConfig $serverConfig,
		private ChangeNotificationConfig $config,
	) {}

	/** True when this request won the right to flush the current window. */
	public function tryOpenWindow(): bool {
		$window = $this->config->batchWindow();

		$cache = $this->memcache();
		if ($cache === null) {
			// No distributed cache means notify_push is unavailable too
			// (its IQueue would be a NullQueue). The ledger alone serves
			// polling readers, so flushing in-request would do nothing.
			return false;
		}

		if ($window === 0) {
			return true;
		}

		return $cache->add(self::KEY, 1, $window);
	}

	private function memcache(): ?IMemcache {
		// Same guard as CursorService: createDistributed() falls back to the
		// LOCAL cache when memcache.distributed is not configured, and a
		// per-process window key would elect one flusher per php-fpm worker.
		if ($this->serverConfig->getSystemValue('memcache.distributed', null) === null) {
			return null;
		}
		if (!$this->cacheFactory->isAvailable()) {
			return null;
		}

		$cache = $this->cacheFactory->createDistributed(self::CACHE_PREFIX);

		return $cache instanceof IMemcache ? $cache : null;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter BatchWindowServiceTest`
Expected: PASS, 4 tests

- [ ] **Step 5: Commit**

```bash
git add lib/Service/ChangeNotification/BatchWindowService.php tests/Unit/Service/ChangeNotification/BatchWindowServiceTest.php
git commit -m "feat(cn): add leading-edge batch window via atomic cache add"
```

---

### Task 11: Signal builder, notify_push availability, transport, and the real publisher

This is the flush path assembled: read ledger rows above the watermark, build the payload, push it through notify_push, advance the watermark.

**Files:**
- Create: `lib/Service/ChangeNotification/SignalBuilder.php`
- Create: `lib/Service/ChangeNotification/NotifyPushAvailability.php`
- Create: `lib/Service/ChangeNotification/NotifyPushTransport.php`
- Modify: `lib/Service/ChangeNotification/SignalPublisher.php` (replace the Task 9 stub)
- Test: `tests/Unit/Service/ChangeNotification/SignalBuilderTest.php`
- Test: `tests/Unit/Service/ChangeNotification/SignalPublisherTest.php`

- [ ] **Step 1: Write the failing SignalBuilder test**

Create `tests/Unit/Service/ChangeNotification/SignalBuilderTest.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Service\ChangeNotification\SignalBuilder;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SignalBuilderTest extends TestCase {

	/** @var IConfig&MockObject */
	private $config;

	private SignalBuilder $builder;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getSystemValueString')->with('instanceid')->willReturn('f1a2instance');
		$this->builder = new SignalBuilder($this->config);
	}

	public function testSignalCarriesVersionInstancePrevCursorAndRefs(): void {
		$refs = [
			new CollectionReference('principals/users/alice', 'caldav', 'personal', 9651, false),
			new CollectionReference('principals/users/bob', 'caldav', 'team-x', 77, true),
		];

		$signal = $this->builder->build(1849200, 1849233, $refs, false);

		$this->assertSame(1, $signal['v']);
		$this->assertSame('f1a2instance', $signal['instance']);
		$this->assertSame(1849200, $signal['prev']);
		$this->assertSame(1849233, $signal['cursor']);
		$this->assertFalse($signal['truncated']);
		$this->assertSame(
			['p' => 'principals/users/alice', 't' => 'caldav', 'u' => 'personal', 's' => 9651, 'c' => false],
			$signal['refs'][0]
		);
		$this->assertTrue($signal['refs'][1]['c']);
	}

	public function testTruncatedSignalOmitsRefs(): void {
		$refs = [new CollectionReference('principals/users/alice', 'caldav', 'personal', 1, false)];

		$signal = $this->builder->build(0, 500, $refs, true);

		$this->assertTrue($signal['truncated']);
		$this->assertSame([], $signal['refs']);
	}

	public function testSignalIsJsonSerializable(): void {
		$signal = $this->builder->build(0, 1, [
			new CollectionReference('principals/users/alice', 'caldav', 'personal', 1, false),
		], false);

		$json = json_encode($signal);

		$this->assertIsString($json);
		$this->assertStringContainsString('"p":"principals\/users\/alice"', $json);
	}
}
```

- [ ] **Step 2: Write the failing SignalPublisher test**

Create `tests/Unit/Service/ChangeNotification/SignalPublisherTest.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\Db\DirtyCollection;
use OCA\SendentSynchroniser\Service\ChangeNotification\BatchWindowService;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushTransport;
use OCA\SendentSynchroniser\Service\ChangeNotification\SignalBuilder;
use OCA\SendentSynchroniser\Service\ChangeNotification\SignalPublisher;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SignalPublisherTest extends TestCase {

	/** @var BatchWindowService&MockObject */
	private $window;

	/** @var ChangeLedgerService&MockObject */
	private $ledger;

	/** @var NotifyPushTransport&MockObject */
	private $transport;

	/** @var ChangeNotificationConfig&MockObject */
	private $config;

	private SignalPublisher $publisher;

	protected function setUp(): void {
		parent::setUp();
		$this->window = $this->createMock(BatchWindowService::class);
		$this->ledger = $this->createMock(ChangeLedgerService::class);
		// highestSeqOf is a pure function of its argument; stub it to mirror the
		// real implementation, otherwise the mock's int default (0) would make
		// every cursor assertion fail.
		$this->ledger->method('highestSeqOf')->willReturnCallback(
			static fn (array $rows): int => array_reduce(
				$rows,
				static fn (int $carry, DirtyCollection $row): int => max($carry, (int)$row->getChangeSeq()),
				0
			)
		);
		$this->transport = $this->createMock(NotifyPushTransport::class);
		$this->config = $this->createMock(ChangeNotificationConfig::class);
		$this->config->method('maxRefsPerSignal')->willReturn(3);

		$serverConfig = $this->createMock(IConfig::class);
		$serverConfig->method('getSystemValueString')->willReturn('inst');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1755676800);

		$this->publisher = new SignalPublisher(
			$this->window,
			$this->ledger,
			new SignalBuilder($serverConfig),
			$this->transport,
			$this->config,
			$time,
			new NullLogger(),
		);
	}

	private function row(string $uri, int $seq): DirtyCollection {
		$row = new DirtyCollection();
		$row->setPrincipalUri('principals/users/alice');
		$row->setCollectionType('caldav');
		$row->setCollectionUri($uri);
		$row->setSyncToken(1);
		$row->setChangeSeq($seq);
		$row->setStructuralSeq(0);
		$row->setUpdatedAt(1755676800);
		return $row;
	}

	public function testFlushIfDueDoesNothingWhenTheWindowIsClosed(): void {
		$this->window->method('tryOpenWindow')->willReturn(false);
		$this->transport->expects($this->never())->method('publish');

		$this->assertNull($this->publisher->flushIfDue());
	}

	public function testFlushPublishesEverythingAboveTheWatermarkAndAdvancesIt(): void {
		$this->window->method('tryOpenWindow')->willReturn(true);
		$this->config->method('flushedSeq')->willReturn(500);
		$this->ledger->method('rows')->with(500, 4)->willReturn([
			$this->row('personal', 501),
			$this->row('work', 502),
		]);
		$this->transport->method('publish')->willReturn(true);
		$this->config->expects($this->once())->method('setFlushedSeq')->with(502);

		$signal = $this->publisher->flushIfDue();

		$this->assertNotNull($signal);
		$this->assertSame(500, $signal['prev']);
		$this->assertSame(502, $signal['cursor']);
		$this->assertCount(2, $signal['refs']);
		$this->assertFalse($signal['truncated']);
	}

	public function testFlushWithNothingNewPublishesNothing(): void {
		$this->window->method('tryOpenWindow')->willReturn(true);
		$this->config->method('flushedSeq')->willReturn(500);
		$this->ledger->method('rows')->willReturn([]);
		$this->transport->expects($this->never())->method('publish');
		$this->config->expects($this->never())->method('setFlushedSeq');

		$this->assertNull($this->publisher->flushIfDue());
	}

	public function testAnOverfullBatchIsPublishedTruncated(): void {
		// maxRefsPerSignal is 3; the publisher asks for 4 rows and gets 4,
		// so the signal switches to "go read /changes" form. The watermark
		// still only advances to the last ref the reader can learn about
		// from the feed — which is fine, refs are not in the signal at all,
		// and the cursor points at the ledger's true position.
		$this->window->method('tryOpenWindow')->willReturn(true);
		$this->config->method('flushedSeq')->willReturn(0);
		$this->ledger->method('rows')->with(0, 4)->willReturn([
			$this->row('a', 1),
			$this->row('b', 2),
			$this->row('c', 3),
			$this->row('d', 4),
		]);
		$this->transport->method('publish')->willReturn(true);
		$this->config->expects($this->once())->method('setFlushedSeq')->with(4);

		$signal = $this->publisher->flushIfDue();

		$this->assertTrue($signal['truncated']);
		$this->assertSame([], $signal['refs']);
		$this->assertSame(4, $signal['cursor']);
	}

	public function testTheWatermarkDoesNotAdvanceWhenPublishFails(): void {
		// A failed publish leaves the refs above the watermark so the sweeper
		// republishes them. Polling readers never notice either way.
		$this->window->method('tryOpenWindow')->willReturn(true);
		$this->config->method('flushedSeq')->willReturn(500);
		$this->ledger->method('rows')->willReturn([$this->row('personal', 501)]);
		$this->transport->method('publish')->willReturn(false);
		$this->config->expects($this->never())->method('setFlushedSeq');

		$this->assertNull($this->publisher->flushIfDue());
	}

	public function testForcedFlushSkipsTheWindow(): void {
		$this->window->expects($this->never())->method('tryOpenWindow');
		$this->config->method('flushedSeq')->willReturn(500);
		$this->ledger->method('rows')->willReturn([$this->row('personal', 501)]);
		$this->transport->method('publish')->willReturn(true);

		$this->assertNotNull($this->publisher->flush());
	}
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter 'SignalBuilderTest|SignalPublisherTest'`
Expected: FAIL — `Class ... SignalBuilder does not exist`

- [ ] **Step 4: Write the SignalBuilder**

Create `lib/Service/ChangeNotification/SignalBuilder.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Constants;
use OCP\IConfig;

/**
 * Assembles the one wire payload used by the websocket body, the /changes
 * response and the optional webhook. Keeping a single builder is what
 * guarantees the Connector can treat all three identically.
 */
class SignalBuilder {

	public function __construct(
		private IConfig $config,
	) {}

	/**
	 * @param int $prev the feed position BEFORE this signal (the watermark the
	 *                  flush started from). Sequence numbers count events while
	 *                  refs are deduped per collection, so `cursor - len(refs)`
	 *                  tells a reader nothing; `prev` makes gap detection exact:
	 *                  a reader whose last_cursor < prev has missed a frame.
	 * @param CollectionReference[] $refs
	 * @return array{v: int, instance: string, prev: int, cursor: int, truncated: bool, refs: array<int, array{p: string, t: string, u: string, s: int, c: bool}>}
	 */
	public function build(int $prev, int $cursor, array $refs, bool $truncated): array {
		return [
			'v' => Constants::CN_SIGNAL_VERSION,
			'instance' => $this->config->getSystemValueString('instanceid'),
			'prev' => $prev,
			'cursor' => $cursor,
			// A truncated signal deliberately carries no refs: past the cap it
			// is cheaper for the Connector to page /changes than to parse a
			// giant frame, and the ledger is the durable source anyway.
			'truncated' => $truncated,
			'refs' => $truncated
				? []
				: array_map(static fn (CollectionReference $r): array => $r->jsonSerialize(), $refs),
		];
	}
}
```

- [ ] **Step 5: Write NotifyPushAvailability**

Create `lib/Service/ChangeNotification/NotifyPushAvailability.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\Constants;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Container\ContainerInterface;

/**
 * Decides whether transport A (notify_push) is usable, and resolves its queue.
 *
 * The checks, in order of cost:
 *  1. notify_push app enabled.
 *  2. Its IQueue resolves to a real queue — NullQueue means Redis is not
 *     configured as the distributed cache and messages would go nowhere.
 *  3. The daemon answers its /test/cookie endpoint (cached for
 *     CN_DAEMON_CHECK_TTL so the DAV write path never blocks on HTTP).
 *
 * The browser round-trip test from the admin settings is informational only
 * and does not gate isActive() — see the plan's deviation 7.
 *
 * notify_push is an optional dependency: every reference to its classes is by
 * string through the container, inside try/catch.
 */
class NotifyPushAvailability {

	private const IQUEUE_CLASS = 'OCA\\NotifyPush\\Queue\\IQueue';
	private const NULL_QUEUE_CLASS = 'OCA\\NotifyPush\\Queue\\NullQueue';

	public function __construct(
		private IAppManager $appManager,
		private ContainerInterface $container,
		private IClientService $clientService,
		private IConfig $serverConfig,
		private ChangeNotificationConfig $config,
		private ITimeFactory $time,
	) {}

	public function isAppEnabled(): bool {
		return $this->appManager->isInstalled(Constants::CN_NOTIFY_PUSH_APPID);
	}

	/** The resolved queue, or null when notify_push is absent or queue-less. */
	public function queue(): ?object {
		if (!$this->isAppEnabled()) {
			return null;
		}

		try {
			$queue = $this->container->get(self::IQUEUE_CLASS);
		} catch (\Throwable $e) {
			return null;
		}

		if (is_a($queue, self::NULL_QUEUE_CLASS)) {
			return null;
		}

		return $queue;
	}

	/**
	 * Cheap enough for the write path: reads only cached state.
	 * The daemon probe is refreshed out-of-band (settings page, TimedJob).
	 */
	public function isActive(): bool {
		$mode = $this->config->transportMode();
		if ($mode === Constants::TRANSPORT_POLLING) {
			return false;
		}

		$queue = $this->queue();

		// Force-notify_push publishes even while unhealthy: the admin pinned
		// it, the settings page shows the persistent warning, and the ledger
		// still serves catch-up either way.
		if ($mode === Constants::TRANSPORT_NOTIFY_PUSH) {
			return $queue !== null;
		}

		return $queue !== null && $this->cachedDaemonCheck()['ok'];
	}

	/** The transport /config advertises to the Connector. */
	public function effectiveTransport(): string {
		return $this->isActive() ? Constants::TRANSPORT_NOTIFY_PUSH : Constants::TRANSPORT_POLLING;
	}

	/**
	 * The stored probe result, however stale. Stale is still usable — better a
	 * possibly-outdated push attempt (harmless: the ledger catches up) than an
	 * HTTP probe per DAV write. The TimedJob and the settings page refresh it.
	 *
	 * @return array{ok: bool, at: int, message: string}
	 */
	public function cachedDaemonCheck(): array {
		return $this->config->daemonCheck();
	}

	/**
	 * Probes the daemon's HTTP test endpoint and caches the outcome.
	 * Called from the settings controller and the self-test TimedJob — never
	 * from the DAV write path.
	 */
	public function refreshDaemonCheck(): array {
		$now = $this->time->getTime();

		$base = $this->baseEndpoint();
		if ($base === null) {
			$result = ['ok' => false, 'at' => $now, 'message' => 'notify_push app or its endpoint not available'];
			$this->config->setDaemonCheck(false, $now, $result['message']);
			return $result;
		}

		try {
			$client = $this->clientService->newClient();
			$response = $client->get($base . '/test/cookie', ['timeout' => 5, 'nextcloud' => ['allow_local_address' => true]]);
			$ok = $response->getStatusCode() === 200;
			$message = $ok ? 'daemon reachable' : ('unexpected status ' . $response->getStatusCode());
		} catch (\Throwable $e) {
			$ok = false;
			$message = 'daemon unreachable: ' . substr($e->getMessage(), 0, 500);
		}

		$this->config->setDaemonCheck($ok, $now, $message);

		return ['ok' => $ok, 'at' => $now, 'message' => $message];
	}

	/** wss:// URL the Connector should open, advertised via /config. */
	public function websocketUrl(): ?string {
		$base = $this->baseEndpoint();
		if ($base === null) {
			return null;
		}
		if (!str_starts_with($base, 'http')) {
			return null; // malformed base_endpoint; better no ws_url than a nonsensical one
		}

		return preg_replace('/^http/', 'ws', $base) . '/ws';
	}

	private function baseEndpoint(): ?string {
		if (!$this->isAppEnabled()) {
			return null;
		}

		// notify_push stores its reachable base endpoint in its own app config
		// during `occ notify_push:setup`.
		$endpoint = $this->serverConfig->getAppValue(Constants::CN_NOTIFY_PUSH_APPID, 'base_endpoint', '');

		return $endpoint !== '' ? rtrim($endpoint, '/') : null;
	}
}
```

Note for the implementer: `IConfig::getAppValue` is deprecated on newer NC but present across 28–34; it is the only public way to read *another* app's config value here.

- [ ] **Step 6: Write NotifyPushTransport**

Create `lib/Service/ChangeNotification/NotifyPushTransport.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\Constants;
use Psr\Log\LoggerInterface;

/**
 * Publishes one signal as a notify_push custom message addressed to the bot
 * account. The 'user' field is the RECIPIENT (whose websockets get the frame);
 * the changed users ride inside body.refs. Wire format on the socket:
 * `sendent_sync {json}`.
 *
 * Fire-and-forget by design: false is "not delivered to the queue", and the
 * callers leave the watermark untouched so the refs surface again.
 */
class NotifyPushTransport {

	public function __construct(
		private NotifyPushAvailability $availability,
		private ChangeNotificationConfig $config,
		private LoggerInterface $logger,
	) {}

	/** @param array<string, mixed> $signal */
	public function publish(array $signal): bool {
		return $this->push(Constants::CN_MESSAGE_NAME, $signal);
	}

	/** Settings-page round-trip probe. */
	public function publishPing(array $body): bool {
		return $this->push(Constants::CN_PING_MESSAGE_NAME, $body);
	}

	/** @param array<string, mixed> $body */
	private function push(string $message, array $body): bool {
		$botUser = $this->config->botUser();
		if ($botUser === '') {
			return false;
		}

		$queue = $this->availability->queue();
		if ($queue === null) {
			return false;
		}

		try {
			$queue->push('notify_custom', [
				'user' => $botUser,
				'message' => $message,
				'body' => $body,
			]);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('notify_push publish failed: ' . $e->getMessage(), [
				'exception' => $e,
				'app' => 'sendentsynchroniser',
			]);
			return false;
		}
	}
}
```

- [ ] **Step 7: Replace the SignalPublisher stub**

Replace the entire content of `lib/Service/ChangeNotification/SignalPublisher.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * The flush: everything in the ledger above the flushed watermark becomes one
 * signal. Under the cap the refs travel inline; over it the signal degrades to
 * `truncated: true` and the Connector pages /changes — under extreme load the
 * system deliberately shifts from push-refs to read-the-ledger, the cheaper
 * path.
 *
 * The watermark (cnFlushedSeq) only advances after a successful publish, so a
 * dropped publish means the same refs ride the next flush or the sweeper's.
 * Duplicate delivery is free by design; missed delivery is what costs.
 */
class SignalPublisher {

	public function __construct(
		private BatchWindowService $window,
		private ChangeLedgerService $ledger,
		private SignalBuilder $builder,
		private NotifyPushTransport $transport,
		private ChangeNotificationConfig $config,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {}

	/**
	 * In-request path: flush only when this request wins the batch window.
	 *
	 * @return array<string, mixed>|null the published signal
	 */
	public function flushIfDue(): ?array {
		if (!$this->window->tryOpenWindow()) {
			return null;
		}

		return $this->flush();
	}

	/**
	 * Unconditional flush — sweeper, occ command, admin "flush now".
	 *
	 * @return array<string, mixed>|null the published signal
	 */
	public function flush(): ?array {
		$since = $this->config->flushedSeq();
		$max = $this->config->maxRefsPerSignal();

		// One row past the cap tells us whether to truncate.
		$rows = $this->ledger->rows($since, $max + 1);
		if ($rows === []) {
			return null;
		}

		$truncated = count($rows) > $max;
		if ($truncated) {
			$this->logger->debug('Signal truncated at ' . $max . ' refs; the Connector will page /changes', [
				'app' => 'sendentsynchroniser',
			]);
		}
		$cursor = $this->ledger->highestSeqOf($rows);
		$refs = $truncated
			? []
			: array_map(static fn ($row) => $row->toReference($since), $rows);

		$signal = $this->builder->build($since, $cursor, $refs, $truncated);

		if (!$this->transport->publish($signal)) {
			return null;
		}

		$this->config->setFlushedSeq($cursor);
		$this->config->setLastSignalAt($this->time->getTime());

		return $signal;
	}
}
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter 'SignalBuilderTest|SignalPublisherTest'`
Expected: PASS, 9 tests

- [ ] **Step 9: Run the whole suite**

Run: `php vendor/bin/phpunit -c phpunit.xml`
Expected: PASS, no regressions (existing trashbin/scheduling tests still green)

- [ ] **Step 10: Commit**

```bash
git add lib/Service/ChangeNotification/SignalBuilder.php lib/Service/ChangeNotification/NotifyPushAvailability.php lib/Service/ChangeNotification/NotifyPushTransport.php lib/Service/ChangeNotification/SignalPublisher.php tests/Unit/Service/ChangeNotification/SignalBuilderTest.php tests/Unit/Service/ChangeNotification/SignalPublisherTest.php
git commit -m "feat(cn): publish batched signals over notify_push custom messages"
```

---

### Task 12: The flush sweeper TimedJob

**Files:**
- Create: `lib/Cron/FlushSignalBatch.php`
- Modify: `appinfo/info.xml`
- Test: `tests/Unit/Cron/FlushSignalBatchTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Cron/FlushSignalBatchTest.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Cron;

use OCA\SendentSynchroniser\Cron\FlushSignalBatch;
use OCA\SendentSynchroniser\Db\SequenceMapper;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\SignalPublisher;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class FlushSignalBatchTest extends TestCase {

	/** @var SignalPublisher&MockObject */
	private $publisher;

	/** @var SequenceMapper&MockObject */
	private $sequence;

	/** @var ChangeNotificationConfig&MockObject */
	private $config;

	private FlushSignalBatch $job;

	protected function setUp(): void {
		parent::setUp();
		$this->publisher = $this->createMock(SignalPublisher::class);
		$this->sequence = $this->createMock(SequenceMapper::class);
		$this->config = $this->createMock(ChangeNotificationConfig::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1755676800);

		$this->job = new FlushSignalBatch($time, $this->publisher, $this->sequence, $this->config, new NullLogger());
	}

	public function testRunFlushesAndPrunesTheSequenceTable(): void {
		$this->config->method('botUser')->willReturn('sendent-sync');
		$this->publisher->expects($this->once())->method('flush');
		$this->sequence->expects($this->once())->method('prune');

		$this->job->start($this->createMock(\OCP\BackgroundJob\IJobList::class));
	}

	public function testAFailingFlushStillPrunes(): void {
		$this->config->method('botUser')->willReturn('sendent-sync');
		$this->publisher->method('flush')->willThrowException(new \RuntimeException('redis down'));
		$this->sequence->expects($this->once())->method('prune');

		$this->job->start($this->createMock(\OCP\BackgroundJob\IJobList::class));
	}

	public function testAnUnconfiguredInstanceSkipsTheFlushButStillPrunes(): void {
		// No bot user and no webhook = no signal channel at all. flush() would
		// read rows, fail to publish, never advance the watermark — and repeat
		// every cron run forever. Skip it; polling readers use the ledger
		// directly and lose nothing.
		$this->config->method('botUser')->willReturn('');
		$this->config->method('webhookEnabled')->willReturn(false);
		$this->publisher->expects($this->never())->method('flush');
		$this->sequence->expects($this->once())->method('prune');

		$this->job->start($this->createMock(\OCP\BackgroundJob\IJobList::class));
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter FlushSignalBatchTest`
Expected: FAIL — `Class "OCA\SendentSynchroniser\Cron\FlushSignalBatch" does not exist`

- [ ] **Step 3: Write the job**

Create `lib/Cron/FlushSignalBatch.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Cron;

use OCA\SendentSynchroniser\Db\SequenceMapper;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\SignalPublisher;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Sweeper behind the leading-edge batch window: when traffic stops, whatever
 * accumulated after the last in-request flush would sit unpublished forever.
 * This job publishes it within one cron interval — about a minute with
 * webcron/AJAX cron, and commonly five minutes with the recommended system
 * cron, which is the real trailing-latency bound (see plan deviation 2).
 *
 * Also the housekeeping hook for the DB sequence table.
 */
class FlushSignalBatch extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private SignalPublisher $publisher,
		private SequenceMapper $sequence,
		private ChangeNotificationConfig $config,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);

		// Every cron run; flush() is a no-op when nothing is pending.
		$this->setInterval(60);
	}

	protected function run($arguments): void {
		// With no bot user and no webhook there is no signal channel: flush()
		// would read rows, fail to publish, never advance the watermark, and
		// repeat forever. Polling readers use the ledger directly, so skip.
		if ($this->config->botUser() !== '' || $this->config->webhookEnabled()) {
			try {
				$this->publisher->flush();
			} catch (\Throwable $e) {
				$this->logger->error('Sweeper flush failed: ' . $e->getMessage(), [
					'exception' => $e,
					'app' => 'sendentsynchroniser',
				]);
			}
		}

		try {
			$this->sequence->prune();
		} catch (\Throwable $e) {
			$this->logger->error('Sequence prune failed: ' . $e->getMessage(), [
				'exception' => $e,
				'app' => 'sendentsynchroniser',
			]);
		}
	}
}
```

- [ ] **Step 4: Register the job**

In `appinfo/info.xml`, inside `<background-jobs>`, add:

```xml
        <job>OCA\SendentSynchroniser\Cron\FlushSignalBatch</job>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter FlushSignalBatchTest`
Expected: PASS, 3 tests

- [ ] **Step 6: Commit**

```bash
git add lib/Cron/FlushSignalBatch.php appinfo/info.xml tests/Unit/Cron/FlushSignalBatchTest.php
git commit -m "feat(cn): sweep unpublished batches every cron run"
```

---

### Task 13: The change-feed guard

**Files:**
- Create: `lib/Service/ChangeNotification/ChangeFeedGuard.php`
- Test: `tests/Unit/Service/ChangeNotification/ChangeFeedGuardTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Service/ChangeNotification/ChangeFeedGuardTest.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeFeedGuard;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ChangeFeedGuardTest extends TestCase {

	/** @var IUserSession&MockObject */
	private $userSession;

	/** @var IGroupManager&MockObject */
	private $groupManager;

	/** @var ChangeNotificationConfig&MockObject */
	private $config;

	private ChangeFeedGuard $guard;

	protected function setUp(): void {
		parent::setUp();
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->config = $this->createMock(ChangeNotificationConfig::class);
		$this->guard = new ChangeFeedGuard($this->userSession, $this->groupManager, $this->config);
	}

	private function loginAs(?string $uid): void {
		if ($uid === null) {
			$this->userSession->method('getUser')->willReturn(null);
			return;
		}
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testTheBotUserIsAllowed(): void {
		$this->config->method('botUser')->willReturn('sendent-sync');
		$this->loginAs('sendent-sync');

		$this->assertTrue($this->guard->isAllowed());
	}

	public function testAnAdminIsAllowed(): void {
		$this->config->method('botUser')->willReturn('sendent-sync');
		$this->loginAs('root');
		$this->groupManager->method('isAdmin')->with('root')->willReturn(true);

		$this->assertTrue($this->guard->isAllowed());
	}

	public function testAnyOtherUserIsRejected(): void {
		$this->config->method('botUser')->willReturn('sendent-sync');
		$this->loginAs('mallory');
		$this->groupManager->method('isAdmin')->willReturn(false);

		$this->assertFalse($this->guard->isAllowed());
	}

	public function testAnonymousIsRejected(): void {
		$this->loginAs(null);

		$this->assertFalse($this->guard->isAllowed());
	}

	public function testNobodyMatchesAnUnconfiguredBotUser(): void {
		// Empty botUser must not mean "everyone whose uid is empty matches".
		$this->config->method('botUser')->willReturn('');
		$this->loginAs('mallory');
		$this->groupManager->method('isAdmin')->willReturn(false);

		$this->assertFalse($this->guard->isAllowed());
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter ChangeFeedGuardTest`
Expected: FAIL — `Class "OCA\SendentSynchroniser\Service\ChangeNotification\ChangeFeedGuard" does not exist`

- [ ] **Step 3: Write the guard**

Create `lib/Service/ChangeNotification/ChangeFeedGuard.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The change feed exposes every principal's collection URIs, so it is limited
 * to the identities that already see everything: the configured bot account
 * (the Connector's app password) and admins (for diagnostics via curl).
 */
class ChangeFeedGuard {

	public function __construct(
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private ChangeNotificationConfig $config,
	) {}

	public function isAllowed(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		$bot = $this->config->botUser();
		if ($bot !== '' && $user->getUID() === $bot) {
			return true;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter ChangeFeedGuardTest`
Expected: PASS, 5 tests

- [ ] **Step 5: Commit**

```bash
git add lib/Service/ChangeNotification/ChangeFeedGuard.php tests/Unit/Service/ChangeNotification/ChangeFeedGuardTest.php
git commit -m "feat(cn): restrict the change feed to the bot account and admins"
```

---

### Task 14: The change-feed API controller and routes

**Files:**
- Create: `lib/Controller/ChangeFeedApiController.php`
- Modify: `appinfo/routes.php`
- Test: `tests/Unit/Controller/ChangeFeedApiControllerTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Controller/ChangeFeedApiControllerTest.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Controller;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Controller\ChangeFeedApiController;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeFeedGuard;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\CursorService;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushAvailability;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ChangeFeedApiControllerTest extends TestCase {

	/** @var ChangeFeedGuard&MockObject */
	private $guard;

	/** @var ChangeLedgerService&MockObject */
	private $ledger;

	/** @var ChangeNotificationConfig&MockObject */
	private $config;

	/** @var CursorService&MockObject */
	private $cursor;

	/** @var NotifyPushAvailability&MockObject */
	private $availability;

	private ChangeFeedApiController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->guard = $this->createMock(ChangeFeedGuard::class);
		$this->ledger = $this->createMock(ChangeLedgerService::class);
		$this->config = $this->createMock(ChangeNotificationConfig::class);
		$this->cursor = $this->createMock(CursorService::class);
		$this->availability = $this->createMock(NotifyPushAvailability::class);

		$serverConfig = $this->createMock(IConfig::class);
		$serverConfig->method('getSystemValueString')->with('instanceid')->willReturn('inst');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1755676800);

		$appManager = $this->createMock(\OCP\App\IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('2.1.0');

		$this->controller = new ChangeFeedApiController(
			'sendentsynchroniser',
			$this->createMock(IRequest::class),
			$this->guard,
			$this->ledger,
			$this->config,
			$this->cursor,
			$this->availability,
			$serverConfig,
			$time,
			$appManager,
		);
	}

	private function allow(): void {
		$this->guard->method('isAllowed')->willReturn(true);
	}

	public function testEveryEndpointRejectsNonBotNonAdminWith403(): void {
		$this->guard->method('isAllowed')->willReturn(false);

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->config()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->changes(0, 10)->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->ack(1)->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->health()->getStatus());
	}

	public function testConfigDescribesTheTransportContract(): void {
		$this->allow();
		$this->availability->method('effectiveTransport')->willReturn('notify_push');
		$this->availability->method('websocketUrl')->willReturn('wss://cloud.example.com/push/ws');
		$this->config->method('pollInterval')->willReturn(30);
		$this->config->method('batchWindow')->willReturn(2);
		$this->config->method('rereadOverlap')->willReturn(100);
		$this->config->method('botUser')->willReturn('sendent-sync');
		$this->cursor->method('current')->willReturn(1849233);

		$data = $this->controller->config()->getData();

		$this->assertSame('notify_push', $data['transport']);
		$this->assertSame('wss://cloud.example.com/push/ws', $data['ws_url']);
		$this->assertSame(30, $data['poll_interval']);
		$this->assertSame(2, $data['batch_window']);
		$this->assertSame(100, $data['reread_overlap']);
		$this->assertSame(1849233, $data['cursor']);
		$this->assertSame('sendent-sync', $data['bot_user']);
		$this->assertSame('inst', $data['instance']);
		$this->assertSame('sendent_sync', $data['message_name']);
	}

	public function testChangesReturnsAPageWithCursorAndHasMore(): void {
		$this->allow();
		$row = new \OCA\SendentSynchroniser\Db\DirtyCollection();
		$row->setPrincipalUri('principals/users/alice');
		$row->setCollectionType('caldav');
		$row->setCollectionUri('personal');
		$row->setSyncToken(9651);
		$row->setChangeSeq(600);
		$row->setStructuralSeq(0);
		$row->setUpdatedAt(1);

		// limit 1 requested; two rows fetched (limit+1) signals has_more.
		$second = clone $row;
		$second->setChangeSeq(601);
		$this->ledger->method('rows')->with(500, 2)->willReturn([$row, $second]);

		$data = $this->controller->changes(500, 1)->getData();

		$this->assertSame(1, $data['v']);
		$this->assertSame('inst', $data['instance']);
		$this->assertCount(1, $data['refs']);
		$this->assertSame(600, $data['cursor']);
		$this->assertTrue($data['has_more']);
		$this->assertSame('personal', $data['refs'][0]['u']);
	}

	public function testChangesOnAnEmptyLedgerEchoesSince(): void {
		$this->allow();
		$this->ledger->method('rows')->willReturn([]);

		$data = $this->controller->changes(1849233, 100)->getData();

		$this->assertSame([], $data['refs']);
		$this->assertSame(1849233, $data['cursor']);
		$this->assertFalse($data['has_more']);
	}

	public function testChangesClampsTheLimit(): void {
		$this->allow();
		// limit above the hard cap fetches CN_CHANGES_LIMIT_MAX + 1
		$this->ledger->expects($this->once())->method('rows')->with(0, 1001)->willReturn([]);

		$this->controller->changes(0, 999999);
	}

	public function testChangesRejectsNegativeSince(): void {
		$this->allow();
		$this->ledger->expects($this->once())->method('rows')->with(0, 501)->willReturn([]);

		$this->controller->changes(-5, 500);
	}

	public function testAckStoresCursorAndTimestamp(): void {
		$this->allow();
		$this->config->expects($this->once())->method('setAck')->with(1849190, 1755676800);

		$response = $this->controller->ack(1849190);

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
	}

	public function testHealthReportsTransportAndLag(): void {
		$this->allow();
		$this->availability->method('effectiveTransport')->willReturn('polling');
		$this->availability->method('cachedDaemonCheck')->willReturn(['ok' => false, 'at' => 0, 'message' => 'x']);
		$this->config->method('lastSignalAt')->willReturn(1755676700);
		$this->config->method('ackCursor')->willReturn(1849190);
		$this->config->method('ackAt')->willReturn(1755676798);
		$this->cursor->method('current')->willReturn(1849233);
		$this->ledger->method('countCollections')->willReturn(1240118);

		$data = $this->controller->health()->getData();

		$this->assertSame('polling', $data['transport']);
		$this->assertFalse($data['notify_push_ok']);
		$this->assertSame(1755676700, $data['last_signal_at']);
		$this->assertSame(1240118, $data['ledger_rows']);
		$this->assertSame(43, $data['connector_lag']);
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter ChangeFeedApiControllerTest`
Expected: FAIL — `Class "OCA\SendentSynchroniser\Controller\ChangeFeedApiController" does not exist`

- [ ] **Step 3: Write the controller**

Create `lib/Controller/ChangeFeedApiController.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Controller;

use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeFeedGuard;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\CursorService;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushAvailability;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IRequest;

/**
 * Transport B, and every transport's catch-up path: the Connector reads the
 * ledger through these endpoints with the bot account's app password (Basic
 * auth). Same payload schema as the websocket signal, so one Connector code
 * path consumes both.
 */
class ChangeFeedApiController extends ApiController {

	public function __construct(
		string $appName,
		IRequest $request,
		private ChangeFeedGuard $guard,
		private ChangeLedgerService $ledger,
		private ChangeNotificationConfig $config,
		private CursorService $cursor,
		private NotifyPushAvailability $availability,
		private IConfig $serverConfig,
		private ITimeFactory $time,
		private \OCP\App\IAppManager $appManager,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Transport negotiation. The Connector calls this at startup and never
	 * has to guess which transport the instance supports.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function config(): DataResponse {
		if (!$this->guard->isAllowed()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse([
			'transport' => $this->availability->effectiveTransport(),
			'ws_url' => $this->availability->websocketUrl(),
			'message_name' => Constants::CN_MESSAGE_NAME,
			'poll_interval' => $this->config->pollInterval(),
			'batch_window' => $this->config->batchWindow(),
			'reread_overlap' => $this->config->rereadOverlap(),
			'cursor' => $this->cursor->current(),
			'bot_user' => $this->config->botUser(),
			'instance' => $this->serverConfig->getSystemValueString('instanceid'),
			'app_version' => $this->appManager->getAppVersion('sendentsynchroniser'),
		]);
	}

	/**
	 * One page of the ledger above `since`. A single indexed range scan; the
	 * bounded table keeps this cheap at any user count.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function changes(int $since = 0, int $limit = Constants::CN_CHANGES_LIMIT_DEFAULT): DataResponse {
		if (!$this->guard->isAllowed()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$since = max(0, $since);
		$limit = max(1, min(Constants::CN_CHANGES_LIMIT_MAX, $limit));

		// Fetch one row past the page to compute has_more without a COUNT.
		$rows = $this->ledger->rows($since, $limit + 1);
		$hasMore = count($rows) > $limit;
		if ($hasMore) {
			array_pop($rows);
		}

		$cursor = $since;
		$refs = [];
		foreach ($rows as $row) {
			$refs[] = $row->toReference($since)->jsonSerialize();
			$cursor = max($cursor, (int)$row->getChangeSeq());
		}

		return new DataResponse([
			'v' => Constants::CN_SIGNAL_VERSION,
			'instance' => $this->serverConfig->getSystemValueString('instanceid'),
			'cursor' => $cursor,
			'refs' => $refs,
			'has_more' => $hasMore,
		]);
	}

	/**
	 * Optional: the Connector reports how far it has read, so the admin
	 * settings can show lag. Ignoring this endpoint costs nothing.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function ack(int $cursor = 0): DataResponse {
		if (!$this->guard->isAllowed()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$this->config->setAck(max(0, $cursor), $this->time->getTime());

		return new DataResponse([], Http::STATUS_NO_CONTENT);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function health(): DataResponse {
		if (!$this->guard->isAllowed()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$current = $this->cursor->current();

		return new DataResponse([
			'transport' => $this->availability->effectiveTransport(),
			'notify_push_ok' => $this->availability->cachedDaemonCheck()['ok'],
			'last_signal_at' => $this->config->lastSignalAt(),
			'ledger_rows' => $this->ledger->countCollections(),
			'cursor' => $current,
			'ack_cursor' => $this->config->ackCursor(),
			'ack_at' => $this->config->ackAt(),
			'connector_lag' => max(0, $current - $this->config->ackCursor()),
		]);
	}
}
```

- [ ] **Step 4: Add the routes**

In `appinfo/routes.php`, after the `status_api#index` line, add:

```php
		['name' => 'change_feed_api#config', 'url' => '/api/1.0/notify/config', 'verb' => 'GET'],
		['name' => 'change_feed_api#changes', 'url' => '/api/1.0/notify/changes', 'verb' => 'GET'],
		['name' => 'change_feed_api#ack', 'url' => '/api/1.0/notify/ack', 'verb' => 'POST'],
		['name' => 'change_feed_api#health', 'url' => '/api/1.0/notify/health', 'verb' => 'GET'],
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter ChangeFeedApiControllerTest`
Expected: PASS, 8 tests

- [ ] **Step 6: Smoke-test the endpoints on the dev instance**

```bash
# as admin (basic auth); expects JSON with transport/cursor
curl -su admin:admin -H 'OCS-APIRequest: true' \
  'http://localhost:8080/index.php/apps/sendentsynchroniser/api/1.0/notify/config'
# as an ordinary user; expects HTTP 403
curl -su alice:alice \
  'http://localhost:8080/index.php/apps/sendentsynchroniser/api/1.0/notify/changes?since=0' -o /dev/null -w '%{http_code}\n'
```

Expected: first prints JSON, second prints `403`.

- [ ] **Step 7: Commit**

```bash
git add lib/Controller/ChangeFeedApiController.php appinfo/routes.php tests/Unit/Controller/ChangeFeedApiControllerTest.php
git commit -m "feat(cn): expose the change feed, config, ack and health endpoints"
```

---

### Task 15: The change-notification settings controller

Admin-only writes for transport mode, bot user, batching, and the bot app password. The webhook settings land in Task 23 (Phase 2); the round-trip ping in Task 22.

**Files:**
- Create: `lib/Controller/ChangeNotificationSettingsController.php`
- Modify: `appinfo/routes.php`
- Test: `tests/Unit/Controller/ChangeNotificationSettingsControllerTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Controller/ChangeNotificationSettingsControllerTest.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Controller;

use OCA\SendentSynchroniser\Controller\ChangeNotificationSettingsController;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushAvailability;
use OCA\SendentSynchroniser\Service\ChangeNotification\SignalPublisher;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ChangeNotificationSettingsControllerTest extends TestCase {

	/** @var ChangeNotificationConfig&MockObject */
	private $config;

	/** @var IUserManager&MockObject */
	private $userManager;

	/** @var NotifyPushAvailability&MockObject */
	private $availability;

	/** @var SignalPublisher&MockObject */
	private $publisher;

	private ChangeNotificationSettingsController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(ChangeNotificationConfig::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->availability = $this->createMock(NotifyPushAvailability::class);
		$this->publisher = $this->createMock(SignalPublisher::class);

		$this->controller = new ChangeNotificationSettingsController(
			'sendentsynchroniser',
			$this->createMock(IRequest::class),
			$this->config,
			$this->userManager,
			$this->availability,
			$this->publisher,
			new NullLogger(),
		);
	}

	public function testSetTransportModeStoresAValidMode(): void {
		$this->config->expects($this->once())->method('setTransportMode')->with('polling');

		$response = $this->controller->setTransportMode('polling');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testSetTransportModeRejectsGarbage(): void {
		$this->config->method('setTransportMode')
			->willThrowException(new \InvalidArgumentException('Unknown transport mode: wat'));

		$response = $this->controller->setTransportMode('wat');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testSetBotUserRequiresAnExistingUser(): void {
		$this->userManager->method('userExists')->with('ghost')->willReturn(false);
		$this->config->expects($this->never())->method('setBotUser');

		$response = $this->controller->setBotUser('ghost');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testSetBotUserStoresAnExistingUser(): void {
		$this->userManager->method('userExists')->with('sendent-sync')->willReturn(true);
		$this->config->expects($this->once())->method('setBotUser')->with('sendent-sync');

		$response = $this->controller->setBotUser('sendent-sync');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testSetBatchingStoresWindowAndMaxRefs(): void {
		$this->config->expects($this->once())->method('setBatchWindow')->with(5);
		$this->config->expects($this->once())->method('setMaxRefsPerSignal')->with(200);
		$this->config->expects($this->once())->method('setPollInterval')->with(60);

		$response = $this->controller->setBatching(5, 200, 60);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testRunTestRefreshesTheDaemonCheckAndReturnsAllChecks(): void {
		$this->availability->method('isAppEnabled')->willReturn(true);
		$this->availability->method('queue')->willReturn(new \stdClass());
		$this->availability->method('refreshDaemonCheck')
			->willReturn(['ok' => true, 'at' => 1, 'message' => 'daemon reachable']);
		$this->availability->method('effectiveTransport')->willReturn('notify_push');

		$data = $this->controller->runTest()->getData();

		$this->assertTrue($data['app_enabled']);
		$this->assertTrue($data['queue_available']);
		$this->assertTrue($data['daemon']['ok']);
		$this->assertSame('notify_push', $data['effective_transport']);
	}

	public function testFlushNowDelegatesToThePublisher(): void {
		$this->publisher->expects($this->once())->method('flush')->willReturn(['cursor' => 5]);

		$data = $this->controller->flushNow()->getData();

		$this->assertTrue($data['flushed']);
		$this->assertSame(5, $data['cursor']);
	}

	public function testFlushNowWithNothingPendingSaysSo(): void {
		$this->publisher->method('flush')->willReturn(null);

		$data = $this->controller->flushNow()->getData();

		$this->assertFalse($data['flushed']);
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter ChangeNotificationSettingsControllerTest`
Expected: FAIL — `Class "OCA\SendentSynchroniser\Controller\ChangeNotificationSettingsController" does not exist`

- [ ] **Step 3: Write the controller**

Create `lib/Controller/ChangeNotificationSettingsController.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Controller;

use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushAvailability;
use OCA\SendentSynchroniser\Service\ChangeNotification\SignalPublisher;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Admin-only writes behind the "Change notifications" settings section.
 * No @NoAdminRequired anywhere in this class: the framework's default
 * admin requirement is the access control.
 */
class ChangeNotificationSettingsController extends ApiController {

	public function __construct(
		string $appName,
		IRequest $request,
		private ChangeNotificationConfig $config,
		private IUserManager $userManager,
		private NotifyPushAvailability $availability,
		private SignalPublisher $publisher,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	public function setTransportMode(string $mode): DataResponse {
		try {
			$this->config->setTransportMode($mode);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse(['transportMode' => $mode]);
	}

	public function setBotUser(string $uid): DataResponse {
		if ($uid === '' || !$this->userManager->userExists($uid)) {
			return new DataResponse(['message' => 'User does not exist'], Http::STATUS_BAD_REQUEST);
		}

		$this->config->setBotUser($uid);

		return new DataResponse(['botUser' => $uid]);
	}

	public function setBatching(int $batchWindow, int $maxRefsPerSignal, int $pollInterval): DataResponse {
		// Raw values are stored; ChangeNotificationConfig clamps on read, so
		// out-of-range input degrades to the nearest bound instead of erroring.
		$this->config->setBatchWindow($batchWindow);
		$this->config->setMaxRefsPerSignal($maxRefsPerSignal);
		$this->config->setPollInterval($pollInterval);

		return new DataResponse([
			'batchWindow' => $this->config->batchWindow(),
			'maxRefsPerSignal' => $this->config->maxRefsPerSignal(),
			'pollInterval' => $this->config->pollInterval(),
		]);
	}

	/** The settings page's "Run test" button: all availability checks, live. */
	public function runTest(): DataResponse {
		$appEnabled = $this->availability->isAppEnabled();
		$queueAvailable = $this->availability->queue() !== null;
		$daemon = $appEnabled ? $this->availability->refreshDaemonCheck()
			: ['ok' => false, 'at' => 0, 'message' => 'notify_push is not installed'];

		return new DataResponse([
			'app_enabled' => $appEnabled,
			'queue_available' => $queueAvailable,
			'daemon' => $daemon,
			'effective_transport' => $this->availability->effectiveTransport(),
		]);
	}

	/** The settings page's "Flush now" button; also useful while debugging. */
	public function flushNow(): DataResponse {
		$signal = $this->publisher->flush();

		return new DataResponse([
			'flushed' => $signal !== null,
			'cursor' => $signal['cursor'] ?? null,
			'refs' => is_array($signal['refs'] ?? null) ? count($signal['refs']) : 0,
			'truncated' => $signal['truncated'] ?? false,
		]);
	}
}
```

- [ ] **Step 4: Add the routes**

In `appinfo/routes.php`, after the `notify/*` routes added in Task 14, add:

```php
		['name' => 'change_notification_settings#setTransportMode', 'url' => '/api/1.0/settings/cnTransportMode', 'verb' => 'POST'],
		['name' => 'change_notification_settings#setBotUser', 'url' => '/api/1.0/settings/cnBotUser', 'verb' => 'POST'],
		['name' => 'change_notification_settings#setBatching', 'url' => '/api/1.0/settings/cnBatching', 'verb' => 'POST'],
		['name' => 'change_notification_settings#runTest', 'url' => '/api/1.0/settings/cnRunTest', 'verb' => 'POST'],
		['name' => 'change_notification_settings#flushNow', 'url' => '/api/1.0/settings/cnFlushNow', 'verb' => 'POST'],
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter ChangeNotificationSettingsControllerTest`
Expected: PASS, 8 tests

- [ ] **Step 6: Commit**

```bash
git add lib/Controller/ChangeNotificationSettingsController.php appinfo/routes.php tests/Unit/Controller/ChangeNotificationSettingsControllerTest.php
git commit -m "feat(cn): add admin settings endpoints for change notifications"
```

---

### Task 16: Initial state for the admin page

**Files:**
- Modify: `lib/Settings/Admin.php`
- Modify: `src/settings.ts`

- [ ] **Step 1: Extend the admin initial state**

In `lib/Settings/Admin.php`, inside `getParams()`, after the `$params['trashbinScrubEnabled'] = ...` line, add:

```php
		// Change notifications (Connector signal transport)
		$params['cnTransportMode'] = $this->appConfig->getAppValue(Constants::CN_TRANSPORT_MODE_KEY, Constants::CN_TRANSPORT_MODE_DEFAULT);
		$params['cnBotUser'] = $this->appConfig->getAppValue(Constants::CN_BOT_USER_KEY, '');
		$params['cnBatchWindow'] = $this->appConfig->getAppValue(Constants::CN_BATCH_WINDOW_KEY, (string)Constants::CN_BATCH_WINDOW_DEFAULT);
		$params['cnMaxRefsPerSignal'] = $this->appConfig->getAppValue(Constants::CN_MAX_REFS_KEY, (string)Constants::CN_MAX_REFS_DEFAULT);
		$params['cnPollInterval'] = $this->appConfig->getAppValue(Constants::CN_POLL_INTERVAL_KEY, (string)Constants::CN_POLL_INTERVAL_DEFAULT);
		$params['cnNotifyPushInstalled'] = $this->appManager->isInstalled(Constants::CN_NOTIFY_PUSH_APPID);
```

- [ ] **Step 2: Thread the values through settings.ts**

In `src/settings.ts`, inside the `createApp(AdminSettings, {...})` props object, after the `notificationsAppInstalled` line, add:

```ts
		cnTransportMode: (state.cnTransportMode as string) || 'auto',
		cnBotUser: (state.cnBotUser as string) || '',
		cnBatchWindow: (state.cnBatchWindow as string) || '2',
		cnMaxRefsPerSignal: (state.cnMaxRefsPerSignal as string) || '500',
		cnPollInterval: (state.cnPollInterval as string) || '30',
		cnNotifyPushInstalled: Boolean(state.cnNotifyPushInstalled),
```

- [ ] **Step 3: Verify the frontend still compiles**

```powershell
$env:NODE_ENV='production'; node node_modules/webpack/bin/webpack.js --config webpack.prod.js
```

Expected: `compiled successfully` (AdminSettings does not declare the new props yet — extra props are ignored by Vue, so this is safe to ship mid-plan).

- [ ] **Step 4: Commit**

```bash
git add lib/Settings/Admin.php src/settings.ts
git commit -m "feat(cn): provide change-notification initial state to the admin page"
```

---

### Task 17: The admin settings UI

**Files:**
- Create: `src/components/ChangeNotificationsSection.vue`
- Modify: `src/components/AdminSettings.vue`

The section follows `SettingsSection.vue`'s established idiom: `script setup`, `saveSetting(endpoint, data, feedbackKey)` posting to `/api/1.0/settings/...`, a `saved` reactive map for the ✓ feedback, `settings-section__*` CSS classes.

- [ ] **Step 1: Write the component**

Create `src/components/ChangeNotificationsSection.vue`:

```vue
<template>
	<div class="settings-section">
		<h3>{{ t('sendentsynchroniser', 'Change notifications') }}</h3>
		<p class="settings-section__hint">
			{{ t('sendentsynchroniser', 'How the Exchange Connector learns that a calendar or address book changed. Signals carry only collection references, never event or contact data.') }}
		</p>

		<!-- Transport -->
		<div class="settings-section__field">
			<label>{{ t('sendentsynchroniser', 'Transport') }}</label>
			<div class="settings-section__input-row">
				<select v-model="transportMode"
					class="settings-section__input"
					@change="saveTransportMode">
					<option value="auto">
						{{ t('sendentsynchroniser', 'Automatic (prefer notify_push)') }}
					</option>
					<option value="notify_push">
						{{ t('sendentsynchroniser', 'Force notify_push') }}
					</option>
					<option value="polling">
						{{ t('sendentsynchroniser', 'Force polling') }}
					</option>
				</select>
				<span v-if="saved.transportMode" class="settings-section__saved">&#x2713;</span>
			</div>
			<!-- Persistent while forced-but-unhealthy — including when the app
				 is not installed at all (testResult never loads then). -->
			<p v-if="transportMode === 'notify_push' && ((testResult && !testResult.daemon.ok) || !notifyPushInstalled)"
				class="settings-section__hint settings-section__hint--warning">
				{{ t('sendentsynchroniser', 'notify_push is forced but unhealthy. Signals may not be delivered; the Connector will fall back to reading the change feed.') }}
			</p>
		</div>

		<!-- notify_push status -->
		<div class="settings-section__field">
			<label>{{ t('sendentsynchroniser', 'notify_push status') }}</label>
			<div v-if="testResult" class="cn-status">
				<div :class="['cn-status__line', testResult.app_enabled ? 'cn-status__line--ok' : 'cn-status__line--fail']">
					{{ testResult.app_enabled
						? t('sendentsynchroniser', 'notify_push app: enabled ✓')
						: t('sendentsynchroniser', 'notify_push app: not installed ✗ — in Nextcloud AIO it ships enabled; on other installs, install the "Client Push" app and run occ notify_push:setup') }}
				</div>
				<div :class="['cn-status__line', testResult.queue_available ? 'cn-status__line--ok' : 'cn-status__line--fail']">
					{{ testResult.queue_available
						? t('sendentsynchroniser', 'Redis queue: available ✓')
						: t('sendentsynchroniser', 'Redis queue: unavailable ✗ — configure Redis as the distributed cache') }}
				</div>
				<div :class="['cn-status__line', testResult.daemon.ok ? 'cn-status__line--ok' : 'cn-status__line--fail']">
					{{ testResult.daemon.ok
						? t('sendentsynchroniser', 'Push daemon: reachable ✓')
						: t('sendentsynchroniser', 'Push daemon: unreachable ✗ — {message}', { message: testResult.daemon.message }) }}
				</div>
				<div class="cn-status__line">
					{{ t('sendentsynchroniser', 'Active transport: {transport}', { transport: testResult.effective_transport }) }}
				</div>
			</div>
			<div v-else-if="!notifyPushInstalled" class="cn-status">
				<div class="cn-status__line cn-status__line--fail">
					{{ t('sendentsynchroniser', 'notify_push app: not installed ✗') }}
				</div>
			</div>
			<div class="settings-section__input-row">
				<button type="button" :disabled="testing" @click="runTest">
					{{ testing ? t('sendentsynchroniser', 'Testing…') : t('sendentsynchroniser', 'Run test') }}
				</button>
			</div>
		</div>

		<!-- Service account -->
		<div class="settings-section__field">
			<label>{{ t('sendentsynchroniser', 'Service account (bot user)') }}</label>
			<div class="settings-section__input-row">
				<input v-model="botUser"
					type="text"
					class="settings-section__input"
					:placeholder="t('sendentsynchroniser', 'e.g. sendent-sync')"
					@change="saveBotUser">
				<span v-if="saved.botUser" class="settings-section__saved">&#x2713;</span>
			</div>
			<p class="settings-section__hint">
				{{ t('sendentsynchroniser', 'This account receives change signals and reads the change feed only; it needs no group memberships, quota or calendars. Create it first (Users administration or occ user:add), then generate an app password for it under its own Settings → Security and store that password in the Connector.') }}
			</p>
		</div>

		<!-- Batching -->
		<div class="settings-section__field">
			<label>{{ t('sendentsynchroniser', 'Batching') }}</label>
			<div class="settings-section__input-row">
				<label class="cn-inline-label">{{ t('sendentsynchroniser', 'Batch window (s)') }}
					<input v-model="batchWindow" type="number" min="0" max="10" @change="saveBatching">
				</label>
				<label class="cn-inline-label">{{ t('sendentsynchroniser', 'Max references per signal') }}
					<input v-model="maxRefsPerSignal" type="number" min="1" max="5000" @change="saveBatching">
				</label>
				<label class="cn-inline-label">{{ t('sendentsynchroniser', 'Poll interval (s)') }}
					<input v-model="pollInterval" type="number" min="5" max="300" @change="saveBatching">
				</label>
				<span v-if="saved.batching" class="settings-section__saved">&#x2713;</span>
			</div>
		</div>

		<!-- Diagnostics -->
		<div class="settings-section__field">
			<label>{{ t('sendentsynchroniser', 'Diagnostics') }}</label>
			<div v-if="health" class="cn-status">
				<div class="cn-status__line">
					{{ t('sendentsynchroniser', 'Collections tracked: {n}', { n: String(health.ledger_rows) }) }}
				</div>
				<div class="cn-status__line">
					{{ t('sendentsynchroniser', 'Current cursor: {n}', { n: String(health.cursor) }) }}
				</div>
				<div class="cn-status__line">
					{{ health.ack_at > 0
						? t('sendentsynchroniser', 'Connector acknowledged cursor {ack} (lag {lag})', { ack: String(health.ack_cursor), lag: String(health.connector_lag) })
						: t('sendentsynchroniser', 'Connector has not acknowledged yet') }}
				</div>
			</div>
			<div class="settings-section__input-row">
				<button type="button" @click="refreshHealth">
					{{ t('sendentsynchroniser', 'Refresh') }}
				</button>
				<button type="button" :disabled="flushing" @click="flushNow">
					{{ flushing ? t('sendentsynchroniser', 'Flushing…') : t('sendentsynchroniser', 'Flush now') }}
				</button>
				<span v-if="saved.flush" class="settings-section__saved">&#x2713;</span>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

interface TestResult {
	app_enabled: boolean
	queue_available: boolean
	daemon: { ok: boolean, at: number, message: string }
	effective_transport: string
}

interface Health {
	transport: string
	notify_push_ok: boolean
	last_signal_at: number
	ledger_rows: number
	cursor: number
	ack_cursor: number
	ack_at: number
	connector_lag: number
}

const props = defineProps<{
	initialTransportMode: string
	initialBotUser: string
	initialBatchWindow: string
	initialMaxRefsPerSignal: string
	initialPollInterval: string
	notifyPushInstalled: boolean
}>()

const transportMode = ref(props.initialTransportMode)
const botUser = ref(props.initialBotUser)
const batchWindow = ref(props.initialBatchWindow)
const maxRefsPerSignal = ref(props.initialMaxRefsPerSignal)
const pollInterval = ref(props.initialPollInterval)

const testing = ref(false)
const flushing = ref(false)
const testResult = ref<TestResult | null>(null)
const health = ref<Health | null>(null)

const saved = reactive<Record<string, boolean>>({})

/**
 * @param key feedback key to flash
 */
function showSaved(key: string) {
	saved[key] = true
	setTimeout(() => { saved[key] = false }, 1500)
}

/**
 * @param endpoint settings endpoint under /api/1.0/settings/
 * @param data POST body
 * @param feedbackKey feedback key to flash on success
 */
async function saveSetting(endpoint: string, data: Record<string, string | number>, feedbackKey: string) {
	const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/' + endpoint)
	try {
		await axios.post(url, data)
		showSaved(feedbackKey)
	} catch {
		console.error('Failed to save setting:', endpoint)
	}
}

/** */
function saveTransportMode() { saveSetting('cnTransportMode', { mode: transportMode.value }, 'transportMode') }
/** */
function saveBotUser() { saveSetting('cnBotUser', { uid: botUser.value }, 'botUser') }
/** */
function saveBatching() {
	saveSetting('cnBatching', {
		batchWindow: Number(batchWindow.value),
		maxRefsPerSignal: Number(maxRefsPerSignal.value),
		pollInterval: Number(pollInterval.value),
	}, 'batching')
}

/** */
async function runTest() {
	testing.value = true
	try {
		const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/cnRunTest')
		testResult.value = (await axios.post(url)).data as TestResult
	} catch {
		console.error('notify_push test failed')
	} finally {
		testing.value = false
	}
}

/** */
async function refreshHealth() {
	try {
		const url = generateUrl('/apps/sendentsynchroniser/api/1.0/notify/health')
		health.value = (await axios.get(url)).data as Health
	} catch {
		console.error('Failed to load change-notification health')
	}
}

/** */
async function flushNow() {
	flushing.value = true
	try {
		const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/cnFlushNow')
		await axios.post(url)
		showSaved('flush')
		await refreshHealth()
	} catch {
		console.error('Flush failed')
	} finally {
		flushing.value = false
	}
}

const notifyPushInstalled = props.notifyPushInstalled

onMounted(() => {
	refreshHealth()
	if (props.notifyPushInstalled) {
		runTest()
	}
})
</script>

<style scoped lang="scss">
.cn-status {
	margin: 8px 0;

	&__line {
		font-size: 0.9em;
		line-height: 1.6;

		&--ok {
			color: var(--color-success, #2d7b41);
		}

		&--fail {
			color: var(--color-error, #d91f2d);
		}
	}
}

.cn-inline-label {
	display: inline-flex;
	flex-direction: column;
	margin-right: 12px;
	font-size: 0.85em;

	input {
		width: 90px;
	}
}
</style>
```

- [ ] **Step 2: Mount the section in AdminSettings**

In `src/components/AdminSettings.vue`:

Add the import next to the other component imports:

```ts
import ChangeNotificationsSection from './ChangeNotificationsSection.vue'
```

Extend the `defineProps` block with:

```ts
	cnTransportMode: string
	cnBotUser: string
	cnBatchWindow: string
	cnMaxRefsPerSignal: string
	cnPollInterval: string
	cnNotifyPushInstalled: boolean
```

In the template, inside the `tab === 'sync'` section, after the `<SettingsSection ... />` element, add:

```html
			<ChangeNotificationsSection :initial-transport-mode="cnTransportMode"
				:initial-bot-user="cnBotUser"
				:initial-batch-window="cnBatchWindow"
				:initial-max-refs-per-signal="cnMaxRefsPerSignal"
				:initial-poll-interval="cnPollInterval"
				:notify-push-installed="cnNotifyPushInstalled" />
```

- [ ] **Step 3: Verify the frontend compiles**

```powershell
$env:NODE_ENV='production'; node node_modules/webpack/bin/webpack.js --config webpack.prod.js
```

Expected: `compiled successfully`, no TypeScript errors.

- [ ] **Step 4: Verify in the browser**

On the dev instance: Settings → Administration → Sendent Sync → Synchronization Management tab. Expected: the "Change notifications" section renders, "Run test" shows the three status lines, saving the transport mode flashes ✓.

- [ ] **Step 5: Commit**

```bash
git add src/components/ChangeNotificationsSection.vue src/components/AdminSettings.vue
git commit -m "feat(cn): admin UI for transport, bot account, batching and diagnostics"
```

---

### Task 18: Phase 1 end-to-end verification and spike removal

No new code; this task proves the MVP on a real instance and cleans up.

**Files:**
- Delete: `lib/Command/CnSpike.php`
- Modify: `appinfo/info.xml` (remove the spike command)

- [ ] **Step 1: Remove the spike**

```bash
git rm lib/Command/CnSpike.php
```

In `appinfo/info.xml` remove the line:

```xml
		<command>OCA\SendentSynchroniser\Command\CnSpike</command>
```

- [ ] **Step 2: Run the full test suite**

Run: `php vendor/bin/phpunit -c phpunit.xml`
Expected: PASS — all pre-existing tests plus roughly 60 new ones.

- [ ] **Step 3: End-to-end on an instance WITH notify_push (transport A)**

1. Configure the bot user and `auto` mode in the admin settings; "Run test" all green.
2. Start the Task 1 `listen.js` websocket client as the bot.
3. As user alice, create a calendar event (web UI or a CalDAV PUT).
4. Expected within ~2 s: one frame `sendent_sync {"v":1,"instance":...,"prev":P,"cursor":N,"truncated":false,"refs":[{"p":"principals/users/alice","t":"caldav","u":"personal","s":...,"c":false}]}`.
5. Create 5 events rapidly, then wait 3 s. Expected: 1–2 frames total (leading edge + at most one trailing flush/sweep), not 5.
6. `curl -su <bot>:<app-password> '<base>/index.php/apps/sendentsynchroniser/api/1.0/notify/changes?since=0'` returns the same refs with `cursor` ≥ the frame's.
7. **Delete** an event (spec §12 open item 3): expect a ref for alice's calendar. If no signal arrives, record it in the research note — the reconcile is then the only delete path on this NC version.
8. **Update a share** on alice's calendar (share it to bob): expect a ref with `"c":true` carrying **alice's** principal (verifies spec §12 open item 4 — `getCalendarData()['principaluri']` on `CalendarShareUpdatedEvent`).
9. **On NC 32+ only — trash a whole calendar**: expect a ref with `"c":true`. If none arrives, neither the OCA nor the OCP collection-level trash event exists/fires there (plan Task 9's defensive OCP registration); record the finding — reconcile then covers calendar-trash on those versions.

- [ ] **Step 4: End-to-end on an instance WITHOUT Redis (transport B)**

1. `/notify/config` returns `"transport":"polling"`.
2. Create an event as alice; `/notify/changes?since=0` shows the ref (sequence via the DB fallback).
3. Delete the calendar; the ref reappears at a higher cursor with `"c":true`.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "chore(cn): remove notify_push spike command after MVP verification"
```

---

## Phase 2 — Scale

### Task 19: Signal metrics

**Files:**
- Create: `lib/Service/ChangeNotification/SignalMetrics.php`
- Modify: `lib/Service/ChangeNotification/SignalPublisher.php`
- Test: `tests/Unit/Service/ChangeNotification/SignalMetricsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Service/ChangeNotification/SignalMetricsTest.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\Service\ChangeNotification\SignalMetrics;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SignalMetricsTest extends TestCase {

	/** @var IAppConfig&MockObject */
	private $appConfig;

	/** @var ITimeFactory&MockObject */
	private $time;

	/** @var array<string, string> */
	private array $values = [];

	private SignalMetrics $metrics;

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getAppValue')->willReturnCallback(
			fn (string $key, $default = '') => $this->values[$key] ?? $default
		);
		$this->appConfig->method('setAppValue')->willReturnCallback(
			function (string $key, string $value): void {
				$this->values[$key] = $value;
			}
		);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->metrics = new SignalMetrics($this->appConfig, $this->time);
	}

	public function testFlushesAccumulateWithinTheHourBucket(): void {
		$this->time->method('getTime')->willReturn(3600 * 100 + 60);

		$this->metrics->recordFlush(18, false);
		$this->metrics->recordFlush(500, true);

		$snapshot = $this->metrics->lastHour();
		$this->assertSame(2, $snapshot['flushes']);
		$this->assertSame(518, $snapshot['refs']);
		$this->assertSame(1, $snapshot['truncated']);
		$this->assertSame(500, $snapshot['max_refs']);
	}

	public function testANewHourStartsAFreshBucket(): void {
		$times = [3600 * 100, 3600 * 101];
		$i = 0;
		$this->time->method('getTime')->willReturnCallback(function () use (&$i, $times) {
			return $times[min($i++, 1)];
		});

		$this->metrics->recordFlush(10, false);   // hour 100
		$this->metrics->recordFlush(20, false);   // hour 101 — new bucket

		$snapshot = $this->metrics->lastHour();
		$this->assertSame(1, $snapshot['flushes']);
		$this->assertSame(20, $snapshot['refs']);
	}

	public function testAnEmptyHistoryReadsAsZeroes(): void {
		$this->time->method('getTime')->willReturn(0);

		$snapshot = $this->metrics->lastHour();
		$this->assertSame(0, $snapshot['flushes']);
		$this->assertSame(0, $snapshot['refs']);
		$this->assertSame(0, $snapshot['truncated']);
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter SignalMetricsTest`
Expected: FAIL — class does not exist

- [ ] **Step 3: Write the metrics service**

Create `lib/Service/ChangeNotification/SignalMetrics.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Lightweight flush counters for the diagnostics block ("Signals last hour:
 * 3,412 flushes · avg 18.6 refs/signal · truncated x2").
 *
 * One app-config JSON blob, bucketed per clock hour. Deliberately approximate:
 * concurrent flushers may lose an increment, which is irrelevant for a gauge
 * an admin reads. No new table for a nice-to-have.
 */
class SignalMetrics {

	private const KEY = 'cnMetricsHour';

	public function __construct(
		private IAppConfig $appConfig,
		private ITimeFactory $time,
	) {}

	public function recordFlush(int $refCount, bool $truncated): void {
		$hour = intdiv($this->time->getTime(), 3600);
		$bucket = $this->bucket();

		if (($bucket['hour'] ?? -1) !== $hour) {
			$bucket = ['hour' => $hour, 'flushes' => 0, 'refs' => 0, 'truncated' => 0, 'max_refs' => 0];
		}

		$bucket['flushes']++;
		$bucket['refs'] += $refCount;
		$bucket['max_refs'] = max($bucket['max_refs'], $refCount);
		if ($truncated) {
			$bucket['truncated']++;
		}

		$this->appConfig->setAppValue(self::KEY, json_encode($bucket, JSON_THROW_ON_ERROR));
	}

	/** @return array{flushes: int, refs: int, truncated: int, max_refs: int} */
	public function lastHour(): array {
		$hour = intdiv($this->time->getTime(), 3600);
		$bucket = $this->bucket();

		if (($bucket['hour'] ?? -1) !== $hour) {
			return ['flushes' => 0, 'refs' => 0, 'truncated' => 0, 'max_refs' => 0];
		}

		return [
			'flushes' => (int)($bucket['flushes'] ?? 0),
			'refs' => (int)($bucket['refs'] ?? 0),
			'truncated' => (int)($bucket['truncated'] ?? 0),
			'max_refs' => (int)($bucket['max_refs'] ?? 0),
		];
	}

	/** @return array<string, int> */
	private function bucket(): array {
		$raw = json_decode((string)$this->appConfig->getAppValue(self::KEY, ''), true);

		return is_array($raw) ? $raw : [];
	}
}
```

- [ ] **Step 4: Record metrics from the publisher**

In `lib/Service/ChangeNotification/SignalPublisher.php`:

Add the constructor parameter after `private ITimeFactory $time,`:

```php
		private SignalMetrics $metrics,
```

In `flush()`, immediately after `$this->config->setLastSignalAt($this->time->getTime());`, add:

```php
		$this->metrics->recordFlush(count($refs), $truncated);
```

Update `tests/Unit/Service/ChangeNotification/SignalPublisherTest.php`: in `setUp()`, add after the `$time` block:

```php
		$metrics = $this->createMock(\OCA\SendentSynchroniser\Service\ChangeNotification\SignalMetrics::class);
```

and pass `$metrics,` to the `new SignalPublisher(...)` call between `$time` and `new NullLogger()`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter 'SignalMetricsTest|SignalPublisherTest'`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add lib/Service/ChangeNotification/SignalMetrics.php lib/Service/ChangeNotification/SignalPublisher.php tests/Unit/Service/ChangeNotification/SignalMetricsTest.php tests/Unit/Service/ChangeNotification/SignalPublisherTest.php
git commit -m "feat(cn): record hour-bucketed flush metrics"
```

---

### Task 20: The occ commands — status, flush, setup

**Files:**
- Create: `lib/Command/ChangeNotificationStatus.php`
- Create: `lib/Command/ChangeNotificationFlush.php`
- Create: `lib/Command/ChangeNotificationSetup.php`
- Modify: `appinfo/info.xml`
- Test: `tests/Unit/Command/ChangeNotificationSetupTest.php`

- [ ] **Step 1: Write the failing test (setup command has the only branching logic)**

Create `tests/Unit/Command/ChangeNotificationSetupTest.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Command;

use OCA\SendentSynchroniser\Command\ChangeNotificationSetup;
use OCA\SendentSynchroniser\Db\SequenceMapper;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ChangeNotificationSetupTest extends TestCase {

	/** @var ChangeNotificationConfig&MockObject */
	private $config;

	/** @var IUserManager&MockObject */
	private $userManager;

	/** @var SequenceMapper&MockObject */
	private $sequence;

	/** @var ChangeLedgerService&MockObject */
	private $ledger;

	private CommandTester $tester;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(ChangeNotificationConfig::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->sequence = $this->createMock(SequenceMapper::class);
		$this->ledger = $this->createMock(ChangeLedgerService::class);

		$command = new ChangeNotificationSetup(
			$this->config,
			$this->userManager,
			$this->sequence,
			$this->ledger,
		);
		$this->tester = new CommandTester($command);
	}

	public function testSetsBotUserAndTransport(): void {
		$this->userManager->method('userExists')->with('sendent-sync')->willReturn(true);
		$this->config->expects($this->once())->method('setBotUser')->with('sendent-sync');
		$this->config->expects($this->once())->method('setTransportMode')->with('auto');

		$exit = $this->tester->execute(['--bot-user' => 'sendent-sync', '--transport' => 'auto']);

		$this->assertSame(0, $exit);
	}

	public function testRejectsAMissingBotUserWithoutCreate(): void {
		$this->userManager->method('userExists')->willReturn(false);
		$this->config->expects($this->never())->method('setBotUser');

		$exit = $this->tester->execute(['--bot-user' => 'ghost']);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString('does not exist', $this->tester->getDisplay());
	}

	public function testCreateFlagCreatesAMissingBotUser(): void {
		$this->userManager->method('userExists')->with('sendent-sync')->willReturn(false);
		$this->userManager->expects($this->once())
			->method('createUser')
			->with('sendent-sync', $this->anything())
			->willReturn($this->createMock(\OCP\IUser::class));
		$this->config->expects($this->once())->method('setBotUser')->with('sendent-sync');

		$exit = $this->tester->execute(['--bot-user' => 'sendent-sync', '--create' => true]);

		$this->assertSame(0, $exit);
	}

	public function testRejectsAnUnknownTransport(): void {
		$this->config->method('setTransportMode')
			->willThrowException(new \InvalidArgumentException('Unknown transport mode: wat'));

		$exit = $this->tester->execute(['--transport' => 'wat']);

		$this->assertSame(1, $exit);
	}

	public function testReseedLiftsTheSequenceAboveTheLedger(): void {
		$this->ledger->method('highWaterMark')->willReturn(5000);
		$this->sequence->expects($this->once())->method('reseedAbove')->with(5000);

		$exit = $this->tester->execute(['--reseed' => true]);

		$this->assertSame(0, $exit);
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter ChangeNotificationSetupTest`
Expected: FAIL — class does not exist

- [ ] **Step 3: Write the setup command**

Create `lib/Command/ChangeNotificationSetup.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Command;

use OCA\SendentSynchroniser\Db\SequenceMapper;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Unattended install: occ sendentsynchroniser:cn-setup
 *     --bot-user=sendent-sync --transport=auto
 *
 * --reseed lifts the DB sequence above the ledger's high-water mark — needed
 * once if an instance permanently loses its distributed cache and the DB
 * sequence would otherwise restart below already-published cursors.
 */
class ChangeNotificationSetup extends Command {

	public function __construct(
		private ChangeNotificationConfig $config,
		private IUserManager $userManager,
		private SequenceMapper $sequence,
		private ChangeLedgerService $ledger,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:cn-setup')
			->setDescription('Configure change notifications for the Exchange Connector')
			->addOption('bot-user', null, InputOption::VALUE_REQUIRED, 'User the Connector authenticates as')
			->addOption('create', null, InputOption::VALUE_NONE, 'Create the bot user if it does not exist (random password — generate an app password for the Connector afterwards)')
			->addOption('transport', null, InputOption::VALUE_REQUIRED, 'auto | notify_push | polling')
			->addOption('reseed', null, InputOption::VALUE_NONE, 'Lift the DB sequence above the ledger high-water mark');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$botUser = $input->getOption('bot-user');
		if (is_string($botUser) && $botUser !== '') {
			if (!$this->userManager->userExists($botUser)) {
				if (!$input->getOption('create')) {
					$output->writeln('<error>User "' . $botUser . '" does not exist. Create it first (occ user:add ' . $botUser . ') or pass --create.</error>');
					return 1;
				}
				// Random throwaway login password: the account is only ever
				// used via an app password the admin generates as this user.
				$created = $this->userManager->createUser($botUser, base64_encode(random_bytes(36)));
				if ($created === false) {
					$output->writeln('<error>Could not create user "' . $botUser . '"</error>');
					return 1;
				}
				$output->writeln('Created bot user "' . $botUser . '". Log in as it once to generate an app password for the Connector.');
			}
			$this->config->setBotUser($botUser);
			$output->writeln('Bot user set to "' . $botUser . '"');
		}

		$transport = $input->getOption('transport');
		if (is_string($transport) && $transport !== '') {
			try {
				$this->config->setTransportMode($transport);
			} catch (\InvalidArgumentException $e) {
				$output->writeln('<error>' . $e->getMessage() . '</error>');
				return 1;
			}
			$output->writeln('Transport mode set to "' . $transport . '"');
		}

		if ($input->getOption('reseed')) {
			$mark = $this->ledger->highWaterMark();
			$this->sequence->reseedAbove($mark);
			$output->writeln('Sequence reseeded above ' . $mark);
		}

		return 0;
	}
}
```

- [ ] **Step 4: Write the status command**

Create `lib/Command/ChangeNotificationStatus.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Command;

use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\CursorService;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushAvailability;
use OCA\SendentSynchroniser\Service\ChangeNotification\SignalMetrics;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ sendentsynchroniser:cn-status — the admin settings' diagnostics block
 * for people who live in a terminal, and the monitoring hook (values are
 * plain `key: value` lines, one per metric).
 */
class ChangeNotificationStatus extends Command {

	public function __construct(
		private ChangeNotificationConfig $config,
		private ChangeLedgerService $ledger,
		private CursorService $cursor,
		private NotifyPushAvailability $availability,
		private SignalMetrics $metrics,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:cn-status')
			->setDescription('Show change-notification transport, ledger and signal status');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$daemon = $this->availability->cachedDaemonCheck();
		$hour = $this->metrics->lastHour();
		$current = $this->cursor->current();
		$ack = $this->config->ackCursor();

		$output->writeln('transport_mode: ' . $this->config->transportMode());
		$output->writeln('effective_transport: ' . $this->availability->effectiveTransport());
		$output->writeln('notify_push_app: ' . ($this->availability->isAppEnabled() ? 'enabled' : 'missing'));
		$output->writeln('notify_push_queue: ' . ($this->availability->queue() !== null ? 'available' : 'unavailable'));
		$output->writeln('notify_push_daemon: ' . ($daemon['ok'] ? 'ok' : ('failed (' . $daemon['message'] . ')')));
		$output->writeln('bot_user: ' . ($this->config->botUser() ?: '(unset)'));
		$output->writeln('batch_window_s: ' . $this->config->batchWindow());
		$output->writeln('max_refs_per_signal: ' . $this->config->maxRefsPerSignal());
		$output->writeln('ledger_collections: ' . $this->ledger->countCollections());
		$output->writeln('cursor: ' . $current);
		$output->writeln('flushed_seq: ' . $this->config->flushedSeq());
		$output->writeln('ack_cursor: ' . $ack);
		$output->writeln('connector_lag: ' . max(0, $current - $ack));
		$output->writeln('last_signal_at: ' . $this->config->lastSignalAt());
		$output->writeln('flushes_last_hour: ' . $hour['flushes']);
		$output->writeln('refs_last_hour: ' . $hour['refs']);
		$output->writeln('truncated_last_hour: ' . $hour['truncated']);
		$output->writeln('max_refs_in_one_signal_last_hour: ' . $hour['max_refs']);

		return 0;
	}
}
```

- [ ] **Step 5: Write the flush command**

Create `lib/Command/ChangeNotificationFlush.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Command;

use OCA\SendentSynchroniser\Service\ChangeNotification\SignalPublisher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ sendentsynchroniser:cn-flush — force a signal for whatever is above the
 * watermark, ignoring the batch window. Debugging and demos.
 */
class ChangeNotificationFlush extends Command {

	public function __construct(
		private SignalPublisher $publisher,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:cn-flush')
			->setDescription('Force-publish a change-notification signal now');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$signal = $this->publisher->flush();

		if ($signal === null) {
			$output->writeln('Nothing to flush (or publish failed — check the log).');
			return 0;
		}

		$output->writeln('Published signal at cursor ' . $signal['cursor']
			. ' with ' . count($signal['refs']) . ' refs'
			. ($signal['truncated'] ? ' (truncated)' : ''));

		return 0;
	}
}
```

- [ ] **Step 6: Register the commands**

In `appinfo/info.xml`, inside `<commands>`, add:

```xml
        <command>OCA\SendentSynchroniser\Command\ChangeNotificationStatus</command>
        <command>OCA\SendentSynchroniser\Command\ChangeNotificationFlush</command>
        <command>OCA\SendentSynchroniser\Command\ChangeNotificationSetup</command>
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter ChangeNotificationSetupTest`
Expected: PASS, 5 tests

- [ ] **Step 8: Verify on the instance**

```bash
php occ sendentsynchroniser:cn-status
php occ sendentsynchroniser:cn-flush
```

Expected: status block prints; flush prints "Nothing to flush" or a cursor line.

- [ ] **Step 9: Commit**

```bash
git add lib/Command/ChangeNotificationStatus.php lib/Command/ChangeNotificationFlush.php lib/Command/ChangeNotificationSetup.php appinfo/info.xml tests/Unit/Command/ChangeNotificationSetupTest.php
git commit -m "feat(cn): add cn-status, cn-flush and cn-setup occ commands"
```

---

### Task 21: The notify_push self-test TimedJob

**Files:**
- Create: `lib/Cron/NotifyPushSelfTest.php`
- Modify: `appinfo/info.xml`

Thin orchestration over `NotifyPushAvailability::refreshDaemonCheck()` (already tested); no new unit test.

- [ ] **Step 1: Write the job**

Create `lib/Cron/NotifyPushSelfTest.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Cron;

use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushAvailability;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Keeps the cached daemon reachability current without ever probing from the
 * DAV write path. In `auto` mode this is what flips the effective transport
 * to polling when the daemon dies, and back when it returns.
 */
class NotifyPushSelfTest extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private NotifyPushAvailability $availability,
		private ChangeNotificationConfig $config,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);

		$this->setInterval(Constants::CN_DAEMON_CHECK_TTL);
	}

	protected function run($arguments): void {
		if ($this->config->transportMode() === Constants::TRANSPORT_POLLING) {
			return; // pinned to polling; nothing to test
		}

		try {
			$result = $this->availability->refreshDaemonCheck();
			if (!$result['ok']) {
				$this->logger->info('notify_push self-test failed: ' . $result['message'], [
					'app' => 'sendentsynchroniser',
				]);
			}
		} catch (\Throwable $e) {
			$this->logger->error('notify_push self-test crashed: ' . $e->getMessage(), [
				'exception' => $e,
				'app' => 'sendentsynchroniser',
			]);
		}
	}
}
```

- [ ] **Step 2: Register the job**

In `appinfo/info.xml`, inside `<background-jobs>`, add:

```xml
        <job>OCA\SendentSynchroniser\Cron\NotifyPushSelfTest</job>
```

- [ ] **Step 3: Verify it registers and runs**

```bash
php occ app:disable sendentsynchroniser && php occ app:enable sendentsynchroniser
php occ background-job:list --class 'OCA\SendentSynchroniser\Cron\NotifyPushSelfTest'
```

Expected: one job row. Then run it and confirm the cached result updates:

```bash
php occ background-job:execute --force-execute <job-id>
php occ sendentsynchroniser:cn-status | grep notify_push_daemon
```

- [ ] **Step 4: Commit**

```bash
git add lib/Cron/NotifyPushSelfTest.php appinfo/info.xml
git commit -m "feat(cn): periodically self-test notify_push daemon reachability"
```

---

### Task 22: Round-trip ping from the settings page

Publishes `sendent_sync_ping` through the transport and records the result the settings page reports back. This is the spec's §4.4 check 4, informational per deviation 7.

**Files:**
- Modify: `lib/Controller/ChangeNotificationSettingsController.php`
- Modify: `appinfo/routes.php`
- Modify: `src/components/ChangeNotificationsSection.vue`
- Test: extend `tests/Unit/Controller/ChangeNotificationSettingsControllerTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `ChangeNotificationSettingsControllerTest`:

```php
	public function testSendPingPublishesThroughTheTransport(): void {
		$this->transport->expects($this->once())
			->method('publishPing')
			->with($this->callback(fn (array $b) => isset($b['nonce']) && $b['nonce'] !== ''))
			->willReturn(true);

		$data = $this->controller->sendPing()->getData();

		$this->assertTrue($data['published']);
		$this->assertNotEmpty($data['nonce']);
	}

	public function testReportPingStoresTheRoundTripResult(): void {
		$this->config->expects($this->once())->method('setRoundTrip')->with(true, $this->anything(), 38);

		$response = $this->controller->reportPing(true, 38);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}
```

Add to the test's `setUp()` a `NotifyPushTransport` mock (`$this->transport = $this->createMock(NotifyPushTransport::class);` with the matching `use` import and property) and pass it to the controller constructor after `$this->publisher` — and add an `ITimeFactory` mock returning `1755676800` after it, matching the constructor change in Step 2.

- [ ] **Step 2: Extend the controller**

In `lib/Controller/ChangeNotificationSettingsController.php`:

Add constructor parameters after `private SignalPublisher $publisher,`:

```php
		private \OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushTransport $transport,
		private \OCP\AppFramework\Utility\ITimeFactory $time,
```

Add the methods:

```php
	/**
	 * Settings page presses "Run test" with a websocket open as the bot (the
	 * @nextcloud/notify_push JS client): we publish a ping frame it should see.
	 */
	public function sendPing(): DataResponse {
		$nonce = bin2hex(random_bytes(8));
		$published = $this->transport->publishPing([
			'nonce' => $nonce,
			'sent_at' => $this->time->getTime(),
		]);

		return new DataResponse(['published' => $published, 'nonce' => $nonce]);
	}

	/** The page reports whether (and how fast) the ping arrived. */
	public function reportPing(bool $ok, int $ms): DataResponse {
		$this->config->setRoundTrip($ok, $this->time->getTime(), max(0, $ms));

		return new DataResponse(['stored' => true]);
	}
```

- [ ] **Step 3: Add the routes**

In `appinfo/routes.php`, after the `cnFlushNow` route:

```php
		['name' => 'change_notification_settings#sendPing', 'url' => '/api/1.0/settings/cnSendPing', 'verb' => 'POST'],
		['name' => 'change_notification_settings#reportPing', 'url' => '/api/1.0/settings/cnReportPing', 'verb' => 'POST'],
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter ChangeNotificationSettingsControllerTest`
Expected: PASS, 10 tests

- [ ] **Step 5: Wire the round-trip into the settings UI**

In `src/components/ChangeNotificationsSection.vue`, extend `runTest()` to attempt the round-trip after the availability checks succeed. Append inside `runTest()` after `testResult.value = ...`:

```ts
		if (testResult.value?.daemon.ok) {
			await runRoundTrip()
		}
```

Add alongside the other functions:

```ts
const roundTrip = ref<{ ok: boolean, ms: number } | null>(null)

/**
 * Publish test (plan deviation 7): asks the server to publish a ping addressed
 * to the BOT user and measures the request round-trip. The admin session
 * cannot see bot-addressed frames, so this verifies and times the PUBLISH side
 * only; end-to-end delivery confirmation is the Connector's own startup check.
 */
async function runRoundTrip() {
	const started = Date.now()
	try {
		const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/cnSendPing')
		const { data } = await axios.post(url)
		const ms = Date.now() - started
		roundTrip.value = { ok: Boolean(data.published), ms }
		const report = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/cnReportPing')
		await axios.post(report, { ok: Boolean(data.published), ms })
	} catch {
		roundTrip.value = { ok: false, ms: 0 }
	}
}
```

And render it in the notify_push status block, after the "Active transport" line:

```html
				<div v-if="roundTrip" :class="['cn-status__line', roundTrip.ok ? 'cn-status__line--ok' : 'cn-status__line--fail']">
					{{ roundTrip.ok
						? t('sendentsynchroniser', 'Publish test: {ms} ms ✓', { ms: String(roundTrip.ms) })
						: t('sendentsynchroniser', 'Publish test failed ✗') }}
				</div>
```

- [ ] **Step 6: Verify the frontend compiles**

```powershell
$env:NODE_ENV='production'; node node_modules/webpack/bin/webpack.js --config webpack.prod.js
```

Expected: `compiled successfully`

- [ ] **Step 7: Commit**

```bash
git add lib/Controller/ChangeNotificationSettingsController.php appinfo/routes.php src/components/ChangeNotificationsSection.vue tests/Unit/Controller/ChangeNotificationSettingsControllerTest.php
git commit -m "feat(cn): publish-side round-trip test from the admin settings"
```

---

### Task 23: The optional outbound webhook

**Files:**
- Create: `lib/Service/ChangeNotification/WebhookSigner.php`
- Create: `lib/BackgroundJob/SendWebhookSignal.php`
- Modify: `lib/Service/ChangeNotification/SignalPublisher.php`
- Modify: `lib/Controller/ChangeNotificationSettingsController.php`
- Modify: `appinfo/routes.php`
- Modify: `src/components/ChangeNotificationsSection.vue`
- Test: `tests/Unit/Service/ChangeNotification/WebhookSignerTest.php`

- [ ] **Step 1: Write the failing signer test**

Create `tests/Unit/Service/ChangeNotification/WebhookSignerTest.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\Service\ChangeNotification\WebhookSigner;
use PHPUnit\Framework\TestCase;

class WebhookSignerTest extends TestCase {

	private WebhookSigner $signer;

	protected function setUp(): void {
		parent::setUp();
		$this->signer = new WebhookSigner();
	}

	public function testSignatureIsHmacSha256OverTimestampNonceAndBody(): void {
		$body = '{"v":1,"cursor":5,"refs":[]}';

		$signature = $this->signer->sign('secret', 1755676800, 'abc123', $body);

		$expected = hash_hmac('sha256', "1755676800.abc123.$body", 'secret');
		$this->assertSame($expected, $signature);
	}

	public function testVerifyAcceptsAValidSignature(): void {
		$body = '{"v":1}';
		$signature = $this->signer->sign('secret', 100, 'n', $body);

		$this->assertTrue($this->signer->verify('secret', 100, 'n', $body, $signature));
	}

	public function testVerifyRejectsATamperedBody(): void {
		$signature = $this->signer->sign('secret', 100, 'n', '{"v":1}');

		$this->assertFalse($this->signer->verify('secret', 100, 'n', '{"v":2}', $signature));
	}

	public function testHeaderValueCarriesAllParts(): void {
		$this->assertSame(
			't=100,n=abc,s=deadbeef',
			$this->signer->headerValue(100, 'abc', 'deadbeef')
		);
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter WebhookSignerTest`
Expected: FAIL — class does not exist

- [ ] **Step 3: Write the signer**

Create `lib/Service/ChangeNotification/WebhookSigner.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

/**
 * HMAC-SHA256 signing for the optional outbound webhook. The canonical string
 * binds timestamp and nonce to the raw body, so a captured request can be
 * neither replayed later (receiver enforces a timestamp window + nonce cache)
 * nor rebound to a different payload.
 *
 * Header: X-Sendent-Signature: t=<unix>,n=<nonce>,s=<hex hmac>
 */
class WebhookSigner {

	public const HEADER = 'X-Sendent-Signature';

	public function sign(string $secret, int $timestamp, string $nonce, string $body): string {
		return hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $body, $secret);
	}

	public function verify(string $secret, int $timestamp, string $nonce, string $body, string $signature): bool {
		return hash_equals($this->sign($secret, $timestamp, $nonce, $body), $signature);
	}

	public function headerValue(int $timestamp, string $nonce, string $signature): string {
		return 't=' . $timestamp . ',n=' . $nonce . ',s=' . $signature;
	}
}
```

- [ ] **Step 4: Write the queued job**

Create `lib/BackgroundJob/SendWebhookSignal.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\BackgroundJob;

use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\WebhookSigner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Delivers one signal to the customer's webhook URL, off the request path.
 * A hint only: the Connector treats it exactly like a websocket frame and
 * still trusts the ledger, so retries are bounded (3) and failures are logged,
 * not escalated.
 *
 * Argument shape: ['signal' => array, 'attempt' => int]
 */
class SendWebhookSignal extends QueuedJob {

	private const MAX_ATTEMPTS = 3;

	public function __construct(
		ITimeFactory $time,
		private ChangeNotificationConfig $config,
		private WebhookSigner $signer,
		private IClientService $clientService,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		$signal = $argument['signal'] ?? null;
		$attempt = (int)($argument['attempt'] ?? 1);

		if (!is_array($signal) || !$this->config->webhookEnabled()) {
			return;
		}

		$url = $this->config->webhookUrl();
		$secret = $this->config->webhookSecret();
		$body = json_encode($signal, JSON_THROW_ON_ERROR);
		$timestamp = $this->time->getTime();
		$nonce = bin2hex(random_bytes(8));

		try {
			$client = $this->clientService->newClient();
			$response = $client->post($url, [
				'body' => $body,
				'timeout' => 10,
				'headers' => [
					'Content-Type' => 'application/json',
					WebhookSigner::HEADER => $this->signer->headerValue(
						$timestamp,
						$nonce,
						$this->signer->sign($secret, $timestamp, $nonce, $body)
					),
				],
			]);
			$status = $response->getStatusCode();
			if ($status >= 200 && $status < 300) {
				return;
			}
			$this->retryOrGiveUp($signal, $attempt, 'HTTP ' . $status);
		} catch (\Throwable $e) {
			$this->retryOrGiveUp($signal, $attempt, $e->getMessage());
		}
	}

	/** @param array<string, mixed> $signal */
	private function retryOrGiveUp(array $signal, int $attempt, string $reason): void {
		if ($attempt >= self::MAX_ATTEMPTS) {
			$this->logger->warning('Webhook signal dropped after ' . $attempt . ' attempts: ' . $reason, [
				'app' => 'sendentsynchroniser',
			]);
			return;
		}

		$this->jobList->add(self::class, ['signal' => $signal, 'attempt' => $attempt + 1]);
	}
}
```

- [ ] **Step 5: Queue the webhook from the publisher**

In `lib/Service/ChangeNotification/SignalPublisher.php`:

Add one constructor parameter after `private SignalMetrics $metrics,` (the
`ChangeNotificationConfig` is already injected as `$config`):

```php
		private \OCP\BackgroundJob\IJobList $jobList,
```

In `flush()`, replace everything from `if (!$this->transport->publish($signal))` down to the closing `return $signal;` with:

```php
		// Two independent hint channels; a signal counts as delivered when
		// EITHER accepted it. In particular, a webhook-only setup (no bot
		// user / no notify_push) must still advance the watermark, or the
		// sweeper would re-deliver the same batch every cron run forever.
		$delivered = $this->transport->publish($signal);

		if ($this->config->webhookEnabled()) {
			$this->jobList->add(
				\OCA\SendentSynchroniser\BackgroundJob\SendWebhookSignal::class,
				['signal' => $signal, 'attempt' => 1]
			);
			$delivered = true;
		}

		if (!$delivered) {
			return null;
		}

		$this->config->setFlushedSeq($cursor);
		$this->config->setLastSignalAt($this->time->getTime());
		$this->metrics->recordFlush(count($refs), $truncated);

		return $signal;
```

Update `SignalPublisherTest`: add an `IJobList` mock as the new constructor argument, and stub `$this->config->method('webhookEnabled')->willReturn(false);` in `setUp()` — with the webhook off, every existing assertion (including "the watermark does not advance when publish fails") holds unchanged. Add one new test: with `webhookEnabled` returning true and `transport->publish` returning false, `flush()` still returns the signal, expects `jobList->add` once, and expects `setFlushedSeq` once.

- [ ] **Step 6: Webhook admin settings (with sensitive secret storage and a test send)**

In `lib/Controller/ChangeNotificationSettingsController.php`:

Add two constructor parameters after `private \OCP\AppFramework\Utility\ITimeFactory $time,` (from Task 22):

```php
		private \OCP\IAppConfig $globalAppConfig,
		private \OCP\BackgroundJob\IJobList $jobList,
```

(`\OCP\IAppConfig` is the *global* app-config interface, distinct from the app-scoped `OCP\AppFramework\Services\IAppConfig` used elsewhere; it is the only one with a sensitivity API. Add both mocks to the controller test's `setUp()`.)

Add the methods:

```php
	public function setWebhook(string $url, string $secret, bool $enabled): DataResponse {
		if ($enabled && !str_starts_with($url, 'https://')) {
			return new DataResponse(['message' => 'Webhook URL must be https'], Http::STATUS_BAD_REQUEST);
		}

		$this->config->setWebhookUrl($url);
		if ($secret !== '') {
			// Empty secret in the payload means "keep the stored one".
			$this->config->setWebhookSecret($secret);
			// Spec §10: secrets are sensitive IAppConfig values (redacted from
			// occ config:list and system reports). updateSensitive() exists
			// since NC 29; on NC 28 the guard skips it — accepted, documented.
			if (method_exists($this->globalAppConfig, 'updateSensitive')) {
				$this->globalAppConfig->updateSensitive(
					'sendentsynchroniser',
					\OCA\SendentSynchroniser\Constants::CN_WEBHOOK_SECRET_KEY,
					true
				);
			}
		}
		$this->config->setWebhookEnabled($enabled);

		return new DataResponse(['enabled' => $this->config->webhookEnabled()]);
	}

	/** "Send test" button: queues one synthetic signal through the webhook path. */
	public function sendTestWebhook(): DataResponse {
		if (!$this->config->webhookEnabled()) {
			return new DataResponse(['message' => 'Webhook is not enabled'], Http::STATUS_BAD_REQUEST);
		}

		$this->jobList->add(
			\OCA\SendentSynchroniser\BackgroundJob\SendWebhookSignal::class,
			['signal' => ['v' => 1, 'test' => true, 'prev' => 0, 'cursor' => 0, 'truncated' => false, 'refs' => []], 'attempt' => 1]
		);

		return new DataResponse(['queued' => true]);
	}
```

Routes in `appinfo/routes.php`:

```php
		['name' => 'change_notification_settings#setWebhook', 'url' => '/api/1.0/settings/cnWebhook', 'verb' => 'POST'],
		['name' => 'change_notification_settings#sendTestWebhook', 'url' => '/api/1.0/settings/cnWebhookTest', 'verb' => 'POST'],
```

UI in `src/components/ChangeNotificationsSection.vue` — add a field group after Batching, following the same idiom:

```html
		<!-- Optional webhook -->
		<div class="settings-section__field">
			<label>{{ t('sendentsynchroniser', 'Outbound webhook (optional)') }}</label>
			<div class="settings-section__input-row">
				<input v-model="webhookUrl"
					type="url"
					class="settings-section__input"
					:placeholder="t('sendentsynchroniser', 'https://connector.example.com/signals')"
					@change="saveWebhook">
				<input v-model="webhookSecret"
					type="password"
					class="settings-section__input"
					:placeholder="t('sendentsynchroniser', 'Shared secret (leave empty to keep current)')"
					@change="saveWebhook">
				<select v-model="webhookEnabled" @change="saveWebhook">
					<option value="true">{{ t('sendentsynchroniser', 'Enabled') }}</option>
					<option value="false">{{ t('sendentsynchroniser', 'Disabled') }}</option>
				</select>
				<button type="button"
					:disabled="webhookEnabled !== 'true'"
					@click="sendTestWebhook">
					{{ t('sendentsynchroniser', 'Send test') }}
				</button>
				<span v-if="saved.webhook" class="settings-section__saved">&#x2713;</span>
			</div>
			<p class="settings-section__hint">
				{{ t('sendentsynchroniser', 'Additionally POST each signal to this URL, signed with HMAC-SHA256. A hint only — the Connector still reads the change feed. Requires Nextcloud to reach the Connector.') }}
			</p>
		</div>
```

with the state and functions (the URL and enabled flag come from initial state; the secret is write-only and never sent to the page):

```ts
const webhookUrl = ref(props.initialWebhookUrl)
const webhookSecret = ref('')
const webhookEnabled = ref(props.initialWebhookEnabled === 'true' ? 'true' : 'false')

/** */
function saveWebhook() {
	saveSetting('cnWebhook', {
		url: webhookUrl.value,
		secret: webhookSecret.value,
		enabled: webhookEnabled.value === 'true' ? 1 : 0,
	}, 'webhook')
}

/** */
async function sendTestWebhook() {
	try {
		const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/cnWebhookTest')
		await axios.post(url)
		showSaved('webhook')
	} catch {
		console.error('Webhook test failed')
	}
}
```

Extend the component's `defineProps` with:

```ts
	initialWebhookUrl: string
	initialWebhookEnabled: string
```

Thread the initial state through the same three files as Task 16 — in `lib/Settings/Admin.php` (`getParams()`):

```php
		$params['cnWebhookUrl'] = $this->appConfig->getAppValue(Constants::CN_WEBHOOK_URL_KEY, '');
		$params['cnWebhookEnabled'] = $this->appConfig->getAppValue(Constants::CN_WEBHOOK_ENABLED_KEY, 'false');
```

in `src/settings.ts` (AdminSettings props object):

```ts
		cnWebhookUrl: (state.cnWebhookUrl as string) || '',
		cnWebhookEnabled: (state.cnWebhookEnabled as string) || 'false',
```

and in `src/components/AdminSettings.vue`: add `cnWebhookUrl: string` and `cnWebhookEnabled: string` to `defineProps`, and pass `:initial-webhook-url="cnWebhookUrl" :initial-webhook-enabled="cnWebhookEnabled"` on the `<ChangeNotificationsSection>` element.

- [ ] **Step 7: Run tests, compile frontend**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter 'WebhookSignerTest|SignalPublisherTest'`
Expected: PASS

```powershell
$env:NODE_ENV='production'; node node_modules/webpack/bin/webpack.js --config webpack.prod.js
```

Expected: `compiled successfully`

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat(cn): optional HMAC-signed outbound webhook"
```

---

### Task 24: Health and diagnostics polish

**Files:**
- Modify: `lib/Controller/ChangeFeedApiController.php`
- Modify: `tests/Unit/Controller/ChangeFeedApiControllerTest.php`

- [ ] **Step 1: Extend the health payload with the metrics block**

Add a `SignalMetrics` constructor parameter to `ChangeFeedApiController` (after `private NotifyPushAvailability $availability,`):

```php
		private \OCA\SendentSynchroniser\Service\ChangeNotification\SignalMetrics $metrics,
```

In `health()`, add to the response array:

```php
			'signals_last_hour' => $this->metrics->lastHour(),
```

- [ ] **Step 2: Update the controller test**

In `ChangeFeedApiControllerTest::setUp()`, create the mock and pass it to the constructor; in `testHealthReportsTransportAndLag`, stub:

```php
		$this->metrics->method('lastHour')
			->willReturn(['flushes' => 3412, 'refs' => 63463, 'truncated' => 2, 'max_refs' => 500]);
```

and assert:

```php
		$this->assertSame(3412, $data['signals_last_hour']['flushes']);
```

- [ ] **Step 3: Render the metrics in the settings diagnostics (spec §8)**

In `src/components/ChangeNotificationsSection.vue`:

Extend the `Health` interface with (optional — Phase 1 servers do not send it):

```ts
	signals_last_hour?: { flushes: number, refs: number, truncated: number, max_refs: number }
```

In the Diagnostics `cn-status` block, after the "Connector acknowledged" line, add:

```html
				<div v-if="health.signals_last_hour" class="cn-status__line">
					{{ t('sendentsynchroniser', 'Signals last hour: {n} flushes · avg {avg} refs/signal · max {max} (truncated ×{tr})', {
						n: String(health.signals_last_hour.flushes),
						avg: health.signals_last_hour.flushes > 0
							? (health.signals_last_hour.refs / health.signals_last_hour.flushes).toFixed(1)
							: '0',
						max: String(health.signals_last_hour.max_refs),
						tr: String(health.signals_last_hour.truncated),
					}) }}
				</div>
```

Verify the frontend compiles:

```powershell
$env:NODE_ENV='production'; node node_modules/webpack/bin/webpack.js --config webpack.prod.js
```

Expected: `compiled successfully`

- [ ] **Step 4: Run tests**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter ChangeFeedApiControllerTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add lib/Controller/ChangeFeedApiController.php src/components/ChangeNotificationsSection.vue tests/Unit/Controller/ChangeFeedApiControllerTest.php
git commit -m "feat(cn): surface flush metrics in health endpoint and admin diagnostics"
```

---

## Phase 3 — Hardening

### Task 25: Principal allow-list

**Files:**
- Create: `lib/Service/ChangeNotification/PrincipalAllowList.php`
- Modify: `lib/Controller/ChangeFeedApiController.php` (filter + upload endpoint)
- Modify: `appinfo/routes.php`
- Test: `tests/Unit/Service/ChangeNotification/PrincipalAllowListTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Service/ChangeNotification/PrincipalAllowListTest.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\PrincipalAllowList;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PrincipalAllowListTest extends TestCase {

	/** @var IAppConfig&MockObject */
	private $appConfig;

	/** @var ChangeNotificationConfig&MockObject */
	private $config;

	/** @var array<string, string> */
	private array $values = [];

	private PrincipalAllowList $list;

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getAppValue')->willReturnCallback(
			fn (string $key, $default = '') => $this->values[$key] ?? $default
		);
		$this->appConfig->method('setAppValue')->willReturnCallback(
			function (string $key, string $value): void {
				$this->values[$key] = $value;
			}
		);
		$this->config = $this->createMock(ChangeNotificationConfig::class);
		$this->list = new PrincipalAllowList($this->appConfig, $this->config);
	}

	public function testDisabledListAllowsEveryPrincipal(): void {
		$this->config->method('allowListEnabled')->willReturn(false);

		$this->assertTrue($this->list->isAllowed('principals/users/anyone'));
	}

	public function testEnabledListAllowsOnlyListedPrincipals(): void {
		$this->config->method('allowListEnabled')->willReturn(true);
		$this->list->replace(['principals/users/alice', 'principals/users/bob']);

		$this->assertTrue($this->list->isAllowed('principals/users/alice'));
		$this->assertFalse($this->list->isAllowed('principals/users/mallory'));
	}

	public function testEnabledButEmptyListAllowsNothing(): void {
		$this->config->method('allowListEnabled')->willReturn(true);

		$this->assertFalse($this->list->isAllowed('principals/users/alice'));
	}

	public function testReplaceDeduplicatesAndDropsEmptyEntries(): void {
		$this->config->method('allowListEnabled')->willReturn(true);
		$this->list->replace(['principals/users/alice', 'principals/users/alice', '', 'principals/users/bob']);

		$this->assertSame(2, $this->list->count());
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter PrincipalAllowListTest`
Expected: FAIL — class does not exist

- [ ] **Step 3: Write the service**

Create `lib/Service/ChangeNotification/PrincipalAllowList.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\Constants;
use OCP\AppFramework\Services\IAppConfig;

/**
 * Optional privacy hardening: when a customer considers collection URIs
 * sensitive, the Connector uploads the principals it actually maps, and
 * /changes stops returning refs for anyone else. Off by default; disabled
 * means allow-all.
 *
 * Fail-closed on purpose: enabled + empty = nothing is returned, so a
 * Connector that enables the list before uploading it sees an empty feed
 * rather than a leak.
 */
class PrincipalAllowList {

	public function __construct(
		private IAppConfig $appConfig,
		private ChangeNotificationConfig $config,
	) {}

	public function isAllowed(string $principalUri): bool {
		if (!$this->config->allowListEnabled()) {
			return true;
		}

		return in_array($principalUri, $this->principals(), true);
	}

	/** @param string[] $principals */
	public function replace(array $principals): void {
		$clean = array_values(array_unique(array_filter($principals, static fn ($p) => is_string($p) && $p !== '')));
		$this->appConfig->setAppValue(Constants::CN_ALLOWLIST_KEY, json_encode($clean, JSON_THROW_ON_ERROR));
	}

	public function count(): int {
		return count($this->principals());
	}

	/** @return string[] */
	private function principals(): array {
		$raw = json_decode((string)$this->appConfig->getAppValue(Constants::CN_ALLOWLIST_KEY, '[]'), true);

		return is_array($raw) ? $raw : [];
	}
}
```

- [ ] **Step 4: Filter the feed and accept uploads**

In `lib/Controller/ChangeFeedApiController.php`:

Add the constructor parameter (after `$metrics`):

```php
		private \OCA\SendentSynchroniser\Service\ChangeNotification\PrincipalAllowList $allowList,
```

In `changes()`, replace the `foreach ($rows as $row)` loop body with:

```php
		foreach ($rows as $row) {
			$cursor = max($cursor, (int)$row->getChangeSeq());
			$ref = $row->toReference($since);
			if (!$this->allowList->isAllowed($ref->principalUri)) {
				continue; // cursor still advances: filtered rows must not wedge paging
			}
			$refs[] = $ref->jsonSerialize();
		}
```

Add the upload endpoint:

```php
	/**
	 * The Connector uploads the principals it maps; only those appear in
	 * /changes afterwards (when the allow-list is enabled in settings).
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @param string[] $principals
	 */
	public function setAllowList(array $principals = []): DataResponse {
		if (!$this->guard->isAllowed()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$this->allowList->replace($principals);

		return new DataResponse(['count' => $this->allowList->count()]);
	}
```

Route in `appinfo/routes.php`:

```php
		['name' => 'change_feed_api#setAllowList', 'url' => '/api/1.0/notify/allowlist', 'verb' => 'PUT'],
```

Update `ChangeFeedApiControllerTest::setUp()` with the new mock (stub `isAllowed` → `true` by default) and add one test: with the allow-list rejecting `principals/users/alice`, `changes()` returns zero refs but still advances the cursor.

- [ ] **Step 5: Run tests**

Run: `php vendor/bin/phpunit -c phpunit.xml --filter 'PrincipalAllowListTest|ChangeFeedApiControllerTest'`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add lib/Service/ChangeNotification/PrincipalAllowList.php lib/Controller/ChangeFeedApiController.php appinfo/routes.php tests/Unit/Service/ChangeNotification/PrincipalAllowListTest.php tests/Unit/Controller/ChangeFeedApiControllerTest.php
git commit -m "feat(cn): optional principal allow-list for the change feed"
```

---

### Task 26: The load-test harness

**Files:**
- Create: `lib/Command/ChangeNotificationLoadTest.php`
- Modify: `appinfo/info.xml`

- [ ] **Step 1: Write the command**

Create `lib/Command/ChangeNotificationLoadTest.php`:

```php
<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Command;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\SignalPublisher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ sendentsynchroniser:cn-loadtest --events=350 --seconds=60 --users=10000
 *
 * Drives the ledger + batch-window path at a target event rate with synthetic
 * references (no DAV objects are created), and reports achieved rate and
 * per-event latency. Validates the §9 capacity model: at 350 ev/s the write
 * path must stay in the low milliseconds and flushes must stay ~1 per window.
 *
 * Synthetic refs use the reserved principal prefix below so a test run is
 * distinguishable in the ledger; run against staging, not production.
 */
class ChangeNotificationLoadTest extends Command {

	private const PRINCIPAL_PREFIX = 'principals/users/cn-loadtest-';

	public function __construct(
		private ChangeLedgerService $ledger,
		private SignalPublisher $publisher,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:cn-loadtest')
			->setDescription('Drive synthetic change events through the ledger at a target rate')
			->addOption('events', null, InputOption::VALUE_REQUIRED, 'Target events per second', '350')
			->addOption('seconds', null, InputOption::VALUE_REQUIRED, 'Duration', '60')
			->addOption('users', null, InputOption::VALUE_REQUIRED, 'Distinct synthetic users', '10000');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$rate = max(1, (int)$input->getOption('events'));
		$seconds = max(1, (int)$input->getOption('seconds'));
		$users = max(1, (int)$input->getOption('users'));

		$total = 0;
		$latencies = [];
		$start = microtime(true);

		for ($s = 0; $s < $seconds; $s++) {
			$secondStart = microtime(true);
			for ($i = 0; $i < $rate; $i++) {
				$user = self::PRINCIPAL_PREFIX . (($total * 7919) % $users);
				$ref = new CollectionReference(
					$user,
					($total % 3 === 0) ? Constants::COLLECTION_TYPE_CARDDAV : Constants::COLLECTION_TYPE_CALDAV,
					($total % 3 === 0) ? 'contacts' : 'personal',
					$total,
					$total % 500 === 0
				);

				$t0 = microtime(true);
				$this->ledger->record([$ref]);
				$this->publisher->flushIfDue();
				$latencies[] = (microtime(true) - $t0) * 1000;
				$total++;
			}
			// Hold the target rate; if the second overran, keep going flat out.
			$elapsed = microtime(true) - $secondStart;
			if ($elapsed < 1.0) {
				usleep((int)((1.0 - $elapsed) * 1e6));
			}
			$output->writeln(sprintf('second %d: %d events', $s + 1, $total));
		}

		sort($latencies);
		$wall = microtime(true) - $start;
		$p = static fn (float $q) => $latencies[(int)floor($q * (count($latencies) - 1))];

		$output->writeln('');
		$output->writeln('events_total: ' . $total);
		$output->writeln(sprintf('achieved_rate: %.1f/s (target %d/s)', $total / $wall, $rate));
		$output->writeln(sprintf('latency_ms p50: %.2f p95: %.2f p99: %.2f max: %.2f', $p(0.5), $p(0.95), $p(0.99), $p(1.0)));
		$output->writeln('Check flush behaviour with: occ sendentsynchroniser:cn-status');

		return 0;
	}
}
```

- [ ] **Step 2: Register the command**

In `appinfo/info.xml`, inside `<commands>`:

```xml
        <command>OCA\SendentSynchroniser\Command\ChangeNotificationLoadTest</command>
```

- [ ] **Step 3: Run it against the staging instance**

```bash
php occ sendentsynchroniser:cn-loadtest --events=350 --seconds=60 --users=10000
php occ sendentsynchroniser:cn-status
```

Record in the commit message (or a follow-up note): achieved rate, p95/p99 latency, flushes in the window, whether signals went truncated. Acceptance per §9: achieved ≥ 350/s, p95 ≤ 5 ms, flushes ≈ seconds / batch-window.

- [ ] **Step 4: Clean the synthetic rows**

```sql
DELETE FROM oc_sndntsync_dirty WHERE principal_uri LIKE 'principals/users/cn-loadtest-%';
```

(via the DB console, or `php occ db:execute-query` where the target NC version ships it — staging only.)

- [ ] **Step 5: Commit**

```bash
git add lib/Command/ChangeNotificationLoadTest.php appinfo/info.xml
git commit -m "feat(cn): synthetic load-test command for the ledger write path"
```

---

### Task 27: Documentation and the Connector wire contract

**Files:**
- Create: `docs/change-notifications.md`
- Create: `docs/connector-change-feed-contract.md`

- [ ] **Step 1: Write the admin/operations doc**

Create `docs/change-notifications.md` covering, in this order (write real prose, not bullets-of-bullets):

1. **What it is** — signal-then-pull model; signals carry references only; the ledger under both transports; one paragraph per §1.2 design principle.
2. **Setup on AIO** — notify_push preinstalled; create bot user, generate its app password, `occ sendentsynchroniser:cn-setup --bot-user=… --transport=auto`; websocket proxy note for external reverse proxies.
3. **Setup on Helm/Docker/bare-metal** — enabling notify_push, `notify_push:setup`; the no-Redis path (automatic polling; DB sequence).
4. **The admin settings page** — every field, its default and bounds, what "Run test" checks, what the diagnostics numbers mean.
5. **occ commands** — cn-status (with a sample output block and what each line means), cn-flush, cn-setup (including --reseed and when it is needed), cn-loadtest.
6. **Monitoring** — `/api/1.0/notify/health` fields; the notify_push side (`occ notify_push:metrics` "Messages sent (custom)").
7. **Failure modes** — daemon down (auto mode flips to polling within CN_DAEMON_CHECK_TTL), Redis flush (cursor reseeds from ledger), lost signals (catch-up + 6-hourly Connector reconcile), NC 31 double events (deduped).

- [ ] **Step 2: Write the wire contract for the .NET team**

Create `docs/connector-change-feed-contract.md` with exactly these sections:

```markdown
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
after the admin enables it in settings. Enabled + empty list = empty feed
(fail closed). Upload before enabling.
```

- [ ] **Step 3: Cross-check the contract against the code**

Verify every field name in the contract appears in `SignalBuilder::build()`, `ChangeFeedApiController::config()`, `changes()` and `health()` — no drift between doc and implementation.

- [ ] **Step 4: Commit**

```bash
git add docs/change-notifications.md docs/connector-change-feed-contract.md
git commit -m "docs(cn): admin guide and Connector wire contract v1"
```

---

## Follow-ups deliberately out of scope

- The .NET Connector implementation (transport client, catch-up, sharding, per-mailbox scheduler, reconcile — spec §5): separate plan in the Connector repo, built against `docs/connector-change-feed-contract.md`.
- Sharee-collection signals (deviation 8): revisit after the Connector's owner→mailbox expansion exists.
- Spec §12 open item 3 (`CalendarObjectDeletedEvent` reliability on NC 31–34) is mitigated by the Connector's reconcile; verify empirically during Phase 1 Task 18 step 3 by also deleting an event.
- Prometheus metrics export beyond `cn-status`'s parseable output.

## Compatibility notes for the implementer

- `OCP\AppFramework\Services\IAppConfig` (the app-scoped one this codebase already uses) is injected everywhere; do not switch to `OCP\IAppConfig` mid-plan.
- `getAppValue`/`setAppValue` deprecation warnings on newer NC are accepted for consistency with the rest of this app.
- Sensitive values: `cnWebhookSecret` is marked sensitive via `\OCP\IAppConfig::updateSensitive()` in Task 23 — the API exists since NC 29; on NC 28 the `method_exists` guard skips it (accepted, documented in the admin doc).
- Event classes referenced but absent on a given NC version are never dispatched; `instanceof` on them is safe. Do not add `class_exists` guards around `registerEventListener` calls.
- The `@NoAdminRequired` + `@NoCSRFRequired` annotation style matches this codebase (annotations, not attributes) — keep it consistent even though attributes exist on newer NC.
</content>

---

## Post-review amendments (applied during execution)

The final adversarial review of the merged branch surfaced three fixes now in the code but not in the task bodies above:

1. **Webhook job-argument size guard (amends Task 23).** Nextcloud's `JobList::add()` rejects arguments whose JSON exceeds 32,000 chars. `SignalPublisher::flush()` therefore measures the signal and queues the truncated wire frame (`refs: [], truncated: true`) for the webhook channel when the full signal would exceed `MAX_WEBHOOK_ARGUMENT_BYTES` (30,000); the enqueue is additionally wrapped in try/catch so a failed enqueue can never wedge the watermark. Covered by `testAnOversizedWebhookSignalIsQueuedTruncated`.

2. **Reachable allow-list toggle (amends Tasks 20/25).** `cnAllowListEnabled` previously had no writer. `occ sendentsynchroniser:cn-setup --allowlist=on|off` now toggles it; both docs name that path instead of a nonexistent settings control.

3. **`SendWebhookSignal` unit tests (amends Task 23).** The retry chain (attempt-increment defeating NC's job-argument dedup, 2xx no-retry, give-up at 3 attempts, disabled guard, signature header) is pinned by `tests/Unit/BackgroundJob/SendWebhookSignalTest.php`.

Also applied from review: visible save errors + honest enabled-state revert in the webhook UI, `publish_test` line in `cn-status`, a 100k cap in `PrincipalAllowList::replace()`, and the reseed floor `max(highWaterMark, flushedSeq)` in `cn-setup`.

Deferred (tracked, deliberate): cn-flush exit-code conflation of "nothing to flush" vs "publish failed"; a11y label associations + aria-live in the settings section; batching inputs not writing back server-clamped values; truncated flushes recording 0 refs in SignalMetrics (charter: approximate gauge).

### Amendments from the notify_push validation pass (systematic-debugging, post-merge)

Task 1's open items were closed by **source verification** of nextcloud/notify_push instead of the live spike (findings in `docs/superpowers/research/2026-08-20-notify-push-spike.md`): Custom messages are **exempt from the daemon's debounce**, and the per-connection channel is a **bounded broadcast(4), drop-oldest** — the exact loss mode the `prev` field detects. The queue contract (`notify_custom`, `{user, message, body}`), wire frame (`"sendent_sync {json}"`), and `NullQueue` FQCN were all confirmed exact.

Fixes applied from the same pass: pinned **Force polling** now truly disables the push path (transport gate + `flushIfDue()` early-out + sweeper gate — previously every window still read the ledger and published to nobody); `NotifyPushTransportTest` (new, 5 tests) pins the verified queue-message shape; the contract covers body-less frames from ancient notify_push versions as bare hints; docs note the current-notify_push requirement. The live round-trip check remains in Task 18's E2E list.
