<?php
namespace OCA\SendentSynchroniser\Cron;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Notification\IManager;

class NotifyInactiveUsers extends TimedJob {

    /** @var IManager */
     private $notificationManager;
  
    public function __construct(ITimeFactory $time, IAppConfig $appConfig, IManager $notificationManager,

    ) {
        parent::__construct($time);

        $this->notificationManager = $notificationManager;

        // Sets the job to run at specified interval
        $interval = $appConfig->getAppValue('notificationInterval', '7');
        $interval = $interval * 24 * 3600;
        $this->setInterval($interval);
    }

    protected function run() {
        $notification = $this->notificationManager->createNotification();
		$notification->setApp('sendentsynchroniser')
 		   ->setUser('admin')
    		->setDateTime(new \DateTime())
			->setObject('settings', 'admin')
    		->setSubject('Please activate your Exchange synchronisation');
		$this->notificationManager->notify($notification);

		return new DataResponse(FALSE);

    }

}