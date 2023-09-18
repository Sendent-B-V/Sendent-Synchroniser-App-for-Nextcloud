<?php
namespace OCA\SendentSynchroniser\Cron;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Notification\IManager;
use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Service\SyncUserService;

class NotifyInactiveUsers extends TimedJob {

    /** @var IManager */
     private $notificationManager;

     /** @var SyncUserService */
     private $syncUserService;

    public function __construct(ITimeFactory $time, IAppConfig $appConfig, IManager $notificationManager, SyncUserService $syncUserService) {
        parent::__construct($time);

        $this->notificationManager = $notificationManager;
        $this->syncUserService = $syncUserService;

        // Sets the job to run at specified interval
        $interval = $appConfig->getAppValue('notificationInterval',  Constants::REMINDER_NOTIFICATIONS_DEFAULT_INTERVAL);
        $interval = $interval * 24 * 3600;
        $this->setInterval($interval);
    }

    protected function run() {

        // Is shared secret configured?
          if (empty($this->appConfig->getAppValue('sharedSecret', ''))) {
			return;
		};

        // TODO: Check licensing?

        // Should we send notifications?
        if ($appConfig->getAppValue('reminderType', Constants::REMINDER_NOTIFICATIONS) === Constants::REMINDER_MODAL) {
            return;
        }

        // Gets list of invalid users (users who have opt out of sendent sync are not counted as invalid)
        $inactiveUsers = $this->syncUserService->getInvalidUsers();

        // Defers sending notifications to avoid multiple connections to the server
        $shouldFlush = $this->notificationManager->defer();

        // Prepare notifications for all invalid users
        foreach ($inactiveUsers as $inactiveUser) {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp('sendentsynchroniser')
                ->setUser($inactiveUser->getUid())
                ->setDateTime(new \DateTime())
                ->setObject('settings', 'admin')
                ->setSubject('Please activate your Exchange synchronisation');
        }

        // Sends notifications (if no other app is already deferring)
        if ($shouldFlush) {
            $this->notificationManager->flush();
        }

		return;

    }

}