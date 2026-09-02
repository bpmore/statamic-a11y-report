<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Http\Controllers;

use Bpmore\A11yReport\Document\AssessmentMerger;
use Bpmore\A11yReport\Document\Wcag;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Bpmore\A11yReport\Worksheet\Worksheet;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

/**
 * The criteria worksheet, rendered the way Statamic renders a utility view.
 */
class CriteriaController extends CpController
{
    public function __invoke(Request $request, ReportDatabase $database, Worksheet $worksheet)
    {
        $installed = $database->isInstalled();
        $site = $request->query('site');
        $site = is_string($site) && $site !== '' && Site::get($site) ? $site : null;

        $sheet = $installed ? $worksheet->build($site) : ['site' => $site, 'scan' => null, 'standard' => 'wcag22aa', 'rows' => []];

        return Inertia::render('utilities/Show', [
            'title' => 'Accessibility Report: Criteria',
            'html' => view('a11y-report::utilities.criteria', [
                'installed' => $installed,
                'site' => $site,
                'sites' => Site::all()->map(fn ($s) => ['handle' => $s->handle(), 'name' => $s->name()])->values()->all(),
                'sheet' => $sheet,
                'standardLabel' => Wcag::label($sheet['standard']),
                'statuses' => Worksheet::STATUSES,
                'methods' => Worksheet::METHODS,
                'label' => fn (string $s) => AssessmentMerger::label($s),
                'canAssess' => (bool) User::current()?->can('assess accessibility criteria'),
                'saveUrl' => cp_route('utilities.a11y-report.criteria.save'),
                'criteriaUrl' => cp_route('utilities.a11y-report.criteria'),
                'indexUrl' => cp_route('utilities.a11y-report.issues'),
                'overviewUrl' => cp_route('utilities.index').'/a11y-report',
            'settingsUrl' => \Statamic\Facades\Addon::get(\Bpmore\A11yReport\Settings::PACKAGE)?->settingsUrl(),
            ])->render(),
        ]);
    }
}
