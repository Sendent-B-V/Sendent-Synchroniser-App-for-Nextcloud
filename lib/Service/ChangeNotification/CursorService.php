<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\Db\DirtyCollectionMapper;
use OCA\SendentSynchroniser\Db\SequenceMapper;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IMemcache;

/**
 * The monotonic sequence stamped onto every ledger row, plus the reader fence.
 *
 * Preferred source: atomic INCR on the distributed cache (~50 us); falls back
 * to the DB sequence table on small, polling-only instances.
 *
 * Sequence numbers are monotonic but NOT gapless: a DAV write that rolls back
 * still consumed one. Readers only ever compare with `>`, so gaps are free.
 *
 * The one failure that must never happen is handing out a value that readers
 * have already passed: a row stamped with it sits below every reader's `since`
 * forever. Three things can make the counter go backwards:
 *
 *  - The cache loses the counter (restart, eviction). inc() then recreates it
 *    at 1, and concurrent requests receive 1, 2, 3, ... before anyone repairs
 *    it. Every one of those values must be rejected, not only the first.
 *  - The cache comes back from an older snapshot (a Redis restart with RDB
 *    persistence, a failover to a lagging replica): the counter is behind,
 *    but far above 1.
 *  - inc() fails for a moment and the DB sequence answers instead. That
 *    sequence lives in its own table and can be far below the counter.
 *
 * Every value is checked against the ledger's highest sequence: the cursor
 * /changes hands out never exceeds a row's sequence, so no reader is beyond
 * it. That costs one indexed MAX() per event. A durable floor (cnSeqFloor)
 * additionally covers values stamped by rows that are not committed yet: the
 * floor is set to the seed whenever the counter is (re)seeded, and seeds land
 * at least RESEED_GAP above anything in the ledger.
 */
class CursorService {

	private const KEY = 'cursor';
	private const FENCE_KEY = 'fence';
	private const CACHE_PREFIX = 'sndntsync_cn/';
	private const RESEED_RETRIES = 3;
	private const FENCE_RETRIES = 3;

	/**
	 * Distance between the ledger's highest sequence and a fresh seed. It is
	 * also the minimum floor, so it bounds how many values a restarted counter
	 * can hand out before it is noticed. A jump forward is always harmless.
	 */
	public const RESEED_GAP = 1000;

	/** Whether the DB sequence was already checked against the ledger in this process. */
	private bool $databaseSequenceChecked = false;

	/**
	 * The floor this process raised itself. Its durable copy is written only
	 * after commit, and app config is cached for the process, so without this
	 * a long transaction (a cron job writing many events) would see no floor
	 * on every call and re-seed every time.
	 */
	private int $knownFloor = 0;

	public function __construct(
		private ICacheFactory $cacheFactory,
		private IConfig $serverConfig,
		private SequenceMapper $sequence,
		private DirtyCollectionMapper $ledger,
		private ChangeNotificationConfig $config,
		private AfterCommit $afterCommit,
	) {}

	public function next(): int {
		$cache = $this->memcache();
		if ($cache === null) {
			return $this->databaseNext(false);
		}

		// Read before the increment: every row visible now was stamped by an
		// earlier increment, so a healthy counter always lands above it. Read
		// after, a faster writer that took the next number and committed in
		// between would look like a counter that went backwards.
		$ledgerMax = $this->ledger->maxSeq();

		// Memcached reports a failed increment as false; Redis throws
		// (RedisException on a dropped connection). Both mean the same thing.
		$value = $this->tryInc($cache);
		if (!is_int($value)) {
			return $this->databaseNext(true);
		}

		$established = max($this->config->seqFloor(), $this->knownFloor);
		$floor = max($established, $ledgerMax);
		if ($established > 0 && $value > $floor) {
			return $value;
		}

		// Either the counter fell to or below the floor (lost or behind), or
		// this instance never established a floor (fresh install, or an
		// upgrade from a version without one). In the second case the value
		// may be fine, but there is nothing to prove it, so jump forward.
		$seed = max($ledgerMax, $floor, $value) + self::RESEED_GAP;
		for ($i = 0; $i < self::RESEED_RETRIES && $value <= $seed; $i++) {
			// cas() fails when a concurrent request raced the reseed with its
			// own inc(); re-inc and re-check rather than trusting a single
			// attempt. A lost race must never return a value below the seed.
			try {
				$cache->cas(self::KEY, $value, $seed + 1);
			} catch (\Throwable $e) {
				$value = false;
				break;
			}
			$value = $this->tryInc($cache);
			if (!is_int($value)) {
				break;
			}
		}

		$this->raiseFloor($seed);

		if (!is_int($value) || $value <= $seed) {
			// Shared counter unrepairable right now; the DB sequence, lifted
			// above the seed, is always safe.
			$this->sequence->reseedAbove($seed);
			return $this->sequence->next();
		}

		return $value;
	}

