<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\Constants;
use OCP\AppFramework\Services\IAppConfig;

/**
 * Typed, clamped read/write access to every change-notification app-config key.
 * Setters clamp too, so stored and effective values always agree.
 *
 * Nothing here is written per DAV event: every app-config write clears
 * Nextcloud's shared app-config cache.
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

	public function pollInterval(): int {
		$raw = $this->appConfig->getAppValue(Constants::CN_POLL_INTERVAL_KEY, (string)Constants::CN_POLL_INTERVAL_DEFAULT);
		$value = is_numeric($raw) ? (int)$raw : Constants::CN_POLL_INTERVAL_DEFAULT;
		return $this->clampPollInterval($value);
	}

	public function setPollInterval(int $seconds): void {
		$this->appConfig->setAppValue(Constants::CN_POLL_INTERVAL_KEY, (string)$this->clampPollInterval($seconds));
	}

	/** 0 means no floor was ever established on this instance. */
	public function seqFloor(): int {
		return max(0, (int)$this->appConfig->getAppValue(Constants::CN_SEQ_FLOOR_KEY, '0'));
	}

	/** Monotonic: the floor only ever rises. */
	public function raiseSeqFloor(int $floor): void {
		if ($floor <= $this->seqFloor()) {
			return;
		}
		$this->appConfig->setAppValue(Constants::CN_SEQ_FLOOR_KEY, (string)$floor);
	}

	public function seqOffset(): int {
		return max(0, (int)$this->appConfig->getAppValue(Constants::CN_SEQ_OFFSET_KEY, '0'));
	}

	/** Monotonic: the offset only ever rises. */
	public function raiseSeqOffset(int $offset): void {
		if ($offset <= $this->seqOffset()) {
			return;
		}
		$this->appConfig->setAppValue(Constants::CN_SEQ_OFFSET_KEY, (string)$offset);
	}

	private function clampPollInterval(int $seconds): int {
		return max(Constants::CN_POLL_INTERVAL_MIN, min(Constants::CN_POLL_INTERVAL_MAX, $seconds));
	}
}
