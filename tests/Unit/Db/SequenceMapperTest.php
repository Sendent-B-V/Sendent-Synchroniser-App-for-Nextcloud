<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Db;

use OCA\SendentSynchroniser\Db\SequenceMapper;
use OCA\SendentSynchroniser\Service\ChangeNotification\AfterCommit;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SequenceMapperTest extends TestCase {

	/** @var IDBConnection&MockObject */
	private $db;

	/** @var ChangeNotificationConfig&MockObject */
	private $config;

	/** @var list<callable> work AfterCommit queued for the end of the request */
	private array $endOfRequest = [];

	private int $lastId = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->config = $this->createMock(ChangeNotificationConfig::class);

		// Every next() inserts a row; the table hands out ids 1, 2, 3, ...
		$this->db->method('getQueryBuilder')->willReturnCallback(function (): IQueryBuilder {
			$qb = $this->createMock(IQueryBuilder::class);
			$qb->method('insert')->willReturnSelf();
			$qb->method('values')->willReturnSelf();
			$qb->method('executeStatement')->willReturnCallback(function (): int {
				$this->lastId++;
				return 1;
			});
			$qb->method('getLastInsertId')->willReturnCallback(fn (): int => $this->lastId);
			return $qb;
		});
	}

	private function mapper(bool $inTransaction): SequenceMapper {
		$this->db->method('inTransaction')->willReturnCallback(fn (): bool => $inTransaction);
		$afterCommit = new AfterCommit($this->db, new NullLogger(), function (callable $work): void {
			$this->endOfRequest[] = $work;
		});

		return new SequenceMapper($this->db, $this->config, $this->createMock(ITimeFactory::class), $afterCommit);
	}

	public function testAReseedInsideTheDavTransactionTakesEffectAtOnceButIsStoredAfterCommit(): void {
		// Writing app config inside the DAV transaction holds its row lock
		// until the user's write commits, and on PostgreSQL a concurrent first
		// insert of the key fails and the change is dropped.
		$this->config->method('seqOffset')->willReturn(0);
		$this->config->expects($this->never())->method('raiseSeqOffset');
		$mapper = $this->mapper(true);

		$mapper->reseedAbove(5000);

		$this->assertGreaterThan(5000, $mapper->next());
		$this->assertCount(1, $this->endOfRequest);
	}

	public function testTheStoredOffsetCoversEveryNumberHandedOutBeforeIt(): void {
		$this->config->method('seqOffset')->willReturn(0);
		$stored = null;
		$this->config->method('raiseSeqOffset')->willReturnCallback(function (int $offset) use (&$stored): void {
			$stored = $offset;
		});
		$mapper = $this->mapper(false);

		$mapper->reseedAbove(5000);

		$this->assertNotNull($stored);
		// The id the reseed consumed, lifted by the stored offset, is above the target.
		$this->assertGreaterThan(5000, $stored + $this->lastId);
		$this->assertGreaterThan(5000, $mapper->next());
	}

	public function testNoReseedIsNeededAboveTheTarget(): void {
		$this->config->method('seqOffset')->willReturn(9000);
		$this->config->expects($this->never())->method('raiseSeqOffset');

		$this->mapper(false)->reseedAbove(5000);

		$this->assertSame([], $this->endOfRequest);
	}
}
