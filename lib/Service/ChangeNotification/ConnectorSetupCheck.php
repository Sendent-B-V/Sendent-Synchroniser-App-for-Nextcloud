<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\Constants;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Layered "is everything set up correctly?" diagnostic around the Exchange
 * Connector address setting.
 *
 * Layer 1 (notify_push): is transport A actually available on this server —
 * app enabled, a real queue behind it, the daemon answering, and the
 * advertised websocket URL secure (wss://). A polling-pinned instance passes
 * this layer by choice.
 *
 * Layer 2 (connector TLS): does the configured connector address use https,
 * does a TLS handshake with full certificate verification succeed, and how
 * long until the certificate expires.
 *
 * Admin-triggered only (settings button, save hook, occ cn-check) — never on
 * the DAV write path. The raw socket probes deliberately bypass IClient: we
 * need the peer certificate, and this runs at the same trust level as the
 * admin who typed the URL.
 */
class ConnectorSetupCheck {

	private const EXPIRY_WARN_DAYS = 30;

	public function __construct(
		private ChangeNotificationConfig $config,
		private NotifyPushAvailability $availability,
		private ITimeFactory $time,
	) {}

	/** @return array{ok: bool, checked_at: int, notify_push: array<string, mixed>, connector: array<string, mixed>} */
	public function run(): array {
		$notifyPush = $this->checkNotifyPush();
		$connector = $this->checkConnector($this->config->connectorUrl());

		return [
			'ok' => $notifyPush['ok'] && $connector['ok'],
			'checked_at' => $this->time->getTime(),
			'notify_push' => $notifyPush,
			'connector' => $connector,
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
			// A polling-pinned instance passes by choice; otherwise the full
			// push stack must be healthy.
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
	private function checkConnector(string $url): array {
		$result = [
			'ok' => false,
			'configured' => false,
			'url' => $url,
			'https' => false,
			'reachable' => false,
			'tls_valid' => false,
			'tls_issuer' => null,
			'tls_expires_in_days' => null,
			'tls_expiring_soon' => false,
			'message' => 'no connector address configured',
		];

		if ($url === '') {
			return $result;
		}
		$result['configured'] = true;

		$parts = parse_url($url);
		$host = is_array($parts) ? ($parts['host'] ?? '') : '';
		$scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';
		if ($host === '') {
			$result['message'] = 'connector address is not a valid URL';
			return $result;
		}

		if ($scheme !== 'https') {
			$port = is_array($parts) ? (int)($parts['port'] ?? 80) : 80;
			$result['reachable'] = $this->probeTcp($host, $port);
			$result['message'] = $result['reachable']
				? 'connector answers, but the address is not https — use TLS in production'
				: 'connector address is not https, and nothing answered at ' . $host . ':' . $port;
			return $result;
		}
		$result['https'] = true;

		$port = is_array($parts) ? (int)($parts['port'] ?? 443) : 443;
		$tls = $this->probeTls($host, $port);

		$result['reachable'] = (bool)($tls['reachable'] ?? false);
		$result['tls_valid'] = (bool)($tls['ok'] ?? false);
		$result['tls_issuer'] = $tls['issuer'] ?? null;
		$result['tls_expires_in_days'] = $tls['expires_in_days'] ?? null;
		$result['tls_expiring_soon'] = $result['tls_valid']
			&& $result['tls_expires_in_days'] !== null
			&& $result['tls_expires_in_days'] < self::EXPIRY_WARN_DAYS;

		if (!$result['tls_valid']) {
			$result['message'] = (string)($tls['message'] ?? 'TLS check failed');
		} elseif ($result['tls_expiring_soon']) {
			$result['message'] = 'TLS certificate valid, but it expires in ' . $result['tls_expires_in_days'] . ' days';
		} else {
			$result['message'] = 'connector reachable over verified TLS';
		}

		$result['ok'] = $result['tls_valid'];

		return $result;
	}

	/**
	 * TLS handshake with full peer verification, capturing the certificate.
	 * Protected so unit tests can stub the raw socket work.
	 *
	 * @return array{ok: bool, reachable: bool, message: string, issuer: ?string, expires_in_days: ?int}
	 */
	protected function probeTls(string $host, int $port): array {
		try {
			$context = stream_context_create(['ssl' => [
				'verify_peer' => true,
				'verify_peer_name' => true,
				'capture_peer_cert' => true,
				'SNI_enabled' => true,
			]]);
			$errno = 0;
			$errstr = '';
			$socket = @stream_socket_client(
				'ssl://' . $host . ':' . $port,
				$errno,
				$errstr,
				5,
				STREAM_CLIENT_CONNECT,
				$context
			);

			if ($socket === false) {
				// A refused/timed-out TCP connect and a failed certificate
				// verification both land here; the OpenSSL error text tells
				// the admin which ("certificate verify failed", "self-signed"…).
				$reachable = $this->probeTcp($host, $port);
				return [
					'ok' => false,
					'reachable' => $reachable,
					'message' => $reachable
						? ('TLS handshake failed: ' . ($errstr !== '' ? $errstr : 'certificate verification failed'))
						: ('connector unreachable at ' . $host . ':' . $port . ($errstr !== '' ? ' (' . $errstr . ')' : '')),
					'issuer' => null,
					'expires_in_days' => null,
				];
			}

			$params = stream_context_get_params($socket);
			fclose($socket);

			$issuer = null;
			$expiresInDays = null;
			$cert = $params['options']['ssl']['peer_certificate'] ?? null;
			if ($cert !== null) {
				$parsed = openssl_x509_parse($cert);
				if (is_array($parsed)) {
					$issuer = $parsed['issuer']['O'] ?? $parsed['issuer']['CN'] ?? null;
					$validTo = $parsed['validTo_time_t'] ?? null;
					if (is_int($validTo)) {
						$expiresInDays = intdiv($validTo - $this->time->getTime(), 86400);
					}
				}
			}

			return [
				'ok' => true,
				'reachable' => true,
				'message' => 'verified TLS',
				'issuer' => is_string($issuer) ? $issuer : null,
				'expires_in_days' => $expiresInDays,
			];
		} catch (\Throwable $e) {
			return ['ok' => false, 'reachable' => false, 'message' => 'TLS probe failed: ' . $e->getMessage(), 'issuer' => null, 'expires_in_days' => null];
		}
	}

	/** Plain TCP reachability. Protected for the same test seam. */
	protected function probeTcp(string $host, int $port): bool {
		try {
			$errno = 0;
			$errstr = '';
			$socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 5);
			if ($socket === false) {
				return false;
			}
			fclose($socket);
			return true;
		} catch (\Throwable $e) {
			return false;
		}
	}
}
