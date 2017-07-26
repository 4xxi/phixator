<?php

/**
 * Class TimeTrackerUIEventListener
 */
class TimeTrackerUIEventListener extends PhabricatorEventListener
{
    /**
     * Register listeners.
     */
    public function register()
    {
        $this->listen(PhabricatorEventType::TYPE_UI_DIDRENDERACTIONS);
    }

    /**
     * @param PhutilEvent $event
     */
    public function handleEvent(PhutilEvent $event)
    {
        switch ($event->getType()) {
            case PhabricatorEventType::TYPE_UI_DIDRENDERACTIONS:
                $this->handleActionEvent($event);
                break;
        }
    }

    /**
     * @param PhutilEvent $event
     */
    private function handleActionEvent(PhutilEvent $event)
    {
        $user = $event->getUser();
        $object = $event->getValue('object');

        if (!$object || !$object->getPHID()) {
            return;
        }

        if (!($object instanceof ManiphestTask)) {
            return;
        }

        if (!$this->canUseApplication($event->getUser())) {
            return;
        }

        $track_action = id(new PhabricatorActionView())
            ->setName(pht('Log work'))
            ->setIcon('fa-clock-o')
            ->setWorkflow(true)
            ->setHref('/phixator/log/'.$object->getPHID().'/');

        if (!$user->isLoggedIn()) {
            $track_action->setDisabled(true);
        }

        $this->addActionMenuItems($event, $track_action);
    }
}