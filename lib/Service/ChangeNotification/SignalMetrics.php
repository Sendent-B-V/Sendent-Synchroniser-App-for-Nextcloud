<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Lightweight flush counters for the diagnostics block, bucketed per clock
 * hour in one app-config JSON blob. Deliberately approximate — concurrent
 * flushers may lose an increment, fine for an admin-facing gauge.
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
