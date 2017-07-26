<?php

/**
 * Class TimeTrackerLogController
 */
class TimeTrackerLogController extends PhabricatorController
{
    /**
     * @var string
     */
    private $verb;

    /**
     * @var string
     */
    private $phid;

    /**
     * @var array
     */
    private $formErrors;

    /**
     * @param array $data
     */
    public function willProcessRequest(array $data)
    {
        $this->phid = $data['phid'];
        $this->verb = $data['verb'];
    }

    /**
     * Application router method.
     *
     * @return Aphront404Response
     */
    public function handleRequest()
    {
        $request = $this->getRequest();
        $viewer = $request->getUser();

        $this->formErrors = [];

        $viewer_spaces = PhabricatorSpacesNamespaceQuery::getViewerSpaces($viewer);

        switch ($this->verb) {
            case 'log':
                $time = trim($request->getStr('time')) ?? false;
                $description = $request->getStr('description') ?? '';
                $timestamp = AphrontFormDateControlValue::newFromRequest($request, 'started');

                if (!$timestamp->isValid()) {
                    $this->formErrors[] = pht('Please choose a valid date.');
                }

                if ($request->isDialogFormPost()) {

                    if (!$time) {
                        $this->formErrors[] = pht('Please pick spend time.');
                    }

                    if ($time && !$this->isTimeFormatCorrect($time)) {
                        $this->formErrors[] = pht(
                            'Spend time is incorrect. Allowed format is: 1w 4d 2h 30m. ' .
                            'You can specify any those modificators at any order.'
                        );
                    }

                    if (!$this->formErrors) {
                        $task = id(new ManiphestTaskQuery())
                            ->setViewer($viewer)
                            ->withPHIDs([$this->phid])
                            ->needSubscriberPHIDs(true)
                            ->executeOne();

                        $content_source = PhabricatorContentSource::newFromRequest($request);
                        $editor = id(new ManiphestTransactionEditor())
                            ->setActor($request->getUser())
                            ->setContentSource($content_source)
                            ->setContinueOnNoEffect(true);

                        $transactions[] = id(new ManiphestTransaction())
                            ->setTransactionType('timetracker:log')
                            ->setNewValue([
                                'spend' => $time,
                                'started' => $timestamp->getEpoch(),
                                'description' => $description
                            ]);

                        $editor->applyTransactions($task, $transactions);

                        return id(new AphrontRedirectResponse())->setURI('/'.$task->getMonogram());
                    }
                }

                return $this->buildAddTimeLogDialog($time, $description, $timestamp->getEpoch());

                break;

            case 'edit':
                if (!$transaction = $this->getTimelogTransacrionByPHID($this->phid)) {
                    return new Aphront404Response();
                }

                if ($transaction->getAuthorPHID() !== $viewer->getPHID() && !$viewer->getIsAdmin()) {
                    return new Aphront404Response();
                }

                if ($request->isFormPost()) {
                    $userPHID = $request->getArr('user_phid')[0] ?? null;
                    $description  = $request->getStr('description');
                    $spendTime = $request->getStr('spend_time');
                    $dateLogged = AphrontFormDateControlValue::newFromRequest($request, 'date_logged')->getEpoch();
                    $dateCreated = AphrontFormDateControlValue::newFromRequest($request, 'date_created')->getEpoch();

                    $errors = [];

                    if (!$viewer->getIsAdmin()) {
                        $userPHID = $transaction->getAuthorPHID();
                        $dateCreated = $transaction->getDateCreated();
                    }

                    if (!$this->isTimeFormatCorrect($spendTime)) {
                        $this->formErrors[] = pht(
                            'Spend time is incorrect. Allowed format is: 1w 4d 2h 30m. ' .
                            'You can specify any those modificators at any order.'
                        );
                    }

                    if (!$userPHID) {
                        $this->formErrors[] = pht('Invalid user.');
                    }

                    if (!$this->formErrors) {
                        $this->updateTimelogTransaction($transaction, $userPHID, $description, $spendTime, $dateCreated, $dateLogged);

                        return id(new AphrontRedirectResponse())->setURI('/'.$transaction->getObject()->getMonogram());
                    }
                }

                return $this->buildEditTransactionDialog($transaction);

            default:
                return new Aphront404Response();
        }
    }

    /**
     * @param string $time
     *
     * @return bool
     */
    protected function isTimeFormatCorrect(string $time): bool
    {
        $parts = explode(' ', $time);
        foreach ($parts as $part) {
            $modifier = substr($part, -1, 1);
            $value = substr($part, 0, strlen($part) -1);

            if (!is_numeric($value)) {
                return false;
            }

            switch ($modifier) {
                case 'w':
                case 'd':
                    break;

                case 'h':
                    if ($value > 23) {
                        return false;
                    }
                    break;

                case 'm':
                    if ($value > 60) {
                        return false;
                    }
                    break;

                default:
                    return false;
            }
        }

        return true;
    }

