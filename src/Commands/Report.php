<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Commands;

use Bpmore\A11yReport\Document\ReportWriter;
use Bpmore\A11yReport\Models\Scan as ScanModel;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;

/**
 * Generate the conformance document from a completed scan.
 */
class Report extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:a11y:report
        {--scan= : The id of the scan to report on. Default is the latest complete scan.}
        {--site= : With no --scan, the latest complete scan of this site.}
        {--format=all : html, json, or all.}
        {--out= : A directory to copy the files into as well.}';

    protected $description = 'Generate an accessibility conformance report from a completed scan.';

    public function handle(ReportWriter $writer, ReportDatabase $database): int
    {
        if (! $database->isInstalled()) {
            $this->error('No scan has run yet. Run: php please a11y:scan');

            return 1;
        }

        $scan = $this->scan();

        if ($scan === null) {
            $this->error('There is no complete scan to report on.');

            return 1;
        }

        $format = (string) $this->option('format');
        $formats = $format === 'all' ? ReportWriter::FORMATS : [$format];

        try {
            $report = $writer->write($scan, 'console', $formats);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $this->line('');
        $this->line("  Report <options=bold>{$report->uuid}</> generated from scan {$scan->uuid}.");
        $this->line("  {$report->coverage_note}");

        foreach (['html_path', 'json_path'] as $column) {
            if ($report->{$column}) {
                $path = $writer->absolutePath($report->{$column});
                $this->line("  {$path}");

                if ($out = $this->option('out')) {
                    if (! is_dir($out)) {
                        mkdir($out, 0755, true);
                    }

                    copy($path, rtrim($out, '/').'/'.basename($path));
                }
            }
        }

        if ($out = $this->option('out')) {
            $this->line("  Copied to {$out}");
        }

        $this->line('');
        $this->line('<fg=gray>A self-assessment, not a certification. The document says so and cannot be told not to.</>');
        $this->line('');

        return 0;
    }

    private function scan(): ?ScanModel
    {
        if ($id = $this->option('scan')) {
            return ScanModel::where('uuid', $id)->first();
        }

        $query = ScanModel::where('status', ScanModel::COMPLETE)->orderByDesc('id');

        if ($site = $this->option('site')) {
            $query->where('site', $site);
        }

        return $query->first();
    }
}
