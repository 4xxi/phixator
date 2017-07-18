<?php

/**
 * Class TimeTrackerLogworkHistoryCurtainExtension
 */
class TimeTrackerLogworkHistoryCurtainExtension extends PHUICurtainExtension
{
    const EXTENSIONKEY = 'timetracker.log_history';

    public function shouldEnableForObject($object)
    {
        return ($object instanceof ManiphestTask);
    }

    public function getExtensionApplication()
    {
        return new PhabricatorTimeTrackerApplication();
    }

    /**
     * @param ManiphestTask $task
     */
    public function buildCurtainPanel(ManiphestTask $task)
    {
        if (!$xactions = $this->getTaskTimeTransactions($task)) {
            return;
        }

        $status_view = new PHUIStatusListView();
        $summarySpendByUser = [];

        foreach ($xactions as $xaction) {
            $item = new PHUIStatusItemView();
            $item->setIcon(PHUIStatusItemView::ICON_CLOCK, null, $xaction->getNewValue()['description'] ?? '');
            $item->setTarget($xaction->getTitle());
            $status_view->addItem($item);

            $summarySpendByUser[$xaction->getAuthorPHID()] += TimeLogHelper::timeLogToMinutes(
                $xaction->getNewValue()['spend'] ?? ''
            );
        }

        if ($summarySpendByUser) {
            $status_view->addItem((new PHUIStatusItemView())->setTarget('Summary:'));

            $uidToUser = DataRetriverHelper::getAuthorsByPHIDs(array_keys($summarySpendByUser));

            foreach ($summarySpendByUser as $userPHID => $spendMinutes) {
                if (!$author = $uidToUser[$userPHID] ?? null) {
                    continue;
                }

                $authorUrl = (new PhabricatorObjectHandle())
                    ->setType(phid_get_type($userPHID))
                    ->setPHID($userPHID)
                    ->setName($author->getUsername())
                    ->setURI('/p/'.$author->getUsername().'/')
                    ->renderLink();

                $item = (new PHUIStatusItemView())
                        ->setIcon(PHUIStatusItemView::ICON_CLOCK)
                        ->setTarget(hsprintf('%s spend %s', $authorUrl, TimeLogHelper::minutesToTimeLog($spendMinutes)));

                $status_view->addItem($item);
            }
        }

        return $this->newPanel()
            ->setHeaderText(pht('Log work'))
            ->setOrder(40000)
            ->appendChild($status_view);
    }

    /**
     * @param ManiphestTask $object
     *
     * @return ManiphestTransaction[]
     */
    protected function getTaskTimeTransactions(ManiphestTask $object): array
    {
        $xactions = (new ManiphestTransactionQuery())
            ->setViewer($this->getViewer())
            ->withObjectPHIDs([$object->getPHID()])
            ->withTransactionTypes([LogTimeItemTransaction::TRANSACTIONTYPE])
            ->needComments(true)
            ->execute();

        return array_reverse($xactions);
    }
}
