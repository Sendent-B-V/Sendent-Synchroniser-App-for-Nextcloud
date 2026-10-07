<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Listener\DavChangeListener;

/**
 * The setup diagnostic behind the settings page's "Run test" and
 * `occ sendentsynchroniser:cn-check`: does this server have the change events,
 * and is notify_push usable here. Probes the daemon live, on demand only.
 *
 * Whether the Connector is connected shows in Nextcloud itself:
 * `occ user:auth-tokens:list <bot user>` lists its app password's last activity.
 */
class SetupCheck {

	public function __construct(
		private ChangeNotificationConfig $config,
		private NotifyPushAvailability $availability,
	) {}

	/**
	 * ok: the change feed works here. Without usable notify_push it still
	 * does, the Connector polls; notify_push.ok reports that separately.
	 *
	 * @return array{ok: bool, server_supported: bool, notify_push: array<string, mixed>}
	 */
	public function run(): array {
		$serverSupported = $this->serverSupportsChangeEvents();
		$notifyPush = $this->checkNotifyPush();

		return [
			'ok' => $serverSupported,
			'server_supported' => $serverSupported,
			'notify_push' => $notifyPush,
		];
	}

	/**
	 * The same gate that decides whether the listener is registered at all.
	 * Protected so unit tests can pin either answer.
	 */
	protected function serverSupportsChangeEvents(): bool {
		return DavChangeListener::serverSupported();
	}

	/** @return array<string, mixed> */
	private function checkNotifyPush(): array {
		$appEnabled = $this->availability->isAppEnabled();
		$queueAvailable = $this->availability->queue() !== null;
		$daemon = $appEnabled
			? $this->availability->probeDaemon()
			: ['ok' => false, 'message' => 'notify_push is not installed'];
		$wsUrl = $this->availability->websocketUrl();
		$websocketSecure = $wsUrl !== null && str_starts_with($wsUrl, 'wss://');

		$pushHealthy = $appEnabled && $queueAvailable && $daemon['ok'];
		$pinnedPolling = $this->config->transportMode() === Constants::TRANSPORT_POLLING;

		if ($pinnedPolling) {
			$message = 'transport pinned to polling; notify_push is not required';
		} elseif ($pushHealthy) {
			$message = $websocketSecure
				? 'notify_push available'
				: 'notify_push available, but the websocket endpoint is not wss:// — use TLS in production';
		} elseif (!$appEnabled) {
			$message = 'notify_push app is not installed — the Connector will poll';
		} elseif (!$queueAvailable) {
			$message = 'notify_push has no usable queue (Redis missing?) — the Connector will poll';
		} else {
			$message = $daemon['message'] . ' — the Connector will poll until it is back';
		}

		return [
			'ok' => $pushHealthy || $pinnedPolling,
			'app_enabled' => $appEnabled,
			'queue_available' => $queueAvailable,
			'daemon' => $daemon,
			'ws_url' => $wsUrl,
			'websocket_secure' => $websocketSecure,
			'effective_transport' => $this->availability->effectiveTransport(),
			'message' => $message,
		];
	}
}
