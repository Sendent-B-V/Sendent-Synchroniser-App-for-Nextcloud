<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Integration\ChangeNotification;

use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Db\DirtyCollectionMapper;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The change feed against a real database, a real distributed cache and real
 * concurrent writers. All Nextcloud work happens in worker processes
 * (bin/worker.php): under PHPUnit, Nextcloud swaps every cache for a
 * per-process ArrayCache, so this process only orchestrates, asserts and
 * cleans up.
 *
 * Run with phpunit.integration.xml on MariaDB/MySQL or PostgreSQL with Redis
 * as memcache.distributed. Tests skip themselves where the setup cannot show
 * the behaviour (SQLite locks the whole database; no cache means no fence).
 */
class ChangeFeedConcurrencyTest extends TestCase {

	private const PRINCIPAL = 'principals/users/cn-integration';
	private const ANSWER_TIMEOUT_SECONDS = 60;

	private IDBConnection $db;
	private DirtyCollectionMapper $ledger;
	private ChangeNotificationConfig $config;
	private string $run;
	private string $previousTransport;

	/** @var list<array{process: resource, stdin: resource, stdout: resource, stderr: resource}> */
	private array $workers = [];

	protected function setUp(): void {
		parent::setUp();
		$this->db = \OCP\Server::get(IDBConnection::class);
		$this->ledger = \OCP\Server::get(DirtyCollectionMapper::class);
		$this->config = \OCP\Server::get(ChangeNotificationConfig::class);
		$this->run = bin2hex(random_bytes(4));
		$this->previousTransport = $this->config->transportMode();
	}

	protected function tearDown(): void {
		foreach ($this->workers as $worker) {
			fclose($worker['stdin']);
			fclose($worker['stdout']);
			fclose($worker['stderr']);
			proc_terminate($worker['process']);
			proc_close($worker['process']);
		}
		$this->config->setTransportMode($this->previousTransport);

		$qb = $this->db->getQueryBuilder();
		$qb->delete('sndntsync_dirty')
			->where($qb->expr()->eq('principal_uri', $qb->createNamedParameter(self::PRINCIPAL)))
			->executeStatement();
		parent::tearDown();
	}

	public function testAPollingConnectorSeesAWriteThatCommitsAfterItsCursorPassedIt(): void {
		$this->requireDistributedCache();
		// Pinned to polling, nothing but /changes ever reads the ledger.
		$this->config->setTransportMode(Constants::TRANSPORT_POLLING);
		$overlap = Constants::CN_REREAD_OVERLAP;
		$start = $this->ledger->maxSeq();
		[$slow, $fast, $connector] = [$this->worker(), $this->worker(), $this->worker()];

		$slowSeq = (int)$this->ask($slow, 'open ' . $this->uri('slow'));
		for ($i = 0; $i <= $overlap + 50; $i++) {
			$this->ask($fast, 'write ' . $this->uri('fast-' . $i));
		}
		[$cursor] = $this->read($connector, $start);
		$this->assertGreaterThan($slowSeq + $overlap, $cursor, 'precondition: the Connector is past the open write by more than the overlap');

		$this->ask($slow, 'commit');

		[, $uris] = $this->read($connector, max(0, $cursor - $overlap));
		$this->assertContains($this->uri('slow'), $uris);
	}

	public function testConcurrentFirstWritesToOneCollectionKeepTheUsersTransactionUsable(): void {
		$this->requireConcurrentDatabase();
		$shared = $this->uri('shared');
		$other = $this->uri('other');
		[$first, $second] = [$this->worker(), $this->worker()];

		$this->ask($first, 'open ' . $shared);
		// The second write of the same new collection waits on the first's
		// uncommitted row, which commits meanwhile. On PostgreSQL the second
		// insert then fails on the unique key — a failure that, outside a
		// savepoint, aborts the whole transaction the user's own calendar
		// write lives in, so the write after it would fail too.
		$this->send($first, 'commit 1000');
		$this->ask($second, 'txn ' . $shared . ' ' . $other);
		$this->expectOk($first);

		$this->assertSame([$other, $shared], $this->storedUris());
	}

