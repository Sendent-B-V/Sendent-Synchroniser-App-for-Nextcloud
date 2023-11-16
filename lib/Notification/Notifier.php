<?php

namespace OCA\SendentSynchroniser\Notification;

use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use \OCP\ILogger;

class Notifier implements INotifier {

    /** @var IFactory */
    protected $factory;

    /** @var ILogger */
	private $logger;

    /** @var IURLGenerator */
    protected $url;

    public function __construct(IFactory $factory, ILogger $logger, IURLGenerator $URLGenerator)
    {
        $this->factory = $factory;
        $this->logger = $logger;
        $this->url = $URLGenerator;
        $this->logger->info('Constructed notifier');
    }

    /**
     * Identifier of the notifier, only use [a-z0-9_]
     * @return string
     */
    public function getID(): string {
        return 'sendentsynchroniser';
    }

    /**
     * Human readable name describing the notifier
     * @return string
     */
    public function getName(): string {
        return $this->factory->get('sendentsynchroniser')->t('Sendent synchroniser');
    }

    /**
     * @param INotification $notification
     * @param string $languageCode The code of the language that should be used to prepare the notification
     */
    public function prepare(INotification $notification, string $languageCode): INotification {
        if ($notification->getApp() !== 'sendentsynchroniser') {
            // Not my app
            throw new \InvalidArgumentException();
        }
        $l = $this->factory->get('sendentsynchroniser', $languageCode);

        // Adds icon and link
        $notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath('sendentsynchroniser', 'app-dark.svg')))
            ->setLink($this->url->linkToRouteAbsolute('settings.PersonalSettings.index', ['section' => 'sendentsynchroniser']));

        // Creates subject (previously set subject is replaced)
        $subject = $l->t('Please activate your Exchange synchronisation');
        $notification->setParsedSubject($subject);

        return $notification;
    }

}