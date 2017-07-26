<?php

class TimeTrackerReportsController extends PhabricatorController
{
    /**
     * @var bool
     */
    protected $downloadReport;

    /**
     * @var string
     */
    private $view;

    /**
     * @var null|array
     */
    private $filterProject;

    /**
     * @var null|array
     */
    private $filterUser;

    /**
     * @var int
     */
    private $filterDateFrom;

    /**
     * @var int
     */
    private $filterDateTo;

    /**
     * @param AphrontRequest $request
     *
     * @return Aphront404Response
     */
    public function handleRequest(AphrontRequest $request)
    {
        $this->view = $request->getURIData('view');

        $this->filterDateFrom = strtotime('-10 days');

        $this->filterDateTo = time();

        $this->downloadReport = false;

        require_celerity_resource('aphront-tooltip-css');
        Javelin::initBehavior('phabricator-tooltips', array());

        if ($request->isFormPost()) {
            $this->filterProject = $request->getArr('set_project');
            $this->filterUser = $request->getArr('set_user');
            $this->filterDateFrom = AphrontFormDateControlValue::newFromRequest($request, 'set_from')->getEpoch();
            $this->filterDateTo = AphrontFormDateControlValue::newFromRequest($request, 'set_to')->getEpoch();
            $this->downloadReport = (bool) $request->getStr('download', false);
        }

        $nav = new AphrontSideNavFilterView();
        $nav->setBaseURI(new PhutilURI('/phixator/'));
        $nav->addLabel(pht('Reports'));
        $nav->addFilter('user', pht('By users'));
        $nav->addFilter('tasks', pht('By tasks'));
        $nav->addFilter('usertask', pht('By users and tasks'));

        $this->view = $nav->selectFilter($this->view, 'user');

        $crumbs = $this->buildApplicationCrumbs();
        $title = pht('Maniphest Reports');

        switch ($this->view) {
            case 'user':
                $rows = $this->getLogByUsers();
                $crumbs->addTextCrumb(pht('By users'));
                $tableTitle = 'Spend time by users';
                break;

            case 'tasks':
                $rows = $this->getLogByTasks();
                $crumbs->addTextCrumb(pht('By tasks'));
                $tableTitle = 'Spend time by tasks';
                break;

            case 'usertask':
                $rows = $this->getLogByUserAndTasks();
                $crumbs->addTextCrumb(pht('By users and tasks'));
                $tableTitle = 'Spend time by tasks grouped by users';
                break;

            default:
                return new Aphront404Response();
        }

        if ($this->downloadReport) {
            return $this->downloadReport($rows ?? []);
        }

        $panel = new PHUIObjectBoxView();
        $panel->setHeaderText($tableTitle);
        $panel->setTable(new AphrontTableView($rows ?? []));

        $nav->appendChild([$this->renderReportFilters(), $panel]);

        return $this->newPage()
            ->setTitle($title)
            ->setCrumbs($crumbs)
            ->setNavigation($nav);
    }

    /**
     * @return AphrontListFilterView
     */
    protected function renderReportFilters(): AphrontListFilterView
    {
        $request = $this->getRequest();
        $viewer = $request->getUser();

        $form = id(new AphrontFormView())
            ->setUser($viewer)
            ->appendControl(
                id(new AphrontFormTokenizerControl())
                    ->setDatasource(new PhabricatorProjectDatasource())
                    ->setLabel(pht('Project'))
                    ->setName('set_project')
                    ->setValue($this->filterProject)
            )
            ->appendControl(
                id(new AphrontFormTokenizerControl())
                    ->setDatasource(new PhabricatorPeopleDatasource())
                    ->setLabel(pht('User'))
                    ->setName('set_user')
                    ->setValue($this->filterUser)
            )
            ->appendControl(
                id(new AphrontFormDateControl())
                    ->setUser($viewer)
                    ->setIsTimeDisabled(true)
                    ->setName('set_from')
                    ->setLabel('Date from')
                    ->setValue($this->filterDateFrom)
            )
            ->appendControl(
                id(new AphrontFormDateControl())
                    ->setUser($viewer)
                    ->setIsTimeDisabled(true)
                    ->setName('set_to')
                    ->setLabel('Date to')
                    ->setValue($this->filterDateTo)
            );

        $downloadBtn = id(new PHUIButtonView())
            ->setTag('input')
            ->setButtonType('submit')
            ->setValue('Download csv')
            ->setColor('grey')
            ->setCustomClasses(['mml'])
            ->setName('download');

        $filterBtn = id(new AphrontNormalFormSubmitControl())
            ->setValue(pht('Filter'))
            ->addButton($downloadBtn);

        $form->appendChild($filterBtn);

        $filter = new AphrontListFilterView();
        $filter->appendChild($form);

        return $filter;
    }

