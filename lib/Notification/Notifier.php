<?php

namespace OCA\SendentSynchroniser\Notification;

use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

class Notifier implements INotifier {
    protected $factory;
    protected $url;

    public function __construct(IFactory $factory, IURLGenerator $URLGenerator)
    {
        $this->factory = $factory;
        $this->url = $URLGenerator;
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

        $parameters = $notification->getSubjectParameters();
        $notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath('sendentsynchroniser', 'app-dark.svg')));
        $parameters = $notification->getSubjectParameters();
        $subject = $l->t('Please activate your Exchange synchronisation');

        $notification->setParsedSubject($subject);

        return $notification;
    }

}