	/** @return int|false false when the cache failed, whichever way it signals that */
	private function tryInc(IMemcache $cache): int|false {
		try {
			$value = $cache->inc(self::KEY);
		} catch (\Throwable $e) {
			return false;
		}
		return is_int($value) ? $value : false;
	}

	/**
	 * The highest sequence number handed out so far; read-only. /changes
	 * raises the fence to it and caps its cursor at it.
	 *
	 * The counter alone is not enough: while inc() failed, the DB sequence
	 * stamped rows above it, and a cursor capped at the counter could never
	 * pass them. The ledger's highest committed number covers those. Raising
	 * the fence above the counter costs nothing: next() never hands out a
	 * number at or below the ledger's highest, so no writer is re-stamped for it.
	 */
	public function current(): int {
		$counter = 0;
		$cache = $this->memcache();
		if ($cache !== null) {
			$value = $cache->get(self::KEY);
			if (is_numeric($value)) {
				$counter = (int)$value;
			}
		}

		return max($counter, $this->ledger->maxSeq());
	}

	/**
	 * The highest sequence a reader has fenced off: /changes raises the fence
	 * to the counter's value before it reads the ledger. A row whose number is
	 * at or below the fence, and that committed after that point, may have been
	 * read past, so its writer must re-stamp it. 0 when unknown (no cache).
	 */
	public function fence(): int {
		$cache = $this->memcache();
		if ($cache === null) {
			return 0;
		}
		$value = $cache->get(self::FENCE_KEY);
		return is_numeric($value) ? (int)$value : 0;
	}

	/** Raises the fence to $seq; never lowers it. A no-op without a distributed cache. */
	public function raiseFence(int $seq): void {
		$cache = $this->memcache();
		if ($cache === null) {
			return;
		}

		for ($i = 0; $i < self::FENCE_RETRIES; $i++) {
			$current = $cache->get(self::FENCE_KEY);
			if ($current === null) {
				if ($cache->add(self::FENCE_KEY, $seq)) {
					return;
				}
				continue;
			}
			if ((int)$current >= $seq) {
				return;
			}
			if ($cache->cas(self::FENCE_KEY, $current, $seq)) {
				return;
			}
		}
	}

	/**
	 * The DB sequence, never at or below a value readers may already have passed.
	 *
	 * @param bool $cacheFailed true when a configured cache counter failed just
	 *                          now: the counter may be far ahead of this table,
	 *                          so the ledger is checked on every call, and the
	 *                          floor is raised so that the counter, once back,
	 *                          is rejected if it is still behind this value.
	 */
	private function databaseNext(bool $cacheFailed): int {
		$floor = max($this->config->seqFloor(), $this->knownFloor);
		if ($cacheFailed || !$this->databaseSequenceChecked) {
			// A cache-less instance checks once per process: its only source is
			// this table, which cannot fall behind itself. It can only lag the
			// ledger when the instance used a cache before.
			$floor = max($floor, $this->ledger->maxSeq());
			$this->databaseSequenceChecked = true;
		}

		$value = $this->sequence->next();
		if ($value <= $floor) {
			$this->sequence->reseedAbove($floor);
			$value = $this->sequence->next();
		}

		if ($cacheFailed) {
			$this->raiseFloor($value);
		}

		return $value;
	}

	/**
	 * next() usually runs inside the DAV backend's transaction. Writing app
	 * config there would hold its row lock until the user's write commits and
	 * stall every other writer raising the same floor, so the write waits for
	 * the commit. Until then, other requests check against the previous floor
	 * and may reseed too; that only jumps the counter further forward.
	 */
	private function raiseFloor(int $floor): void {
		$this->knownFloor = max($this->knownFloor, $floor);
		$this->afterCommit->run(fn () => $this->config->raiseSeqFloor($floor));
	}

	private function memcache(): ?IMemcache {
		// createDistributed() silently falls back to a per-process LOCAL cache
		// when memcache.distributed isn't configured, which would let php-fpm
		// workers hand out duplicate sequence numbers; require it explicitly.
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
