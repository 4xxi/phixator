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

        return pht(
            '%s logged %s at %s, %s %s',
            $this->renderAuthor(),
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
