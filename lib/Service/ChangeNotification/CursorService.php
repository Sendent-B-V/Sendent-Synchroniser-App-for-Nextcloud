<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\Db\DirtyCollectionMapper;
use OCA\SendentSynchroniser\Db\SequenceMapper;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IMemcache;

/**
 * The monotonic sequence stamped onto every ledger row.
 *
 * Preferred source is an atomic INCR on the distributed cache (~50 us).
 * Without one, the DB sequence table is used instead — realistic only on small
 * instances, which are also the instances that fall back to polling anyway.
 *
 * Sequence numbers are monotonic but NOT gapless: a DAV write that rolls back
 * still consumed one. Readers only ever compare with `>`, so gaps are free.
 *
 * The one failure that must never happen is handing out a value at or below
 * the flushed watermark: a row stamped with it sits below every reader's
 * `since` forever. next() therefore treats any counter value <= flushedSeq
 * (a restarted/evicted counter) as broken and repairs it before returning.
 */
class CursorService {

	private const KEY = 'cursor';
	private const CACHE_PREFIX = 'sndntsync_cn/';
	private const RESEED_RETRIES = 3;

	public function __construct(
		private ICacheFactory $cacheFactory,
		private IConfig $serverConfig,
		private SequenceMapper $sequence,
		private DirtyCollectionMapper $ledger,
		private ChangeNotificationConfig $config,
	) {}

	public function next(): int {
		$cache = $this->memcache();
		if ($cache === null) {
			return $this->sequence->next();
		}

		$value = $cache->inc(self::KEY);
		if (!is_int($value)) {
			return $this->sequence->next();
		}

		// Restart detection. flushedSeq() is the cheap per-event gate (config
		// read); value === 1 additionally catches polling-only instances whose
		// watermark never advances. maxSeq() (one indexed MAX) only runs on
		// the rare repair path.
		$floor = $this->config->flushedSeq();
		if ($value <= $floor || $value === 1) {
			$seed = max($this->ledger->maxSeq(), $floor);
			for ($i = 0; $i < self::RESEED_RETRIES && $value <= $seed; $i++) {
				// cas() fails when a concurrent request raced the reseed with
				// its own inc(); re-inc and re-check rather than trusting a
				// single attempt — a lost race must never return a
				// below-watermark value.
				$cache->cas(self::KEY, $value, $seed + 1);
				$value = $cache->inc(self::KEY);
				if (!is_int($value)) {
					break;
				}
			}
			if (!is_int($value) || $value <= $seed) {
				// Shared counter unrepairable right now; the DB sequence,
				// lifted above the seed, is always safe.
				$this->sequence->reseedAbove($seed);
				return $this->sequence->next();
			}
		}

		return $value;
	}

	/** The highest sequence number handed out so far; cheap, read-only. */
	public function current(): int {
		$cache = $this->memcache();
		if ($cache !== null) {
			$value = $cache->get(self::KEY);
			if (is_numeric($value)) {
				return (int)$value;
			}
		}

		// Floor at the flushed watermark for symmetry with next()'s repair
		// path: under-reporting after a cache eviction is safe (readers only
		// re-see known rows) but pointless when the watermark is known higher.
		return max($this->ledger->maxSeq(), $this->config->flushedSeq());
	}

	private function memcache(): ?IMemcache {
		// ICacheFactory::createDistributed() silently falls back to the LOCAL
		// cache class when memcache.distributed is not configured (the common
		// APCu-only install). A local counter is per-process — php-fpm workers
		// and the cron CLI would hand out duplicate sequence numbers — so an
		// explicitly configured distributed cache is required; anything else
		// uses the DB sequence.
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
