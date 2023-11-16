<?php
namespace OCA\SendentSynchroniser\Cron;

use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Notification\IManager;
use \OCP\ILogger;
use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Service\SyncUserService;

class NotifyInactiveUsers extends TimedJob {

    /** @var IAppConfig */
    private $config;

    /** @var ILogger */
	private $logger;

    /** @var IManager */
    private $notificationManager;

    /** @var SyncUserService */
    private $syncUserService;

    public function __construct(ITimeFactory $time, IAppConfig $config, ILogger $logger,
        IManager $notificationManager, SyncUserService $syncUserService) {
        parent::__construct($time);

	    $this->config = $config;
        $this->logger = $logger;
        $this->notificationManager = $notificationManager;
        $this->syncUserService = $syncUserService;

        // Sets the job to run at specified interval
        $interval = $config->getAppValue('notificationInterval',  Constants::REMINDER_NOTIFICATIONS_DEFAULT_INTERVAL);
        $interval = intval($interval) * 24 * 3600;
        $this->setInterval($interval);
    }

    protected function run($arguments) {

        // Is shared secret configured?
          if (empty($this->config->getAppValue('sharedSecret', ''))) {
            $this->logger->info('Not sending notifications as sharedSecret is not configured');
			return;
		};

        // TODO: Check licensing?

        // Should we send notifications?
        if ($this->config->getAppValue('reminderType', Constants::REMINDER_NOTIFICATIONS) === Constants::REMINDER_MODAL) {
            $this->logger->info('Not sending notifications as reminderType is set to Modal only');
            return;
        }

        // Gets list of invalid users (users who have opt out of sendent sync are not counted as invalid)
        $inactiveUsers = $this->syncUserService->getInvalidUsers();

        // Defers sending notifications to avoid multiple connections to the server
        //$shouldFlush = $this->notificationManager->defer();

        // Prepare notifications for all invalid users
        foreach ($inactiveUsers as $inactiveUser) {
            $this->logger->info('Sending notification to user ' . $inactiveUser->getUid());
            $notification = $this->notificationManager->createNotification();
            $notification->setApp('sendentsynchroniser')
                ->setUser($inactiveUser->getUid())
                ->setDateTime(new \DateTime())
                ->setObject('settings', 'admin')
                ->setSubject('Please activate your Exchange synchronisation');
            $this->notificationManager->notify($notification);
        }

        // Sends notifications (if no other app is already deferring)
        //if ($shouldFlush) {
        //    $this->notificationManager->flush();
        //}

        $this->logger->info('Sent notification to ' . count($inactiveUsers) . ' user(s)');

		return;

    }

}
