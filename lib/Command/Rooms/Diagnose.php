<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Command\Rooms;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\SendentSynchroniser\Calendar\Resource\RoomBackend;
use OCA\SendentSynchroniser\Db\RoomMapper;
use OCA\SendentSynchroniser\Service\Room\CalDAVService;
use OCA\SendentSynchroniser\Service\Room\HiddenUserService;
use OCP\Calendar\Room\IManager;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Diagnostic walk: prints the chain of state that NC's Calendar app's resource
 * picker depends on. Run this to figure out where the chain breaks.
 */
class Diagnose extends Command {

	public function __construct(
		private RoomMapper $rooms,
		private RoomBackend $backend,
		private IManager $roomManager,
		private IUserManager $userManager,
		private CalDavBackend $calDav,
		private HiddenUserService $hiddenUsers,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sendentsynchroniser:rooms:diagnose')
			->setDescription('Walk the picker chain (DB row → backend → NC IManager → CalDAV). Prints every step.')
			->addOption('refresh-cache', null, InputOption::VALUE_NONE, 'Call IRoomManager::update() to populate oc_calendar_rooms for existing rooms (use after deploying the fix when there are pre-existing rooms in the DB).');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$output->writeln('<info>=== Step 1: rows in sndntsync_rooms ===</info>');
		$rows = $this->rooms->findAll();
		if (empty($rows)) {
			$output->writeln('<error>No rooms in DB. Create one first.</error>');
			return 1;
		}
		foreach ($rows as $r) {
			$output->writeln(sprintf(
				'  %s  active=%s  email=%s  principal=%s',
				$r->getId(),
				$r->getActive() ? 'true' : 'FALSE',
				$r->getEmail() ?? '(null)',
				$r->getBackingPrincipalUri() ?? '(null)',
			));
		}

		$output->writeln('');
		$output->writeln('<info>=== Step 2: RoomBackend::getAllRooms() — what our backend exposes ===</info>');
		$ourRooms = $this->backend->getAllRooms();
		$output->writeln('  count: ' . count($ourRooms));
		foreach ($ourRooms as $room) {
			$output->writeln(sprintf(
				'  id=%s  displayName=%s  email=%s',
				$room->getId(),
				$room->getDisplayName(),
				$room->getEMail(),
			));
		}

		$output->writeln('');
		$output->writeln('<info>=== Step 3: NC IManager::getBackends() — is our backend registered? ===</info>');
		$backends = $this->roomManager->getBackends();
		foreach ($backends as $b) {
			$output->writeln('  ' . $b->getBackendIdentifier() . ' (' . get_class($b) . ')');
		}
		$ours = array_filter($backends, fn ($b) => $b->getBackendIdentifier() === RoomBackend::BACKEND_IDENTIFIER);
		if (empty($ours)) {
			$output->writeln('<error>  ! Our backend is NOT registered with NC. App boot may have failed.</error>');
		} else {
			$output->writeln('<info>  ✓ Our backend is registered.</info>');
		}

		$output->writeln('');
		$output->writeln('<info>=== Step 4: hidden users — does NC see _room_<id>? ===</info>');
		foreach ($rows as $r) {
			$uid = $this->hiddenUsers->uidFor($r->getId());
			$exists = $this->userManager->userExists($uid);
			$output->writeln('  ' . $uid . ' → userExists: ' . ($exists ? 'true' : 'FALSE'));
		}

		$output->writeln('');
		$output->writeln('<info>=== Step 5: CalDAV calendars — does the room calendar exist? ===</info>');
		foreach ($rows as $r) {
			$cal = $this->calDav->getCalendarByUri($r->getBackingPrincipalUri(), CalDAVService::CALENDAR_URI);
			if ($cal === null) {
				$output->writeln('<error>  ' . $r->getId() . ': NO CALENDAR FOUND (uri: ' . $r->getBackingPrincipalUri() . '/' . CalDAVService::CALENDAR_URI . ')</error>');
			} else {
				$output->writeln('  ' . $r->getId() . ': calendar id=' . $cal['id'] . ' uri=' . CalDAVService::CALENDAR_URI);
			}
		}

		$output->writeln('');
		$output->writeln('<info>=== Step 6: oc_calendar_rooms cache rows for our backend ===</info>');
		try {
			$db = \OC::$server->get(\OCP\IDBConnection::class);
			$qb = $db->getQueryBuilder();
			$qb->select('*')
				->from('calendar_rooms')
				->where($qb->expr()->eq('backend_id', $qb->createNamedParameter(RoomBackend::BACKEND_IDENTIFIER)));
			$result = $qb->executeQuery();
			$cacheRows = $result->fetchAll();
			$result->closeCursor();
			if (empty($cacheRows)) {
				$output->writeln('<comment>  (no rows in oc_calendar_rooms for our backend — daily cron has not run yet)</comment>');
				$output->writeln('  Run: occ background-job:list | grep UpdateCalendarResourcesRoomsBackgroundJob');
				$output->writeln('       occ background-job:execute <id>');
			} else {
				foreach ($cacheRows as $row) {
					$output->writeln('  ' . $row['resource_id'] . '  email=' . $row['email'] . '  displayname=' . $row['displayname']);
				}
			}
		} catch (\Throwable $e) {
			$output->writeln('<error>  Could not query oc_calendar_rooms: ' . $e->getMessage() . '</error>');
		}

		if ($input->getOption('refresh-cache')) {
			$output->writeln('');
			$output->writeln('<info>=== Step 7: --refresh-cache requested — calling IRoomManager::update() ===</info>');
			try {
				$this->roomManager->update();
				$output->writeln('<info>  ✓ Cache refresh fired. Re-run without --refresh-cache to verify step 6 now has rows.</info>');
			} catch (\Throwable $e) {
				$output->writeln('<error>  Refresh failed: ' . $e->getMessage() . '</error>');
				return 1;
			}
			return Command::SUCCESS;
		}

		$output->writeln('');
		$output->writeln('<info>If steps 1-5 show your room and step 6 is empty:</info>');
		$output->writeln('  • For NEW rooms going forward: IRoomManager::update() now runs automatically on create/update/delete (deploy the latest RoomService.php).');
		$output->writeln('  • For EXISTING rooms (created before the fix): re-run with --refresh-cache to populate the cache once.');
		$output->writeln('  • Or wait for OCA\DAV\BackgroundJob\UpdateCalendarResourcesRoomsBackgroundJob (daily cron).');
		return Command::SUCCESS;
	}
}
