<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Http\Controllers;

use Bpmore\A11yReport\Queue\IssueQuery;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

/**
 * The remediation queue, under the report utility. Rendered the way
 * Statamic renders a utility view, so it sits in the same chrome.
 */
class IssuesController extends CpController
{
    public function __invoke(Request $request, ReportDatabase $database)
    {
        $installed = $database->isInstalled();
        $query = new IssueQuery($request->query());

        $data = [
            'installed' => $installed,
            'filters' => $query->filters,
            'options' => $installed ? IssueQuery::options() : ['sites' => [], 'assignees' => [], 'collections' => [], 'criteria' => []],
            'counts' => $installed ? $query->countsByStatus() : [],
            'issues' => $installed ? $query->paginate((int) $request->query('page', 1)) : null,
            'queryString' => $query->queryString(),
            'canManage' => (bool) User::current()?->can('manage accessibility issues'),
            'updateUrl' => cp_route('utilities.a11y-report.issues.update'),
            'indexUrl' => cp_route('utilities.a11y-report.issues'),
            'criteriaUrl' => cp_route('utilities.a11y-report.criteria'),
            'overviewUrl' => cp_route('utilities.index').'/a11y-report',
            'statuses' => IssueQuery::STATUSES,
        ];

        return Inertia::render('utilities/Show', [
            'title' => 'Accessibility Report: Issues',
            'html' => view('a11y-report::utilities.issues', $data)->render(),
        ]);
    }
}
