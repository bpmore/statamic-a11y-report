<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Http\Controllers;

use Bpmore\A11yReport\Document\Wcag;
use Bpmore\A11yReport\Queue\IssueQuery;
use Bpmore\A11yReport\Settings;
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
            // What the policy says about the queue: past target, and
            // acceptances near or past the date they run out on.
            'policyCounts' => $installed ? $query->policyCounts() : ['overdue' => 0, 'expiring' => 0, 'expired' => 0],
            'policy' => $query->policy,
            'dueOptions' => IssueQuery::DUE,
            'expiringWithin' => IssueQuery::EXPIRING_WITHIN_DAYS,
            // The furthest ahead an acceptance may be set to run to, so the
            // date control cannot offer one the controller would refuse.
            'latestExpiry' => $query->policy->latestExpiry()->format('Y-m-d'),
            'issues' => $installed ? $query->paginate((int) $request->query('page', 1)) : null,
            'queryString' => $query->queryString(),
            'canManage' => (bool) User::current()?->can('manage accessibility issues'),
            'updateUrl' => cp_route('utilities.a11y-report.issues.update'),
            'indexUrl' => cp_route('utilities.a11y-report.issues'),
            'criteriaUrl' => cp_route('utilities.a11y-report.criteria'),
            'overviewUrl' => cp_route('utilities.index').'/a11y-report',
            'settingsUrl' => \Statamic\Facades\Addon::get(\Bpmore\A11yReport\Settings::PACKAGE)?->settingsUrl(),
            'statuses' => IssueQuery::STATUSES,
            // The WCAG version the report is set to, so a link on a cited
            // criterion opens that version's text rather than the newest.
            'standard' => self::standard(),
        ];

        return Inertia::render('utilities/Show', [
            'title' => 'Accessibility Report: Issues',
            'html' => view('a11y-report::utilities.issues', $data)->render(),
        ]);
    }

    private static function standard(): string
    {
        $configured = app(Settings::class)->block('report')['standard'] ?? null;

        return is_string($configured) && isset(Wcag::STANDARDS[$configured]) ? $configured : 'wcag22aa';
    }
}
