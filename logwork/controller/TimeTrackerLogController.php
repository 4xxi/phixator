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
    public function processRequest()
    {
        $request = $this->getRequest();
        $viewer = $request->getUser();

        $handle = id(new PhabricatorHandleQuery())
            ->setViewer($viewer)
            ->withPHIDs([$this->phid])
            ->executeOne();
        $done_uri = $handle->getURI();

        switch ($this->verb) {
            case 'log':
                $errors = [];
                $e_date = '';

                $timestamp = AphrontFormDateControlValue::newFromRequest($request, 'started');
                if (!$timestamp->isValid()) {
                    $errors[] = pht('Please choose a valid date.');
                    $e_date = pht('Invalid');
                }

                $time = trim($request->getStr('time')) ?? false;
                $description = $request->getStr('description') ?? '';

                if ($request->isDialogFormPost()) {

                    if (!$time) {
                        $errors[] = pht('Please pick spend time.');
                    }

                    if ($time && !$this->isTimeFormatCorrect($time)) {
                        $errors[] = pht(
                            'Spend time is incorrect. Allowed format is: 1w 4d 2h 30m. ' .
                            'You can specify any those modificators at any order.'
                        );
                    }

                    if (!$errors) {
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

                        return id(new AphrontRedirectResponse())->setURI($done_uri);
                    }
                }

                $dialog = $this->newDialog()
                    ->setTitle('Log work')
                    ->setWidth(AphrontDialogView::WIDTH_FORM)
                    ->setErrors($errors)
                    ->appendParagraph('How much time you spent for work?');

                $form = new PHUIFormLayoutView();
                $form->appendChild(
                    id(new AphrontFormTextControl())
                        ->setUser($viewer)
                        ->setName('time')
                        ->setLabel('Time spent')
                        ->setValue($time)
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
                        ->setError($e_date)
                        ->setValue($timestamp)
                );

                $dialog->appendChild($form);
                $dialog->addCancelButton('/', pht('Close'));
                $dialog->addSubmitButton(pht('Done'));

                return $dialog;

                break;

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
            $value = (int) substr($part, 0, strlen($part) -1);

            if (!$value) {
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
}