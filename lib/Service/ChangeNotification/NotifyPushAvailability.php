<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\Constants;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Container\ContainerInterface;

/**
 * Whether notify_push is offered to the Connector, and its queue.
 *
 * Like Nextcloud's own advertisement to its clients, this is about
 * configuration, not health: offered when the app is enabled, its queue is
 * real (NullQueue means no Redis) and its endpoint is set up. A Connector that
 * cannot connect polls until it can. probeDaemon() checks the daemon on
 * demand for the setup check; nothing on the DAV write path calls it.
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

	public function effectiveTransport(): string {
		if ($this->config->transportMode() === Constants::TRANSPORT_POLLING) {
			return Constants::TRANSPORT_POLLING;
		}

		return $this->queue() !== null && $this->websocketUrl() !== null
			? Constants::TRANSPORT_NOTIFY_PUSH
			: Constants::TRANSPORT_POLLING;
	}

	/** @return array{ok: bool, message: string} */
	public function probeDaemon(): array {
		$base = $this->baseEndpoint();
		if ($base === null) {
			return ['ok' => false, 'message' => 'notify_push app or its endpoint not available'];
		}

		try {
			// http_errors=false: notify_push guards /test/* with a per-run token,
			// so an unauthenticated probe legitimately gets 4xx (Guzzle would
			// otherwise throw) — a POSITIVE liveness signal. 5xx means a dead
			// backend behind a proxy; deep health is `occ notify_push:self-test`'s job.
			$response = $this->clientService->newClient()->get($base . '/test/cookie', [
				'timeout' => 5,
				'http_errors' => false,
				'nextcloud' => ['allow_local_address' => true],
			]);
			$status = $response->getStatusCode();
		} catch (\Throwable $e) {
			return ['ok' => false, 'message' => 'daemon unreachable: ' . substr($e->getMessage(), 0, 500)];
		}

		return $status < 500
			? ['ok' => true, 'message' => 'daemon reachable (HTTP ' . $status . ')']
			: ['ok' => false, 'message' => 'gateway reports backend down (HTTP ' . $status . ')'];
	}

	/** ws:// or wss:// URL the Connector should open, advertised via /config. */
	public function websocketUrl(): ?string {
		$base = $this->baseEndpoint();
		if ($base === null || !str_starts_with($base, 'http')) {
			return null; // no endpoint, or a malformed one: better no ws_url than a nonsensical one
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
