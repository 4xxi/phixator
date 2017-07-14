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
     * @param ManiphestTask $object
     */
    public function buildCurtainPanel(ManiphestTask $object)
    {
        $viewer = $this->getViewer();
        $status_view = new PHUIStatusListView();

        $xactions = (new ManiphestTransactionQuery())
            ->setViewer($viewer)
            ->withObjectPHIDs([$object->getPHID()])
            ->withTransactionTypes([LogTimeItemTransaction::TRANSACTIONTYPE])
            ->needComments(true)
            ->execute();

        $xactions = array_reverse($xactions);

        if (!$xactions) {
            return;
        }

        $grouped = [];
        $uidToUser = [];

        foreach ($xactions as $xaction) {
            $item = new PHUIStatusItemView();
            $item->setIcon(PHUIStatusItemView::ICON_CLOCK, null, $xaction->getNewValue()['description'] ?? '');
            $item->setTarget($xaction->getTitle());
            $status_view->addItem($item);
        }

        return $this->newPanel()
            ->setHeaderText(pht('Log work'))
            ->setOrder(40000)
            ->appendChild($status_view);
    }
}
