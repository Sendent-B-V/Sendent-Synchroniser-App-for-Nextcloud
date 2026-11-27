<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Integration\ChangeNotification;

use OCA\SendentSynchroniser\Db\EventChangeRowMapper;
use OCA\SendentSynchroniser\Listener\UserGoneListener;
use OCA\SendentSynchroniser\Service\ChangeNotification\EventRowCleaner;
use OCA\SendentSynchroniser\Service\SyncUserService;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A deleted user against the real table: the rows of that user go and the
 * rows of another user stay.
 */
class EventRowsUserRemovalTest extends TestCase {

	private const GONE = 'cn-removal-gone';
	private const STAYS = 'cn-removal-stays';

	private IDBConnection $db;
	private EventChangeRowMapper $eventRows;

	protected function setUp(): void {
		parent::setUp();
		$this->db = \OCP\Server::get(IDBConnection::class);
		$this->eventRows = \OCP\Server::get(EventChangeRowMapper::class);

		// Numbers above every row in the table: the column has a unique index.
		$number = $this->eventRows->maxSeq() + 1000;
		$this->eventRows->append('principals/users/' . self::GONE, 'personal', 'one.ics', 'created', $number + 1, 1);
		$this->eventRows->append('principals/users/' . self::STAYS, 'personal', 'two.ics', 'created', $number + 2, 1);
		$this->eventRows->append('principals/users/' . self::GONE, 'work', 'three.ics', 'updated', $number + 3, 1);
		$this->eventRows->append('principals/users/' . self::STAYS, 'personal', 'four.ics', 'deleted', $number + 4, 1);
	}

	protected function tearDown(): void {
		foreach ([self::GONE, self::STAYS] as $uid) {
			$this->eventRows->deleteForPrincipal('principals/users/' . $uid);
		}
		parent::tearDown();
	}

	/** @return list<string> the file names in the rows of one user */
	private function fileNamesOf(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('event_file_name')
			->from(EventChangeRowMapper::TABLE)
			->where($qb->expr()->eq('principal_uri', $qb->createNamedParameter('principals/users/' . $uid)))
			->orderBy('sequence_number');
		$result = $qb->executeQuery();
		$names = [];
		while (($row = $result->fetch()) !== false) {
			$names[] = (string)$row['event_file_name'];
		}
		$result->closeCursor();

		return $names;
	}

	public function testOnlyTheRowsOfTheDeletedUserAreRemoved(): void {
		$listener = new UserGoneListener(
			new EventRowCleaner($this->eventRows, new NullLogger()),
			$this->createMock(SyncUserService::class),
			new NullLogger(),
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn(self::GONE);

		$listener->handle(new UserDeletedEvent($user));

		$this->assertSame([], $this->fileNamesOf(self::GONE));
		$this->assertSame(['two.ics', 'four.ics'], $this->fileNamesOf(self::STAYS));
	}
}
