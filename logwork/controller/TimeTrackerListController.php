<?php

class TimeTrackerListController extends PhabricatorController
{
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

        if ($request->isFormPost()) {
            $this->filterProject = $request->getArr('set_project');
            $this->filterUser = $request->getArr('set_user');
            $this->filterDateFrom = AphrontFormDateControlValue::newFromRequest($request, 'set_from')->getEpoch();
            $this->filterDateTo = AphrontFormDateControlValue::newFromRequest($request, 'set_to')->getEpoch();
        }

        $nav = new AphrontSideNavFilterView();
        $nav->setBaseURI(new PhutilURI('/timetracker/'));
        $nav->addLabel(pht('Reports'));
        $nav->addFilter('user', pht('By persons'));
        $nav->addFilter('tasks', pht('By tasks'));

        $this->view = $nav->selectFilter($this->view, 'user');

        $crumbs = $this->buildApplicationCrumbs();
        $title = pht('Maniphest Reports');

        switch ($this->view) {
            case 'user':
                $core = $this->renderLogByUsers();
                $crumbs->addTextCrumb(pht('By persons'));
                break;

            case 'tasks':
                $core = $this->renderLogByTasks();
                $crumbs->addTextCrumb(pht('By tasks'));
                break;

            default:
                return new Aphront404Response();
        }

        $nav->appendChild($core);

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

        $form->appendChild(id(new AphrontFormSubmitControl())->setValue(pht('Filter')));

        $filter = new AphrontListFilterView();
        $filter->appendChild($form);

        return $filter;
    }

    /**
     * @return array
     */
    protected function renderLogByTasks(): array
    {
        $request = $this->getRequest();
        $viewer = $request->getUser();
        $taskSummaryTimeByDates = [];
        $dates = [];

        if (!$xactions = $this->getTimeLogTransactions()) {
            return [$this->renderReportFilters(), hsprintf('<div class="phui-box phui-box-border phui-object-box mlt mll mlr">No data</div>')];
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

        $row = ['Total'];
        $total = 0;

        foreach ($dates as $key => $value) {
            if (isset($summaryTaskTimeByDay[$key])) {
                $total += $summaryTaskTimeByDay[$key];
                $row[] = TimeLogHelper::minutesToTimeLog($summaryTaskTimeByDay[$key]);
                continue;
            }

            $row[] = '-';
        }
        $row[] = TimeLogHelper::minutesToTimeLog($total);
        $rows[] = $row;

        $panel = (new PHUIObjectBoxView())
            ->setHeaderText('Grouping by tasks')
            ->setTable(new AphrontTableView($rows));

        return [$this->renderReportFilters(), $panel];
    }

    /**
     * @return array
     */
    protected function renderLogByUsers(): array
    {
        $request = $this->getRequest();
        $viewer = $request->getUser();
        $actions = [];
        $dates = [];

        if (!$xactions = $this->getTimeLogTransactions()) {
            return [$this->renderReportFilters(), hsprintf('<div class="phui-box phui-box-border phui-object-box mlt mll mlr">No data</div>')];
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
                    $row[] = TimeLogHelper::minutesToTimeLog($spendTimeByDates[$key]);
                    $total += $spendTimeByDates[$key];
                } else {
                    $row[] = '-';
                }
            }

            $row[] = TimeLogHelper::minutesToTimeLog($total);

            $rows[] = $row;
        }

        $table = new AphrontTableView($rows);

        $panel = new PHUIObjectBoxView();
        $panel->setHeaderText('Tasks by users');
        $panel->setTable($table);

        return [$this->renderReportFilters(), $panel];
    }

    /**
     * @return ManiphestTransaction[]|null
     */
    protected function getTimeLogTransactions(): ?array
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
}
