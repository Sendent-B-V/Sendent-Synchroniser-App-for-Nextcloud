<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\Constants;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * "Is everything set up correctly?" diagnostic. notify_push availability
 * (app enabled, real queue, daemon reachable, wss:// URL) is the pass/fail
 * layer; Connector visibility is informational only, inferred from
 * /notify/ack calls — Nextcloud never reaches the Connector directly, so no
 * reachability probe is attempted.
 *
 * Admin-triggered only (settings button, save hook, occ cn-check) — never on
 * the DAV write path.
 */
class ConnectorSetupCheck {

	/** Acks older than this stop counting as "recently seen". */
	private const SEEN_RECENTLY_SECONDS = 86400;

	public function __construct(
		private ChangeNotificationConfig $config,
		private NotifyPushAvailability $availability,
		private ITimeFactory $time,
	) {}

	/** @return array{ok: bool, checked_at: int, notify_push: array<string, mixed>, connector: array<string, mixed>} */
	public function run(): array {
		$notifyPush = $this->checkNotifyPush();

		return [
			'ok' => $notifyPush['ok'],
			'checked_at' => $this->time->getTime(),
			'notify_push' => $notifyPush,
			'connector' => $this->connectorInfo(),
		];
	}

	/** @return array<string, mixed> */
	private function checkNotifyPush(): array {
		$appEnabled = $this->availability->isAppEnabled();
		$queueAvailable = $this->availability->queue() !== null;
		$daemon = $appEnabled
			? $this->availability->refreshDaemonCheck()
			: ['ok' => false, 'at' => 0, 'message' => 'notify_push is not installed'];
		$wsUrl = $this->availability->websocketUrl();
		$websocketSecure = $wsUrl !== null && str_starts_with($wsUrl, 'wss://');

		$pushHealthy = $appEnabled && $queueAvailable && $daemon['ok'];
		$pinnedPolling = $this->config->transportMode() === Constants::TRANSPORT_POLLING;

		if ($pushHealthy) {
			$message = $websocketSecure
				? 'notify_push available'
				: 'notify_push available, but the websocket endpoint is not wss:// — use TLS in production';
		} elseif ($pinnedPolling) {
			$message = 'transport pinned to polling; notify_push is not required';
		} elseif (!$appEnabled) {
			$message = 'notify_push app is not installed — the Connector will fall back to polling';
		} elseif (!$queueAvailable) {
			$message = 'notify_push has no usable queue (Redis missing?) — the Connector will fall back to polling';
		} else {
			$message = 'notify_push daemon unreachable — the Connector will fall back to polling';
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

	/** @return array<string, mixed> */
	private function connectorInfo(): array {
		$ackAt = $this->config->ackAt();
		$ackCursor = $this->config->ackCursor();
		$now = $this->time->getTime();
		$seenRecently = $ackAt > 0 && ($now - $ackAt) < self::SEEN_RECENTLY_SECONDS;

		if ($ackAt === 0) {
			$message = 'the Connector has not acknowledged the feed yet — it connects to Nextcloud, '
				. 'so if this persists check the Connector\'s own logs and its configured Nextcloud address';
		} elseif ($seenRecently) {
			$message = 'Connector alive: acknowledged cursor ' . $ackCursor . ', ' . ($now - $ackAt) . ' s ago';
		} else {
			$message = 'Connector silent for ' . intdiv($now - $ackAt, 3600) . ' h (last acknowledged cursor ' . $ackCursor . ')';
		}

		return [
			'url' => $this->config->connectorUrl(),
			'last_ack_cursor' => $ackCursor,
			'last_ack_at' => $ackAt,
			'seen_recently' => $seenRecently,
			'message' => $message,
		];
	}
}
