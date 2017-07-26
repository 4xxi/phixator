<?php

/**
 * Class PhabricatorTimeTrackerApplication
 */
class PhabricatorTimeTrackerApplication extends PhabricatorApplication
{
    public function getName()
    {
        return pht('Phixator');
    }

    public function getShortDescription()
    {
        return pht('Allow to log work time by tasks');
    }

    public function getBaseURI()
    {
        return '/phixator/';
    }

    public function isPrototype()
    {
        return true;
    }

    public function getIcon()
    {
        return 'fa-clock-o';
    }

    public function getApplicationGroup()
    {
        return self::GROUP_UTILITIES;
    }

    public function getApplicationOrder()
    {
        return 0.110;
    }

    public function getEventListeners()
    {
        return array(
            new TimeTrackerUIEventListener(),
        );
    }

    public function getRoutes()
    {
        return [
            '/phixator/' => [
                '(?P<verb>[a-z]+)/(?P<phid>[^/]+)/' => 'TimeTrackerLogController',
                '(?:(?P<view>\w+)/)?' => 'TimeTrackerReportsController',
            ],
        ];
    }
}
