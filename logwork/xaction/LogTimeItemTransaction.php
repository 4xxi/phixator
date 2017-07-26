<?php

/**
 * Class LogTimeItemTransaction
 */
class LogTimeItemTransaction extends ManiphestTaskTransactionType
{
    const TRANSACTIONTYPE = 'timetracker:log';

    public function getTitle()
    {
        $newValue = $this->getNewValue();

        return hsprintf(
            '%s logged <a href="/phixator/edit/%s/" data-sigil="workflow">%s</a> at %s, %s %s',
            $this->renderAuthor(),
            $this->getStorage()->getPHID(),
            $newValue['spend'],
            phabricator_date($newValue['started'], $this->getViewer()),
            phabricator_time($newValue['started'], $this->getViewer()),
            $newValue['description'] ? ': '.$newValue['description'] : ''
        );
    }

    public function generateOldValue($object) {
        return '';
    }

    public function getIcon() {
        return 'fa-clock-o';
    }
}
