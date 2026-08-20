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
