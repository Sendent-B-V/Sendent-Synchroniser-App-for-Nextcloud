<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\Db\DirtyCollectionMapper;
use OCA\SendentSynchroniser\Db\SequenceMapper;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\CursorService;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IMemcache;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CursorServiceTest extends TestCase {

	/** @var ICacheFactory&MockObject */
	private $cacheFactory;

	/** @var IMemcache&MockObject */
	private $memcache;

	/** @var SequenceMapper&MockObject */
	private $sequence;

	/** @var DirtyCollectionMapper&MockObject */
	private $ledger;

	/** @var ChangeNotificationConfig&MockObject */
	private $config;

	/** @var IConfig&MockObject */
	private $serverConfig;

	protected function setUp(): void {
		parent::setUp();
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->memcache = $this->createMock(IMemcache::class);
		$this->sequence = $this->createMock(SequenceMapper::class);
		$this->ledger = $this->createMock(DirtyCollectionMapper::class);
		$this->config = $this->createMock(ChangeNotificationConfig::class);
		$this->serverConfig = $this->createMock(IConfig::class);
	}

	private function service(bool $distributedConfigured = true, bool $cacheAvailable = true): CursorService {
		$this->serverConfig->method('getSystemValue')->with('memcache.distributed', null)
			->willReturn($distributedConfigured ? '\\OC\\Memcache\\Redis' : null);
		$this->cacheFactory->method('isAvailable')->willReturn($cacheAvailable);
		$this->cacheFactory->method('createDistributed')->willReturn($this->memcache);

		return new CursorService(
			$this->cacheFactory,
			$this->serverConfig,
			$this->sequence,
			$this->ledger,
			$this->config,
		);
	}

	public function testNextUsesTheDistributedCacheCounter(): void {
		$this->config->method('flushedSeq')->willReturn(0);
		$this->memcache->method('inc')->willReturn(42);
		$this->sequence->expects($this->never())->method('next');

		$this->assertSame(42, $this->service()->next());
	}

	public function testNextFallsBackToTheDatabaseWithoutAConfiguredDistributedCache(): void {
		// APCu-only installs: createDistributed() silently falls back to the
		// LOCAL cache, whose counter is per-process (php-fpm workers and the
		// cron CLI each see their own). Explicit memcache.distributed config
		// is the only reliable signal the counter is actually shared.
		$this->sequence->method('next')->willReturn(7);

		$this->assertSame(7, $this->service(false)->next());
	}

	public function testNextFallsBackToTheDatabaseWhenIncFails(): void {
		$this->memcache->method('inc')->willReturn(false);
		$this->sequence->method('next')->willReturn(8);

		$this->assertSame(8, $this->service()->next());
	}

	public function testARestartedCounterIsReseededAboveTheWatermark(): void {
		// The cache was evicted: inc() recreates the key at 1 while the feed's
		// watermark is 5000. Handing out 1 would stamp rows below every
		// reader's `since` — permanently invisible — so the counter is lifted.
		$this->config->method('flushedSeq')->willReturn(5000);
		$this->ledger->method('maxSeq')->willReturn(5000);
		$this->memcache->method('inc')->willReturnOnConsecutiveCalls(1, 5002);
		$this->memcache->expects($this->once())
			->method('cas')
			->with($this->anything(), 1, 5001)
			->willReturn(true);

		$this->assertSame(5002, $this->service()->next());
	}

	public function testALostReseedRaceRetriesUntilAboveTheWatermark(): void {
		// Two requests race after an eviction: ours incs to 2, our first cas
		// loses to a concurrent writer, the re-inc lands at 3 (still below the
		// mark), the second cas wins. A single-attempt reseed would have
		// returned 3 — a below-watermark value no reader would ever see.
		$this->config->method('flushedSeq')->willReturn(5000);
		$this->ledger->method('maxSeq')->willReturn(5000);
		$this->memcache->method('inc')->willReturnOnConsecutiveCalls(2, 3, 5002);
		$this->memcache->method('cas')->willReturnOnConsecutiveCalls(false, true);

		$this->assertSame(5002, $this->service()->next());
	}

	public function testAnExhaustedReseedFallsBackToAReseededDbSequence(): void {
		// If the shared counter cannot be repaired within the retry budget,
		// never return a below-watermark value — lift the DB sequence above
		// the mark and use that instead.
		$this->config->method('flushedSeq')->willReturn(5000);
		$this->ledger->method('maxSeq')->willReturn(5000);
		$this->memcache->method('inc')->willReturn(1);
		$this->memcache->method('cas')->willReturn(false);
		$this->sequence->expects($this->once())->method('reseedAbove')->with(5000);
		$this->sequence->method('next')->willReturn(5001);

		$this->assertSame(5001, $this->service()->next());
	}

	public function testAFreshInstanceIsNotReseeded(): void {
		$this->config->method('flushedSeq')->willReturn(0);
		$this->ledger->method('maxSeq')->willReturn(0);
		$this->memcache->method('inc')->willReturn(1);
		$this->memcache->expects($this->never())->method('cas');

		$this->assertSame(1, $this->service()->next());
	}

	public function testCurrentReadsTheCounter(): void {
		$this->memcache->method('get')->willReturn('1849233');

		$this->assertSame(1849233, $this->service()->current());
	}

	public function testCurrentFallsBackToTheLedgerHighWaterMark(): void {
		$this->memcache->method('get')->willReturn(null);
		$this->ledger->method('maxSeq')->willReturn(4711);

		$this->assertSame(4711, $this->service()->current());
	}
}