    /**
     * @return array|null
     */
    protected function getLogByTasks()
    {
        $request = $this->getRequest();
        $viewer = $request->getUser();
        $taskSummaryTimeByDates = [];
        $dates = [];

        if (!$xactions = $this->getTimeLogTransactions()) {
            return null;
        }

        $taskPHIDs = [];
        foreach ($xactions as $xaction) {
            $started = $xaction->getNewValue()['started'];
            $loggedTime = $xaction->getNewValue()['spend'] ?? null;

            if (!$loggedTime) {
                continue;
            }

            $dateKey = date('Ymd', $started);

            $dates[$dateKey] = floor($started / 86400) * 86400;
            $taskPHID = $xaction->getObjectPHID();
            $taskPHIDs[] = $taskPHID;

            if (!isset($taskSummaryTimeByDates[$taskPHID][$dateKey])) {
                $taskSummaryTimeByDates[$taskPHID][$dateKey] = 0;
            }
            $taskSummaryTimeByDates[$taskPHID][$dateKey] += TimeLogHelper::timeLogToMinutes($loggedTime);
        }

        unset($xactions);

        $tasks = (new ManiphestTaskQuery())->setViewer($viewer)->withPHIDs($taskPHIDs)->execute();

        $rows = [];

        $row[] = 'Task';
        foreach ($dates as $key => $timestamp) {
            $row[] = date('Y-m-d', $timestamp);
        }
        $row[] = 'Total';
        $rows[] = $row;

        foreach ($tasks as $task) {
            $row = [phutil_tag('a', ['href' => $task->getURI(), 'target' => '_blank'], $task->getTitle())];

            $totalSpendByTask = 0;
            $taskPHID = $task->getPHID();

            if (!isset($summaryTaskTimeByDay[$key])) {
                $summaryTaskTimeByDay[$key] = 0;
            }

            foreach ($dates as $key => $value) {
                if (isset($taskSummaryTimeByDates[$taskPHID][$key])) {
                    $row[] = TimeLogHelper::minutesToTimeLog($taskSummaryTimeByDates[$taskPHID][$key]);
                    $totalSpendByTask += $taskSummaryTimeByDates[$taskPHID][$key];
                    $summaryTaskTimeByDay[$key] += $taskSummaryTimeByDates[$taskPHID][$key];

                    continue;
                }

                $row[] = '-';
            }

            $row[] = TimeLogHelper::minutesToTimeLog($totalSpendByTask);

            $rows[] = $row;
        }

        $row = [hsprintf('<b>Total</b>')];
        $total = 0;

        foreach ($dates as $key => $value) {
            if (isset($summaryTaskTimeByDay[$key])) {
                $total += $summaryTaskTimeByDay[$key];
                $row[] = hsprintf('<b>'.TimeLogHelper::minutesToTimeLog($summaryTaskTimeByDay[$key]).'</b>');
                continue;
            }

            $row[] = '-';
        }
        $row[] = hsprintf('<b>'.TimeLogHelper::minutesToTimeLog($total).'</b>');
        $rows[] = $row;

        return $rows;
    }

