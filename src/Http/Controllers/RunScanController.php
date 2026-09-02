<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Http\Controllers;

use Bpmore\A11yReport\Jobs\StartScan;
use Bpmore\A11yReport\Models\Scan;
use Bpmore\A11yReport\Scan\Scans;
use Bpmore\A11yReport\Scan\ScanScope;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Illuminate\Http\Request;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

/**
 * The "run a scan" button.
 *
 * Creates the scan row and hands the rest to the queue, so the request
 * returns in milliseconds whatever the site's size. On a site whose queue is
 * `sync` the whole scan runs inside this request instead, which is the queue
 * setting's decision rather than this addon's, and the page says so.
 *
 * Its own permission, separate from seeing the report. Not everyone who may
 * read the numbers should be able to make the site render every page.
 */
class RunScanController extends CpController
{
    public function __invoke(Request $request, Scans $scans, ReportDatabase $database, \Bpmore\A11yReport\Settings $settings)
    {
        abort_unless(User::current()?->can('run accessibility scans'), 403);

        if (! $database->isInstalled()) {
            if (! $database->ownsConnection()) {
                return back()->with('error', 'The report tables are not created yet. Run: php please a11y:report:install');
            }

            $database->install();
        }

        $site = $request->input('site');
        $sites = $site && Site::get($site) ? [$site] : [];

        $scope = ScanScope::fromConfig($settings->block('scan'), $sites);
        $scan = $scans->create($scope, Scan::TRIGGER_MANUAL, User::current()?->email());

        StartScan::dispatch($scan->id);

        return back()->with('success', 'Scan queued. It runs on the queue; this page shows it as it goes.');
    }
}