	public function testACounterRestoredFromAnOlderSnapshotNeverReissuesAPassedNumber(): void {
		$this->requireDistributedCache();
		// Let the counter run ahead of the floor set when it was last seeded,
		// so the restored value below sits between that floor and the ledger.
		$warmup = $this->worker();
		for ($i = 0; $i < 200 && (int)$this->ask($warmup, 'write ' . $this->uri('warmup-' . $i)) < $this->storedSeqFloor() + 50; $i++) {
		}
		$ledgerMax = $this->ledger->maxSeq();
		$restored = $ledgerMax - 20;
		$this->assertGreaterThan($this->storedSeqFloor(), $restored, 'precondition: the restored counter is above the floor');

		// A fresh process: it knows no floor beyond the stored one.
		$writer = $this->worker();
		$this->ask($writer, 'restore-counter ' . $restored);

		$this->assertGreaterThan($ledgerMax, (int)$this->ask($writer, 'write ' . $this->uri('after-restore')));
	}

	private function requireDistributedCache(): void {
		if (\OCP\Server::get(IConfig::class)->getSystemValue('memcache.distributed', null) === null) {
			$this->markTestSkipped('needs memcache.distributed (Redis) for the counter and the fence');
		}
	}

	private function requireConcurrentDatabase(): void {
		if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE) {
			$this->markTestSkipped('SQLite locks the whole database; there is no row-level race to test');
		}
	}

	private function uri(string $name): string {
		return 'cn-it-' . $this->run . '-' . $name;
	}

	/** Read from the database: this process's app config cache would not see the workers' writes. */
	private function storedSeqFloor(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('configvalue')
			->from('appconfig')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter('sendentsynchroniser')))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter(Constants::CN_SEQ_FLOOR_KEY)));
		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();

		return (int)$value;
	}

	/** @return list<string> */
	private function storedUris(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('collection_uri')
			->from('sndntsync_dirty')
			->where($qb->expr()->eq('principal_uri', $qb->createNamedParameter(self::PRINCIPAL)))
			->orderBy('collection_uri');
		$result = $qb->executeQuery();
		$uris = [];
		while (($uri = $result->fetchOne()) !== false) {
			$uris[] = (string)$uri;
		}
		$result->closeCursor();

		return $uris;
	}

	/** @return array{process: resource, stdin: resource, stdout: resource, stderr: resource} */
	private function worker(): array {
		$process = proc_open(
			[PHP_BINARY, __DIR__ . '/bin/worker.php', \OC::$SERVERROOT, self::PRINCIPAL],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		$this->assertIsResource($process, 'could not start a worker process');
		$worker = ['process' => $process, 'stdin' => $pipes[0], 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
		$this->workers[] = $worker;

		return $worker;
	}

	/** @return array{0: int, 1: list<string>} the cursor and the collection URIs a check returned */
	private function read(array $worker, int $since): array {
		[$cursor, $uris] = explode(' ', $this->ask($worker, 'read ' . $since), 2);

		return [(int)$cursor, json_decode($uris, true)];
	}

	private function ask(array $worker, string $command): string {
		$this->send($worker, $command);

		return $this->expectOk($worker);
	}

	private function send(array $worker, string $command): void {
		fwrite($worker['stdin'], $command . "\n");
		fflush($worker['stdin']);
	}

	/** @return string the answer after "ok " */
	private function expectOk(array $worker): string {
		$read = [$worker['stdout']];
		$none = null;
		if (stream_select($read, $none, $none, self::ANSWER_TIMEOUT_SECONDS) !== 1) {
			stream_set_blocking($worker['stderr'], false);
			$this->fail('worker silent for ' . self::ANSWER_TIMEOUT_SECONDS . ' s; stderr: ' . stream_get_contents($worker['stderr']));
		}
		$line = trim((string)fgets($worker['stdout']));
		if ($line !== 'ok' && !str_starts_with($line, 'ok ')) {
			stream_set_blocking($worker['stderr'], false);
			$this->fail('worker answered "' . $line . '"; stderr: ' . stream_get_contents($worker['stderr']));
		}

		return substr($line, 3);
	}
}