    /**
     * @return array|null
     */
    protected function getLogByUsers()
    {
        $request = $this->getRequest();
        $viewer = $request->getUser();
        $actions = [];
        $dates = [];
        $tasks = [];

        if (!$xactions = $this->getTimeLogTransactions()) {
            return null;
        }

        foreach ($xactions as $xaction) {
            $authorPHID = $xaction->getAuthorPHID();
            $loggedTime = $xaction->getNewValue()['spend'];
            $started = $xaction->getNewValue()['started'];
            $dateKey = date('Ymd', $started);

            if (!isset($actions[$authorPHID][$dateKey])) {
                $actions[$authorPHID][$dateKey] = 0;
            }

            $actions[$authorPHID][$dateKey] += TimeLogHelper::timeLogToMinutes($loggedTime);
            $tasks[$authorPHID][$dateKey][$xaction->getObject()->getID()] = sprintf(
                '%s: %s',
                $xaction->getObject()->getMonogram(),
                $xaction->getObject()->getTitle()
            );
            $dates[$dateKey] = floor($started / 86400) * 86400;
        }

        unset($xactions);

        $rows = [];
        $row = [];

        $authorPHIDToUser = DataRetriverHelper::getAuthorsByPHIDs(array_keys($actions));

        $row[] = 'User';
        foreach ($dates as $key => $timestamp) {
            $row[] = date('Y-m-d', $timestamp);
        }
        $row[] = 'Total';
        $rows[] = $row;

        foreach ($actions as $authorPHID => $spendTimeByDates) {
            $row = [];

            $row[] = phutil_tag(
                'a',
                [
                    'href' => '/p/'.$authorPHIDToUser[$authorPHID]->getUsername().'/',
                    'target' => '_blank',
                ],
                $authorPHIDToUser[$authorPHID]->getUsername()
            );

            $total = 0;
            foreach ($dates as $key => $value) {
                if (isset($spendTimeByDates[$key])) {
                    $row[] = javelin_tag(
                        'a',
                        [
                            'href' => '/maniphest/?ids='.implode(',', array_keys($tasks[$authorPHID][$key])),
                            'target' => '_blank',
                            'sigil' => 'has-tooltip',
                            'meta'  => [
                                'tip' => array_reduce($tasks[$authorPHID][$key], function($summary, $item) {
                                    $summary .= $item."\r\n";
                                    return $summary;
                                }),
                            ],
                        ],
                        TimeLogHelper::minutesToTimeLog($spendTimeByDates[$key])
                    );

                    $total += $spendTimeByDates[$key];
                } else {
                    $row[] = '-';
                }
            }

            //$row[] = TimeLogHelper::minutesToTimeLog($total);
            $summaryTasksByUser = [];
            foreach ($tasks[$authorPHID] as $date => $taskList) {
                foreach ($taskList as $taskId => $taskTitle) {
                    $summaryTasksByUser[$taskId] = $taskTitle;
                }
            }
            $row[] = javelin_tag(
                'a',
                [
                    'href' => '/maniphest/?ids='.implode(',', array_keys($summaryTasksByUser)),
                    'target' => '_blank',
                    'sigil' => 'has-tooltip',
                    'meta'  => [
                        'tip' => implode("\r\n", $summaryTasksByUser),
                    ],
                ],
                TimeLogHelper::minutesToTimeLog($total)
            );

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return array|null
     */
    protected function getLogByUserAndTasks()
    {
        $request = $this->getRequest();
        $viewer = $request->getUser();
        $dates = [];
        $usersTasks = [];
        $taskPHIDs = [];
        $tableRows = [];

        if (!$xactions = $this->getTimeLogTransactions()) {
            return null;
        }

        foreach ($xactions as $xaction) {
            $authorPHID = $xaction->getAuthorPHID();
            $started = $xaction->getNewValue()['started'];
            $dateKey = date('Ymd', floor($started / 86400) * 86400);
            $actionPHID = $xaction->getObjectPHID();

            if (!isset($usersTasks[$authorPHID])) {
                $usersTasks[$authorPHID] = [];
            }

            if (!isset($usersTasks[$authorPHID][$actionPHID])) {
                $usersTasks[$authorPHID][$actionPHID] = [];
            }

            $usersTasks[$authorPHID][$actionPHID][$dateKey] += TimeLogHelper::timeLogToMinutes(
                $xaction->getNewValue()['spend']
            );
            $taskPHIDs[$actionPHID] = 1;
            $dates[$dateKey] = $started;
        }

        $tasks = (new ManiphestTaskQuery())->setViewer($viewer)->withPHIDs(array_keys($taskPHIDs))->execute();
        $taskInfo = [];
        foreach ($tasks as $task) {
            $taskInfo[$task->getPHID()] = [
                'url' => $task->getURI(),
                'title' => $task->getTitle(),
                'monogram' => $task->getMonogram(),
                'closed' => $task->isClosed(),
            ];
        }

        unset($xactions, $tasks, $taskPHIDs);

        $authorPHIDToUser = DataRetriverHelper::getAuthorsByPHIDs(array_keys($usersTasks));

        $row = ['Task'];
        foreach ($dates as $dateKey => $timestamp) {
            $row[] = date('Y-m-d', $timestamp);
        }
        $row[] = 'Total';
        $tableRows[] = $row;

        $totalSummary = 0;
        $totalByDay = [];

        foreach ($usersTasks as $authorPHID => $tasks) {
            $tableRows[] = array_merge(
                [phutil_tag(
                    'a',
                    [
                        'href' => '/p/'.$authorPHIDToUser[$authorPHID]->getUsername().'/',
                        'target' => '_blank',
                    ],
                    $authorPHIDToUser[$authorPHID]->getUsername()
                )],
                array_fill(0, count($dates) + 1, '')
            );

            $subtotal = [];
            $subtotalSummary = 0;
            foreach ($tasks as $taskPHID => $spendByDates) {
                $row = [hsprintf('&nbsp;&nbsp;&nbsp;&nbsp;'.phutil_tag(
                    'a',
                    [
                        'href' => $taskInfo[$taskPHID]['url'],
                        'target' => '_blank',
                        'class' => $taskInfo[$taskPHID]['closed'] ? 'phui-tag-core-closed' : '',
                    ],
                    hsprintf('%s: %s', $taskInfo[$taskPHID]['monogram'], $taskInfo[$taskPHID]['title'])
                ))];

                $totalByTask = 0;
                foreach ($dates as $dateKey => $timestamp) {
                    if (isset($spendByDates[$dateKey])) {
                        $row[] = TimeLogHelper::minutesToTimeLog($spendByDates[$dateKey]);

                        $totalByTask += $spendByDates[$dateKey];

                        if (!isset($subtotal[$dateKey])) {
                            $subtotal[$dateKey] = 0;
                        }
                        $subtotal[$dateKey] += $spendByDates[$dateKey];
                        $subtotalSummary += $spendByDates[$dateKey];

                        $totalByDay[$dateKey] += $spendByDates[$dateKey];
                        $totalSummary += $spendByDates[$dateKey];

                        continue;
                    }

                    $row[] = '-';
                }

                $row[] = $totalByTask ? TimeLogHelper::minutesToTimeLog($totalByTask) : '-';

                $tableRows[] = $row;
            }

            $row = [hsprintf('&nbsp;&nbsp;&nbsp;&nbsp;<b>Subtotal</b>')];
            foreach ($dates as $dateKey => $timestamp) {
                $row[] = isset($subtotal[$dateKey]) ? TimeLogHelper::minutesToTimeLog($subtotal[$dateKey]) : '-';
            }
            $row[] = $subtotalSummary ? TimeLogHelper::minutesToTimeLog($subtotalSummary) : '-';

            $tableRows[] = $row;
        }

        $row = [hsprintf('<b>Total</b>')];
        foreach ($dates as $dateKey => $timestamp) {
            $row[] = isset($totalByDay[$dateKey]) ? TimeLogHelper::minutesToTimeLog($totalByDay[$dateKey]) : '-';
        }
        $row[] = $totalSummary ? TimeLogHelper::minutesToTimeLog($totalSummary) : '-';
        $tableRows[] = $row;

        return $tableRows;
    }

    /**
     * @return ManiphestTransaction[]|null
     */
    protected function getTimeLogTransactions()
    {
        $xtable = new ManiphestTransaction();
        $where = [];
        $bindParams = [
            $xtable->getTableName(),
            LogTimeItemTransaction::TRANSACTIONTYPE,
        ];

        if ($this->filterProject) {
            $taskPHIDs = queryfx_all(
                PhabricatorEdgeConfig::establishConnection('PROJ', 'r'),
                'SELECT dst FROM %T edge where src in (%Ls)',
                PhabricatorEdgeConfig::TABLE_NAME_EDGE,
                $this->filterProject
            );

            $where[] = 'x.objectPHID in (%Ls)';
            $bindParams[] = array_column($taskPHIDs, 'dst');
        }

        if ($this->filterUser) {
            $where[] = 'x.authorPHID in (%Ls)';
            $bindParams[] = $this->filterUser;
        }

        $query = 'SELECT x.phid FROM %T x WHERE x.transactionType = %s '.($where ? ' AND ' . implode(' AND ', $where) : '');

        if (!$rows = queryfx_all($xtable->establishConnection('r'), $query, ...$bindParams)) {
            return null;
        }

        if (!$transactionPHIDs = ipull($rows, 'phid')) {
            return null;
        }

        $transactions = id(new ManiphestTransactionQuery())
            ->setViewer($this->getRequest()->getUser())
            ->withPHIDs($transactionPHIDs)
            ->execute();

        if (!$transactions) {
            return [];
        }

        $dateFrom = floor($this->filterDateFrom / 86400) * 86400;
        $dateTo = (floor($this->filterDateTo / 86400) * 86400) + 86399;

        $out = [];
        foreach ($transactions as $transaction) {
            $started = $transaction->getNewValue()['started'] ?? null;
            if (!$started) {
                continue;
            }

            $started = floor($started / 86400) * 86400;

            if (!($started >= $dateFrom && $started <= $dateTo)) {
                continue;
            }

            $out[] = $transaction;
        }

        uasort($out, function($a, $b) {
            return $a->getNewValue()['started'] <=> $b->getNewValue()['started'];
        });

        return $out;
    }

    /**
     * @param array $rows
     *
     * @return AphrontFileResponse
     */
    protected function downloadReport(array $rows): AphrontFileResponse
    {
        $reportContent = 'No data available.';

        if ($rows) {
            $reportContent = array_reduce($rows, function($result, $row) {
                $result .= implode(';', str_replace('&nbsp;', '', array_map('strip_tags', $row)))."\r\n";
                return $result;
            });
        }

        $response = id(new AphrontFileResponse())
            ->setMimeType('text/plain')
            ->setContent($reportContent)
            ->setDownload('Report_'.date('Y-m-d').'.csv');

        return $response;
    }
}
