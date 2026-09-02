<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IMemcache;

/**
 * Leading-edge batch window: IMemcache::add() is set-if-absent with a TTL, so
 * the first event after a window boundary wins the key and flushes
 * immediately; every other event fails the add and rides the next flush or
 * the sweeper. Winning the add IS the lock for this window — no separate
 * flush lock is needed.
 *
 * The trailing edge is loose: a burst followed by silence waits up to one
 * cron interval, a bound also covered by the Connector's overlap reads and reconcile.
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
			// No distributed cache means notify_push is unavailable too (its
			// IQueue would be a NullQueue), so flushing here would do nothing.
			return false;
		}

		if ($window === 0) {
			return true;
		}

		return $cache->add(self::KEY, 1, $window);
	}

	private function memcache(): ?IMemcache {
		// Same guard as CursorService: a local-cache fallback would elect one flusher per php-fpm worker.
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
