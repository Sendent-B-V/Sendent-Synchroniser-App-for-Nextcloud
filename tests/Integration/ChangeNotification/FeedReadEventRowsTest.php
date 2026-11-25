<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Integration\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Controller\ChangeFeedApiController;
use OCA\SendentSynchroniser\Db\DirtyCollectionMapper;
use OCA\SendentSynchroniser\Db\EventChangeRowMapper;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeFeedGuard;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\CursorService;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushAvailability;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The feed read over the two real tables, page by page. Only a real
 * database shows that the two queries and the join agree on the order.
 */
class FeedReadEventRowsTest extends TestCase {

	private const PRINCIPAL = 'principals/users/cn-feed-read-integration';

	private IDBConnection $db;
	private EventChangeRowMapper $eventRows;
	private DirtyCollectionMapper $dirty;

	protected function setUp(): void {
		parent::setUp();
		$this->db = \OCP\Server::get(IDBConnection::class);
		$this->eventRows = \OCP\Server::get(EventChangeRowMapper::class);
		$this->dirty = \OCP\Server::get(DirtyCollectionMapper::class);
	}

	protected function tearDown(): void {
		foreach ([EventChangeRowMapper::TABLE, DirtyCollectionMapper::TABLE] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->eq('principal_uri', $qb->createNamedParameter(self::PRINCIPAL)))
				->executeStatement();
		}
		parent::tearDown();
	}

	public function testEveryRowIsSeenInNumberOrderOverSeveralPages(): void {
		// Above every row that is in the tables, so the test sees its own rows only.
		$base = max($this->eventRows->maxSeq(), $this->dirty->maxSeq()) + 1000;

		$this->eventRows->append(self::PRINCIPAL, 'personal', 'one.ics', 'created', $base + 1, 1);
		$this->eventRows->append(self::PRINCIPAL, 'personal', 'two.ics', 'updated', $base + 2, 1);
		$this->calendar('personal', $base + 3);
		$this->calendar('work', $base + 4);
		$this->eventRows->append(self::PRINCIPAL, 'work', 'three.ics', 'deleted', $base + 5, 1);
		$this->calendar('contacts', $base + 6);
		$this->eventRows->append(self::PRINCIPAL, 'personal', 'one.ics', 'updated', $base + 7, 1);

		// The fence is above all rows, as it is when no writer is active.
		$controller = $this->controller($base + 100);

		$seen = [];
		$fileNames = [];
		$since = $base;
		$pages = 0;
		do {
			$data = $controller->changes($since, 2)->getData();
			$this->assertLessThanOrEqual(2, count($data['refs']));
			$this->assertGreaterThan($since, $data['cursor'], 'A page with rows moves the cursor.');
			foreach ($data['refs'] as $ref) {
				$seen[] = $ref['q'] - $base;
				$fileNames[] = $ref['o'] ?? null;
			}
			$since = $data['cursor'];
			$pages++;
		} while ($data['has_more'] && $pages < 10);

		// No row is seen twice here: the fence is above all rows, so each
		// cursor is the number of the last row of its page.
		// A row above the fence is seen again: the cursor stops at the fence.
		$this->assertSame([1, 2, 3, 4, 5, 6, 7], $seen);
		$this->assertSame(['one.ics', 'two.ics', null, null, 'three.ics', null, 'one.ics'], $fileNames);
		$this->assertSame(4, $pages);
		$this->assertFalse($data['has_more']);
		$this->assertSame($base + 7, $since);
	}

	private function calendar(string $uri, int $seq): void {
		$type = $uri === 'contacts' ? 'carddav' : 'caldav';
		$this->dirty->record(new CollectionReference(self::PRINCIPAL, $type, $uri, false), $seq, 1);
	}

	private function controller(int $fence): ChangeFeedApiController {
		// The counter and the fence are fixed, so nothing is written to the shared cache.
		$cursor = $this->createMock(CursorService::class);
		$cursor->method('current')->willReturn($fence);

		$guard = $this->createMock(ChangeFeedGuard::class);
		$guard->method('isAllowed')->willReturn(true);
		$serverConfig = $this->createMock(IConfig::class);
		$serverConfig->method('getSystemValueString')->willReturn('inst');

		$ledger = new ChangeLedgerService(
			$this->dirty,
			$cursor,
			\OCP\Server::get(ITimeFactory::class),
			$this->db,
			$this->eventRows,
		);

		return new ChangeFeedApiController(
			'sendentsynchroniser',
			$this->createMock(IRequest::class),
			$guard,
			$ledger,
			$this->createMock(ChangeNotificationConfig::class),
			$cursor,
			$this->createMock(NotifyPushAvailability::class),
			$serverConfig,
		);
	}
}
