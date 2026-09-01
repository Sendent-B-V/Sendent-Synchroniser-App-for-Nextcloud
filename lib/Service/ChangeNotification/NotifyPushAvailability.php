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
 * The browser publish test from the admin settings is informational only
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
			// http_errors=false: the client must hand 4xx back as a response
			// (Guzzle throws on it by default), because a 4xx is a POSITIVE
			// liveness signal here — see below.
			$response = $client->get($base . '/test/cookie', [
				'timeout' => 5,
				'http_errors' => false,
				'nextcloud' => ['allow_local_address' => true],
			]);
			// Liveness only: current notify_push guards /test/* with a per-run
			// token its own self-test shares over Redis, so an unauthenticated
			// probe legitimately gets a 4xx — which still proves a daemon is
			// answering at base_endpoint. 5xx is a proxy fronting a dead
			// backend. Deep health stays `occ notify_push:self-test`'s job.
			$status = $response->getStatusCode();
			$ok = $status < 500;
			$message = $ok
				? ('daemon reachable (HTTP ' . $status . ')')
				: ('gateway reports backend down (HTTP ' . $status . ')');
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
