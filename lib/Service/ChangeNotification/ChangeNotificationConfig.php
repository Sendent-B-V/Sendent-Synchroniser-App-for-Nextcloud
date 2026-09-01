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
 * tolerates. Setters clamp on write too, so a stored value never disagrees
 * with what the getters report.
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

	public function connectorUrl(): string {
		return (string)$this->appConfig->getAppValue(Constants::CN_CONNECTOR_URL_KEY, '');
	}

	public function setConnectorUrl(string $url): void {
		$this->appConfig->setAppValue(Constants::CN_CONNECTOR_URL_KEY, $url);
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
		$this->appConfig->setAppValue(Constants::CN_BATCH_WINDOW_KEY, (string)max(Constants::CN_BATCH_WINDOW_MIN, min(Constants::CN_BATCH_WINDOW_MAX, $seconds)));
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
		$this->appConfig->setAppValue(Constants::CN_MAX_REFS_KEY, (string)max(Constants::CN_MAX_REFS_MIN, min(Constants::CN_MAX_REFS_MAX, $max)));
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
		$this->appConfig->setAppValue(Constants::CN_POLL_INTERVAL_KEY, (string)max(Constants::CN_POLL_INTERVAL_MIN, min(Constants::CN_POLL_INTERVAL_MAX, $seconds)));
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
