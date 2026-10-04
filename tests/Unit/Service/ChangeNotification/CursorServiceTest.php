<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\Db\DirtyCollectionMapper;
use OCA\SendentSynchroniser\Db\SequenceMapper;
use OCA\SendentSynchroniser\Service\ChangeNotification\AfterCommit;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\CursorService;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IMemcache;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CursorServiceTest extends TestCase {

	private const GAP = CursorService::RESEED_GAP;

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
			// No open transaction: deferred floor writes run immediately.
			new AfterCommit($this->createMock(IDBConnection::class), new NullLogger()),
		);
	}

	private function givenFloor(int $seqFloor): void {
		$this->config->method('seqFloor')->willReturn($seqFloor);
	}

	public function testNextUsesTheDistributedCacheCounter(): void {
		$this->givenFloor(1000);
		$this->ledger->method('maxSeq')->willReturn(1041);
		$this->memcache->method('inc')->willReturn(1042);
		$this->sequence->expects($this->never())->method('next');
		$this->memcache->expects($this->never())->method('cas');

		$this->assertSame(1042, $this->service()->next());
	}

	public function testAFasterConcurrentWriterIsNotMistakenForARolledBackCounter(): void {
		// We get 101; before we look at the ledger, another request gets 102
		// and commits. Checking the ledger after the increment would see 102
		// and reseed for nothing. Every row visible before the increment was
		// stamped by an earlier increment, so that is when to look.
		$this->givenFloor(1000);
		$incremented = false;
		$this->memcache->method('inc')->willReturnCallback(function () use (&$incremented): int {
			$incremented = true;
			return 101 + 1000;
		});
		$this->ledger->method('maxSeq')->willReturnCallback(
			function () use (&$incremented): int {
				return $incremented ? 102 + 1000 : 100 + 1000;
			}
		);
		$this->memcache->expects($this->never())->method('cas');

		$this->assertSame(101 + 1000, $this->service()->next());
	}

	public function testACounterRolledBackBelowTheLedgerIsReseeded(): void {
		// Redis restarted from an hour-old RDB snapshot: the counter is back
		// at 40,000, above the floor set when it was first seeded, while the
		// ledger — and therefore every cursor the Connector was ever given —
		// is already at 50,000. Handing out 40,001 would stamp a row below
		// the Connector's cursor for good.
		$this->givenFloor(1000);
		$this->ledger->method('maxSeq')->willReturn(50000);
		$seed = 50000 + self::GAP;
		$this->memcache->method('inc')->willReturnOnConsecutiveCalls(40001, $seed + 2);
		$this->memcache->expects($this->once())->method('cas')->with($this->anything(), 40001, $seed + 1)->willReturn(true);

		$this->assertSame($seed + 2, $this->service()->next());
	}

	public function testNextFallsBackToTheDatabaseWithoutAConfiguredDistributedCache(): void {
		// APCu-only installs: createDistributed() silently falls back to the
		// LOCAL cache, whose counter is per-process (php-fpm workers and the
		// cron CLI each see their own). Explicit memcache.distributed config
		// is the only reliable signal the counter is actually shared.
		$this->givenFloor(0);
		$this->sequence->method('next')->willReturn(7);

		$this->assertSame(7, $this->service(false)->next());
	}

	public function testACacheLessInstanceChecksTheLedgerOncePerProcess(): void {
		$this->givenFloor(0);
		$this->ledger->expects($this->once())->method('maxSeq')->willReturn(0);
		$this->sequence->method('next')->willReturnOnConsecutiveCalls(7, 8, 9);
		$service = $this->service(false);

		$this->assertSame(7, $service->next());
		$this->assertSame(8, $service->next());
		$this->assertSame(9, $service->next());
	}

	public function testACacheLessInstanceThatOnceUsedACacheIsLiftedAboveTheLedger(): void {
		// Redis was removed from config.php. The DB sequence never saw the
		// cache's numbers, so its next id (7) is far below the ledger.
		$this->givenFloor(0);
		$this->ledger->method('maxSeq')->willReturn(50000);
		$this->sequence->method('next')->willReturnOnConsecutiveCalls(7, 50001);
		$this->sequence->expects($this->once())->method('reseedAbove')->with(50000);

		$this->assertSame(50001, $this->service(false)->next());
	}

	public function testAFailedIncFallsBackToTheDatabaseAboveTheLedger(): void {
		// Review scenario: increment() returns false during a brief Memcached
		// outage. The DB sequence would answer 3 while the ledger is in the
		// millions; that row would sit below every Connector's cursor.
		$this->givenFloor(1000);
		$this->memcache->method('inc')->willReturn(false);
		$this->ledger->method('maxSeq')->willReturn(1849233);
		$this->sequence->method('next')->willReturnOnConsecutiveCalls(3, 1849234);
		$this->sequence->expects($this->once())->method('reseedAbove')->with(1849233);
		// Once the cache is back, a counter still behind this value must be caught.
		$this->config->expects($this->once())->method('raiseSeqFloor')->with(1849234);

		$this->assertSame(1849234, $this->service()->next());
	}

	public function testARedisConnectionErrorFallsBackToTheDatabaseInsteadOfThrowing(): void {
		// Redis signals a dropped connection by throwing, not by returning
		// false. Letting it escape would lose the ledger row for this change.
		$this->givenFloor(1000);
		$this->memcache->method('inc')->willThrowException(new \RuntimeException('read error on connection to redis:6379'));
		$this->ledger->method('maxSeq')->willReturn(70000);
		$this->sequence->method('next')->willReturnOnConsecutiveCalls(12, 70001);

		$this->assertSame(70001, $this->service()->next());
	}

	public function testARestartedCounterIsReseededAboveTheLedger(): void {
		// The cache was evicted: inc() recreates the key at 1. Handing out 1
		// would stamp a row below every reader's `since`, so the counter is
		// lifted a full gap above the ledger.
		$this->givenFloor(1000);
		$this->ledger->method('maxSeq')->willReturn(5000);
		$seed = 5000 + self::GAP;
		$this->memcache->method('inc')->willReturnOnConsecutiveCalls(1, $seed + 2);
		$this->memcache->expects($this->once())
			->method('cas')
			->with($this->anything(), 1, $seed + 1)
			->willReturn(true);
		$this->config->expects($this->once())->method('raiseSeqFloor')->with($seed);

		$this->assertSame($seed + 2, $this->service()->next());
	}

	public function testEveryValueFromARestartedCounterIsCaughtNotOnlyTheFirst(): void {
		// 50,000 ledger rows. After an eviction request A gets 1 and starts
		// reseeding; request B gets 2 meanwhile. A check that only caught the
		// first value would have returned 2.
		$this->givenFloor(48000);
		$this->ledger->method('maxSeq')->willReturn(50000);
		$seed = 50000 + self::GAP;
		$this->memcache->method('inc')->willReturnOnConsecutiveCalls(2, $seed + 5);
		$this->memcache->method('cas')->willReturn(false); // A won the reseed

		$this->assertSame($seed + 5, $this->service()->next());
	}

	public function testALostReseedRaceRetriesUntilAboveTheSeed(): void {
		// Our first cas loses to a concurrent writer, the re-inc lands at 3
		// (still below the seed), the second cas wins. A single-attempt reseed
		// would have returned 3.
		$this->givenFloor(1000);
		$this->ledger->method('maxSeq')->willReturn(5000);
		$seed = 5000 + self::GAP;
		$this->memcache->method('inc')->willReturnOnConsecutiveCalls(2, 3, $seed + 2);
		$this->memcache->method('cas')->willReturnOnConsecutiveCalls(false, true);

		$this->assertSame($seed + 2, $this->service()->next());
	}

	public function testAnExhaustedReseedFallsBackToAReseededDbSequence(): void {
		$this->givenFloor(1000);
		$this->ledger->method('maxSeq')->willReturn(5000);
		$seed = 5000 + self::GAP;
		$this->memcache->method('inc')->willReturn(1);
		$this->memcache->method('cas')->willReturn(false);
		$this->sequence->expects($this->once())->method('reseedAbove')->with($seed);
		$this->sequence->method('next')->willReturn($seed + 1);

		$this->assertSame($seed + 1, $this->service()->next());
	}

	public function testAnInstanceWithoutAFloorEstablishesOneByJumpingForward(): void {
		// Fresh install, or an upgrade from a version without the floor. The
		// counter's 1 cannot be told apart from a lost counter, so the
		// sequence jumps a full gap ahead. Forward jumps are always harmless.
		$this->givenFloor(0);
		$this->ledger->method('maxSeq')->willReturn(0);
		$this->memcache->method('inc')->willReturnOnConsecutiveCalls(1, self::GAP + 2);
		$this->memcache->method('cas')->with($this->anything(), 1, self::GAP + 2)->willReturn(true);
		$this->config->expects($this->once())->method('raiseSeqFloor')->with(1 + self::GAP);

		$this->assertSame(self::GAP + 2, $this->service()->next());
	}

	public function testEstablishingAFloorNeverMovesAHealthyCounterBackwards(): void {
		// Upgrade with a live counter at 90,000 while the ledger is at 89,990.
		$this->givenFloor(0);
		$this->ledger->method('maxSeq')->willReturn(89990);
		$seed = 90000 + self::GAP;
		$this->memcache->method('inc')->willReturnOnConsecutiveCalls(90000, $seed + 2);
		$this->memcache->method('cas')->with($this->anything(), 90000, $seed + 1)->willReturn(true);

		$this->assertGreaterThan(90000, $this->service()->next());
	}

	public function testTheFloorIsNotWrittenInsideTheDavTransaction(): void {
		// Writing app config inside the user's transaction would hold its row
		// lock until that write commits and stall other writers.
		$this->givenFloor(1000);
		$this->ledger->method('maxSeq')->willReturn(5000);
		$this->memcache->method('inc')->willReturnOnConsecutiveCalls(1, 6002);
		$this->memcache->method('cas')->willReturn(true);
		$this->serverConfig->method('getSystemValue')->willReturn('\\OC\\Memcache\\Redis');
		$this->cacheFactory->method('isAvailable')->willReturn(true);
		$this->cacheFactory->method('createDistributed')->willReturn($this->memcache);

		$inTransaction = true;
		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturnCallback(function () use (&$inTransaction): bool {
			return $inTransaction;
		});
		$endOfRequest = [];
		$service = new CursorService(
			$this->cacheFactory,
			$this->serverConfig,
			$this->sequence,
			$this->ledger,
			$this->config,
			new AfterCommit($db, new NullLogger(), function (callable $work) use (&$endOfRequest): void {
				$endOfRequest[] = $work;
			}),
		);

		$written = [];
		$this->config->method('raiseSeqFloor')->willReturnCallback(function (int $floor) use (&$written): void {
			$written[] = $floor;
		});

		$this->assertSame(6002, $service->next());
		$this->assertSame([], $written, 'nothing written while the transaction is open');

		$inTransaction = false;
		foreach ($endOfRequest as $work) {
			$work();
		}
		$this->assertSame([6000], $written);
	}

	public function testALongTransactionSeedsOnceNotOnEveryEvent(): void {
		// A cron job writes many events inside one transaction. The durable
		// floor is only written after commit and config is cached for the
		// process, so the service must remember the floor it just raised.
		$this->givenFloor(0);
		$this->ledger->method('maxSeq')->willReturn(0);
		$this->memcache->method('inc')->willReturnOnConsecutiveCalls(1, self::GAP + 2, self::GAP + 3, self::GAP + 4);
		$this->memcache->expects($this->once())->method('cas')->willReturn(true);
		$this->serverConfig->method('getSystemValue')->willReturn('\\OC\\Memcache\\Redis');
		$this->cacheFactory->method('isAvailable')->willReturn(true);
		$this->cacheFactory->method('createDistributed')->willReturn($this->memcache);
		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturn(true);
		$service = new CursorService(
			$this->cacheFactory,
			$this->serverConfig,
			$this->sequence,
			$this->ledger,
			$this->config,
			new AfterCommit($db, new NullLogger(), static function (callable $work): void {
			}),
		);

		$this->assertSame(self::GAP + 2, $service->next());
		$this->assertSame(self::GAP + 3, $service->next());
		$this->assertSame(self::GAP + 4, $service->next());
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

	public function testTheFenceIsZeroWithoutACache(): void {
		$this->assertSame(0, $this->service(false)->fence());
	}

	public function testRaiseFenceCreatesAMissingFence(): void {
		$this->memcache->method('get')->willReturn(null);
		$this->memcache->expects($this->once())->method('add')->with('fence', 500)->willReturn(true);

		$this->service()->raiseFence(500);
	}

	public function testRaiseFenceNeverLowersIt(): void {
		$this->memcache->method('get')->willReturn(900);
		$this->memcache->expects($this->never())->method('cas');
		$this->memcache->expects($this->never())->method('add');

		$this->service()->raiseFence(500);
	}

	public function testRaiseFenceRetriesWhenAConcurrentFlushMovedIt(): void {
		$this->memcache->method('get')->willReturnOnConsecutiveCalls(400, 450);
		$this->memcache->expects($this->exactly(2))->method('cas')->willReturnOnConsecutiveCalls(false, true);

		$this->service()->raiseFence(500);
	}
}
