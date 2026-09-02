<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Document;

use Bpmore\A11yReport\Models\Report;
use Bpmore\A11yReport\Models\Scan;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\View;

/**
 * Renders the document and keeps it.
 *
 * Every report is a row and a set of files. The row says who generated it,
 * when, and from which scan, because an undated, unattributed conformance
 * document is worthless as evidence and dangerous as a claim. The files live
 * under storage/, never under public/: a conformance document is not a page
 * of the site.
 */
final class ReportWriter
{
    public const FORMATS = ['html', 'json'];

    public function __construct(
        private readonly Application $app,
        private readonly ReportBuilder $builder,
    ) {}

    /**
     * @param  array<int, string>  $formats
     */
    public function write(Scan $scan, ?string $generatedBy, array $formats = self::FORMATS): Report
    {
        foreach ($formats as $format) {
            if (! in_array($format, self::FORMATS, true)) {
                throw new \InvalidArgumentException("There is no [{$format}] format yet. Available: ".implode(', ', self::FORMATS).'.');
            }
        }

        $data = $this->builder->build($scan, $generatedBy);
        $paths = [];

        if (in_array('html', $formats, true)) {
            $paths['html_path'] = $this->store($data['uuid'].'.html', self::html($data));
        }

        if (in_array('json', $formats, true)) {
            $paths['json_path'] = $this->store($data['uuid'].'.json', (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return Report::create(array_merge([
            'uuid' => $data['uuid'],
            'site' => $scan->site,
            'scan_id' => $scan->id,
            'generated_at' => now(),
            'generated_by' => $generatedBy ?: 'unknown',
            'standard' => $data['standard'],
            'coverage_note' => self::coverageNote($data),
            'evaluator_name' => $data['evaluator']['name'],
            'evaluator_org' => $data['evaluator']['organization'],
            'remediation_plan' => $data['remediation_plan'],
        ], $paths));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function html(array $data): string
    {
        return View::make('a11y-report::report.document', ['r' => $data])->render();
    }

    /** Where a report's file is on disk, from the relative path the row keeps. */
    public function absolutePath(?string $relative): ?string
    {
        return $relative === null ? null : $this->app->storagePath($relative);
    }

    private function store(string $filename, string $contents): string
    {
        $relative = 'a11y-report/reports/'.$filename;
        $absolute = $this->app->storagePath($relative);

        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0755, true);
        }

        file_put_contents($absolute, $contents);

        return $relative;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function coverageNote(array $data): string
    {
        return sprintf(
            '%d of %d criteria had automated checks; %d were not evaluated; %d were assessed by a person.',
            count($data['methods']['automated_criteria']),
            count($data['criteria']),
            count($data['methods']['not_evaluated']),
            count($data['methods']['assessed_by_people']),
        );
    }
}
