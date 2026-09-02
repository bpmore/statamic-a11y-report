<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Http\Controllers;

use Bpmore\A11yReport\Document\ReportWriter;
use Bpmore\A11yReport\Models\Report;
use Statamic\Http\Controllers\CP\CpController;

/**
 * A generated report's file, served from storage to somebody who may see
 * the report utility. Reports never live under public/.
 */
class DownloadReportController extends CpController
{
    public function __invoke(string $uuid, string $format, ReportWriter $writer)
    {
        $report = Report::where('uuid', $uuid)->firstOrFail();

        $path = $writer->absolutePath(match ($format) {
            'html' => $report->html_path,
            'json' => $report->json_path,
            'pdf' => $report->pdf_path,
            default => null,
        });

        abort_if($path === null || ! is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => match ($format) {
                'json' => 'application/json',
                'pdf' => 'application/pdf',
                default => 'text/html; charset=utf-8',
            },
            'Content-Disposition' => 'inline; filename="accessibility-conformance-report-'.$uuid.'.'.$format.'"',
        ]);
    }
}
