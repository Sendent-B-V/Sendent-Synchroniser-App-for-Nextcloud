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
 * Decides whether transport A (notify_push) is usable, and resolves its
 * queue. Checks, in order of cost: app enabled; IQueue resolves to a real
 * queue (NullQueue means Redis isn't the distributed cache); the daemon
 * answers /test/cookie (cached for CN_DAEMON_CHECK_TTL so the DAV write path
 * never blocks on HTTP).
 *
 * notify_push is optional: every reference to its classes is by string
 * through the container, inside try/catch.
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

	public function isActive(): bool {
		$mode = $this->config->transportMode();
		if ($mode === Constants::TRANSPORT_POLLING) {
			return false;
		}

		$queue = $this->queue();

		// Force-notify_push publishes even while unhealthy: the admin pinned
		// it, and the settings page shows a persistent warning either way.
		if ($mode === Constants::TRANSPORT_NOTIFY_PUSH) {
			return $queue !== null;
		}

		return $queue !== null && $this->cachedDaemonCheck()['ok'];
	}

	public function effectiveTransport(): string {
		return $this->isActive() ? Constants::TRANSPORT_NOTIFY_PUSH : Constants::TRANSPORT_POLLING;
	}

	/**
	 * However stale — better a possibly-outdated push than an HTTP probe per DAV write.
	 * @return array{ok: bool, at: int, message: string}
	 */
	public function cachedDaemonCheck(): array {
		return $this->config->daemonCheck();
	}

	/** Called from the settings controller and the self-test TimedJob — never the DAV write path. */
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
			// http_errors=false: notify_push guards /test/* with a per-run token,
			// so an unauthenticated probe legitimately gets 4xx (Guzzle would
			// otherwise throw) — a POSITIVE liveness signal. 5xx means a dead
			// backend behind a proxy; deep health is `occ notify_push:self-test`'s job.
			$response = $client->get($base . '/test/cookie', [
				'timeout' => 5,
				'http_errors' => false,
				'nextcloud' => ['allow_local_address' => true],
			]);
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

		// notify_push writes this during `occ notify_push:setup`.
		$endpoint = $this->serverConfig->getAppValue(Constants::CN_NOTIFY_PUSH_APPID, 'base_endpoint', '');

		return $endpoint !== '' ? rtrim($endpoint, '/') : null;
	}
}
