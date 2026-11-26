<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Listener;

use OCA\SendentSynchroniser\Db\EventChangeRowMapper;
use OCA\SendentSynchroniser\Listener\UserGoneListener;
use OCA\SendentSynchroniser\Service\ChangeNotification\EventRowCleaner;
use OCA\SendentSynchroniser\Service\SyncUserService;
use OCP\EventDispatcher\Event;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IGroup;
use OCP\IUser;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The cleaner is the real one, so each test checks the mapper call and the
 * log line that the whole path gives.
 */
class UserGoneListenerTest extends TestCase {

	private EventChangeRowMapper&MockObject $eventRows;
	private LoggerInterface&MockObject $logger;
	private SyncUserService&MockObject $syncUsers;
	private UserGoneListener $listener;

	protected function setUp(): void {
		$this->eventRows = $this->createMock(EventChangeRowMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->syncUsers = $this->createMock(SyncUserService::class);
		$this->listener = new UserGoneListener(
			new EventRowCleaner($this->eventRows, $this->logger),
			$this->syncUsers,
		);
	}

	private function user(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	private function removedFromGroup(string $uid): UserRemovedEvent {
		return new UserRemovedEvent($this->createMock(IGroup::class), $this->user($uid));
	}

	// ---- the user is deleted ---------------------------------------------

	public function testADeletedUserLosesTheRows(): void {
		$this->eventRows->expects($this->once())
			->method('deleteForPrincipal')
			->with('principals/users/alice')
			->willReturn(2);

		$this->listener->handle(new UserDeletedEvent($this->user('alice')));
	}

	public function testADeletedUserIsNotAskedForTheSyncStatus(): void {
		// The groups of a deleted user are gone before this event. The
		// answer is known, so the question costs a query for nothing.
		$this->syncUsers->expects($this->never())->method('isValidUser');

		$this->listener->handle(new UserDeletedEvent($this->user('alice')));
	}

	public function testAFailedRemovalDoesNotStopTheDeletionOfTheUser(): void {
		$this->eventRows->method('deleteForPrincipal')
			->willThrowException(new \RuntimeException('database is away'));
		$this->logger->expects($this->once())->method('error');

		$this->listener->handle(new UserDeletedEvent($this->user('alice')));
	}

	// ---- the user is removed from a group --------------------------------

	public function testAUserWhoLeftTheLastActiveGroupLosesTheRows(): void {
		$this->syncUsers->method('isValidUser')->with('alice')->willReturn(false);

		$this->eventRows->expects($this->once())
			->method('deleteForPrincipal')
			->with('principals/users/alice')
			->willReturn(2);

		$this->listener->handle($this->removedFromGroup('alice'));
	}

	public function testAUserWhoIsStillASyncUserKeepsTheRows(): void {
		$this->syncUsers->method('isValidUser')->with('alice')->willReturn(true);

		$this->eventRows->expects($this->never())->method('deleteForPrincipal');

		$this->listener->handle($this->removedFromGroup('alice'));
	}

	public function testAFailedRemovalDoesNotStopTheGroupChange(): void {
		$this->syncUsers->method('isValidUser')->willReturn(false);
		$this->eventRows->method('deleteForPrincipal')
			->willThrowException(new \RuntimeException('database is away'));
		$this->logger->expects($this->once())->method('error');

		$this->listener->handle($this->removedFromGroup('alice'));
	}

	// ---- other events ----------------------------------------------------

	public function testAnotherEventRemovesNothing(): void {
		$this->eventRows->expects($this->never())->method('deleteForPrincipal');

		$this->listener->handle(new Event());
	}
}
