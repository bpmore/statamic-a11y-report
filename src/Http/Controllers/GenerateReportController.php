<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Http\Controllers;

use Bpmore\A11yReport\Document\ReportWriter;
use Bpmore\A11yReport\Document\ScanEvidence;
use Bpmore\A11yReport\Models\Scan;
use Illuminate\Http\Request;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

/**
 * The "generate a report" button: the latest complete scan for the selected
 * site, or for every site, written as HTML and JSON and attributed to the
 * person who pressed it.
 */
class GenerateReportController extends CpController
{
    public function __invoke(Request $request, ReportWriter $writer)
    {
        abort_unless(User::current()?->can('generate accessibility reports'), 403);

        $site = $request->input('site');
        $site = is_string($site) && Site::get($site) ? $site : null;

        // Through the same lookup the statement and the worksheet use, and
        // not a `where('site', $site)` of its own. With no site chosen that
        // read as `site is null`, which matches only a scan that covered
        // every site: on an install whose scope names one site, every scan
        // carries that name, none of them matched, and the button reported on
        // whichever all-sites scan happened to be the oldest. A conformance
        // document from a scan hours out of date, with nothing on the screen
        // to say so.
        $scan = ScanEvidence::latestScan($site);

        if ($scan === null) {
            return back()->with('error', 'There is no complete scan to report on'.($site ? " for the {$site} site" : '').'. Run a scan first.');
        }

        $formats = $writer->pdfAvailable() ? ReportWriter::FORMATS : ReportWriter::DEFAULT_FORMATS;

        try {
            $report = $writer->write($scan, User::current()?->email(), $formats);
        } catch (\Bpmore\A11yReport\Pdf\ChromeUnavailable $e) {
            // Chrome was found and then failed. The document still matters
            // more than its rendering, so it is written without the PDF and
            // the person is told.
            $report = $writer->write($scan, User::current()?->email(), ReportWriter::DEFAULT_FORMATS);

            return back()->with('success', "Report {$report->uuid} generated without a PDF: {$e->getMessage()}");
        }

        $note = $report->pdf_path ? '' : ' No PDF: no Chrome or Chromium was found on the server.';

        return back()->with('success', "Report {$report->uuid} generated. {$report->coverage_note}{$note}");
    }
}
