<?php
declare(strict_types=1);

// A normally bootstrapped Nextcloud process for the change-notification
// integration tests. The tests cannot do this work themselves: under PHPUnit
// Nextcloud replaces every cache with a per-process ArrayCache, so the
// sequence counter and the fence would not be shared with other processes.
//
// Usage: php worker.php <server root> <principal uri>
// Reads one command per line, answers one line: "ok[ <result>]" or "error <message>".
//
//   write <uri>            one DAV request: its own transaction, then the post-commit step
//   open <uri>             a DAV write whose transaction stays open
//   commit [<delay ms>]    commit what `open` started, then the post-commit step
//   txn <uri> <uri> ...    several writes in one transaction (blocks while rows are locked)
//   read <since>           page /changes from <since> until has_more is false
//                          -> "ok <cursor> <json list of collection uris>"
//   restore-counter <n>    set the cache counter, as a Redis restored from an older snapshot would

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Controller\ChangeFeedApiController;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeFeedGuard;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\PostCommitQueue;

[, $serverRoot, $principal] = $argv;

require $serverRoot . '/lib/base.php';
\OC_App::loadApps();

$db = \OCP\Server::get(\OCP\IDBConnection::class);
$ledger = \OCP\Server::get(ChangeLedgerService::class);
$postCommit = \OCP\Server::get(PostCommitQueue::class);
$ref = static fn (string $uri): CollectionReference => new CollectionReference($principal, 'caldav', $uri, false);

$feed = new ChangeFeedApiController(
	'sendentsynchroniser',
	\OCP\Server::get(\OCP\IRequest::class),
	new class(\OCP\Server::get(\OCP\IUserSession::class), \OCP\Server::get(\OCP\IGroupManager::class), \OCP\Server::get(\OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig::class)) extends ChangeFeedGuard {
		public function isAllowed(): bool {
			return true;
		}
	},
	$ledger,
	\OCP\Server::get(\OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig::class),
	\OCP\Server::get(\OCA\SendentSynchroniser\Service\ChangeNotification\CursorService::class),
	\OCP\Server::get(\OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushAvailability::class),
	\OCP\Server::get(\OCP\IConfig::class),
);

$answer = static function (string $line): void {
	fwrite(STDOUT, $line . "\n");
	fflush(STDOUT);
};

$open = [];
while (($line = fgets(STDIN)) !== false) {
	$args = preg_split('/\s+/', trim($line));
	$command = array_shift($args);
	try {
		switch ($command) {
			case 'write':
				$db->beginTransaction();
				$stamps = $ledger->record([$ref($args[0])]);
				$db->commit();
				$postCommit->add($stamps);
				$answer('ok ' . $stamps[0]['seq']);
				break;

			case 'open':
				$db->beginTransaction();
				$open = $ledger->record([$ref($args[0])]);
				$answer('ok ' . $open[0]['seq']);
				break;

			case 'commit':
				usleep((int)($args[0] ?? 0) * 1000);
				$db->commit();
				$postCommit->add($open);
				$open = [];
				$answer('ok');
				break;

			case 'txn':
				$db->beginTransaction();
				try {
					$stamps = [];
					foreach ($args as $uri) {
						$stamps = array_merge($stamps, $ledger->record([$ref($uri)]));
					}
					$db->commit();
				} catch (\Throwable $e) {
					$db->rollBack();
					throw $e;
				}
				$postCommit->add($stamps);
				$answer('ok');
				break;

			case 'read':
				$since = (int)$args[0];
				$uris = [];
				do {
					$data = $feed->changes($since, 1000)->getData();
					foreach ($data['refs'] as $r) {
						$uris[] = $r['u'];
					}
					$since = $data['cursor'];
				} while ($data['has_more']);
				$answer('ok ' . $since . ' ' . json_encode($uris));
				break;

			case 'restore-counter':
				\OCP\Server::get(\OCP\ICacheFactory::class)->createDistributed('sndntsync_cn/')->set('cursor', (int)$args[0]);
				$answer('ok');
				break;

			default:
				$answer('error unknown command ' . $command);
		}
	} catch (\Throwable $e) {
		$answer('error ' . get_class($e) . ': ' . str_replace("\n", ' ', $e->getMessage()));
	}
}