    /**
     * @param string $phid
     *
     * @return ManiphestTransaction|null
     */
    private function getTimelogTransacrionByPHID(string $phid)
    {
        $transactions = id(new ManiphestTransactionQuery())
            ->setViewer($this->getRequest()->getUser())
            ->withPHIDs([$phid])
            ->execute();

        if (!$transactions) {
            return null;
        }

        return array_pop($transactions);
    }

    /**
     * @param ManiphestTransaction $transaction
     *
     * @param string $userPHID
     * @param string $description
     * @param string $spendTime
     * @param int $dateCreated
     * @param int $dateLogged
     */
    private function updateTimelogTransaction(
        ManiphestTransaction $transaction,
        string $userPHID,
        string $description,
        string $spendTime,
        int $dateCreated,
        int $dateLogged
    ) {
        $xtable = new ManiphestTransaction();
        $query = 'UPDATE %T set authorPHID = %s, newValue = %s, dateCreated = %d WHERE phid = %s';

        $newValue = $transaction->getNewValue();

        $newValue['description'] = $description;
        $newValue['spend'] = $spendTime;
        $newValue['started'] = $dateLogged;

        $bindParams = [
            $xtable->getTableName(),
            $userPHID,
            json_encode($newValue),
            $dateCreated,
            $transaction->getPHID(),
        ];

        queryfx($xtable->establishConnection('r'), $query, ...$bindParams);
    }

    /**
     * @param ManiphestTransaction $transaction
     *
     * @return AphrontDialogView
     */
    private function buildEditTransactionDialog(ManiphestTransaction $transaction)
    {
        $viewer = $this->getRequest()->getUser();

        $dialog = $this->newDialog()
            ->setTitle('Log work edit')
            ->setWidth(AphrontDialogView::WIDTH_FORM)
            ->setErrors([])
            ->appendParagraph('How much time you spent for work?');

        $form = id(new AphrontFormView())->setUser($viewer);

        if ($viewer->getIsAdmin()) {
            $form->appendControl(
                id(new AphrontFormTokenizerControl())
                    ->setDatasource(new PhabricatorPeopleDatasource())
                    ->setLabel(pht('User'))
                    ->setName('user_phid')
                    ->setValue([$transaction->getAuthorPHID()])
            );
        }

        $form->appendControl(
            id(new AphrontFormTextControl())
                ->setUser($viewer)
                ->setName('spend_time')
                ->setLabel('Log time')
                ->setValue($transaction->getNewValue()['spend'])
        );

        $form->appendControl(
            id(new AphrontFormDateControl())
                ->setUser($viewer)
                ->setIsTimeDisabled(true)
                ->setName('date_logged')
                ->setLabel('Log date')
                ->setValue($transaction->getNewValue()['started'])
        );

        if ($viewer->getIsAdmin()) {
            $form->appendControl(
                id(new AphrontFormDateControl())
                    ->setUser($viewer)
                    ->setIsTimeDisabled(true)
                    ->setName('date_created')
                    ->setLabel('Created date')
                    ->setValue($transaction->getDateCreated())
            );
        }

        $form->appendControl(
            id(new AphrontFormTextAreaControl())
                ->setUser($viewer)
                ->setName('description')
                ->setLabel('Description')
                ->setValue($transaction->getNewValue()['description'])
        );

        $filterBtn = id(new AphrontNormalFormSubmitControl())
            ->setValue(pht('Save'))
            ->addCancelButton('/'.$transaction->getObject()->getMonogram(), 'Cancel');

        $form->appendChild($filterBtn);

        $dialog->appendChild($form);

        if ($this->formErrors) {
            $dialog->setErrors($this->formErrors);
        }

        return $dialog;
    }

    /**
     * @param string $spendTime
     * @param string $description
     * @param int $timestamp
     *
     * @return AphrontDialogView
     */
    private function buildAddTimeLogDialog(string $spendTime = '', string $description = '', ?int $timestamp = null)
    {
        $viewer = $this->getRequest()->getUser();

        $dialog = $this->newDialog()
            ->setTitle('Log work')
            ->setWidth(AphrontDialogView::WIDTH_FORM)
            ->setErrors($this->formErrors)
            ->appendParagraph('How much time you spent for work?');

        $form = new PHUIFormLayoutView();
        $form->appendChild(
            id(new AphrontFormTextControl())
                ->setUser($viewer)
                ->setName('time')
                ->setLabel('Time spent')
                ->setValue($spendTime)
        );
        $form->appendChild(
            id(new AphrontFormTextAreaControl())
                ->setUser($viewer)
                ->setName('description')
                ->setLabel('Work description')
                ->setValue($description)
        );
        $form->appendChild(
            id(new AphrontFormDateControl())
                ->setUser($viewer)
                ->setName('started')
                ->setLabel('Date started')
                ->setValue($timestamp ?? time())
        );

        $dialog->appendChild($form);
        $dialog->addCancelButton('/', pht('Close'));
        $dialog->addSubmitButton(pht('Done'));

        return $dialog;
    }
}