<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Document;

use Bpmore\A11yReport\Models\Report;
use Bpmore\A11yReport\Models\Scan;
use Bpmore\A11yReport\Pdf\ChromePrinter;
use Bpmore\A11yReport\Pdf\PdfMetadata;
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
    public const FORMATS = ['html', 'json', 'pdf'];

    /** What a report is by default: the document and its data. The PDF is asked for. */
    public const DEFAULT_FORMATS = ['html', 'json'];

    public function __construct(
        private readonly Application $app,
        private readonly ReportBuilder $builder,
        private readonly ChromePrinter $printer,
    ) {}

    /**
     * @param  array<int, string>  $formats
     */
    public function write(Scan $scan, ?string $generatedBy, array $formats = self::DEFAULT_FORMATS): Report
    {
        foreach ($formats as $format) {
            if (! in_array($format, self::FORMATS, true)) {
                throw new \InvalidArgumentException("There is no [{$format}] format. Available: ".implode(', ', self::FORMATS).'.');
            }
        }

        $data = $this->builder->build($scan, $generatedBy);
        $paths = [];

        // The PDF is printed from the HTML file, so asking for the PDF is
        // asking for the HTML too: the document is the source and stays
        // beside its rendering.
        if (in_array('html', $formats, true) || in_array('pdf', $formats, true)) {
            $paths['html_path'] = $this->store($data['uuid'].'.html', self::html($data));
        }

        if (in_array('pdf', $formats, true)) {
            $pdf = $this->app->storagePath('a11y-report/reports/'.$data['uuid'].'.pdf');
            $this->printer->print($this->app->storagePath($paths['html_path']), $pdf);
            // The PDF/UA identifier is written because the output has been
            // validated against PDF/UA-1 with veraPDF, which the suite
            // repeats wherever veraPDF is installed, CI included. A change to
            // the template that breaks a rule turns that test red before it
            // ships a file that declares what it no longer has.
            PdfMetadata::stamp($pdf, self::pdfTitle($data), $data['lang'], $data['generator'], declarePdfUa: true, linkDescriptions: Wcag::linkDescriptions($data['standard']));
            $paths['pdf_path'] = 'a11y-report/reports/'.$data['uuid'].'.pdf';
        }

        if (in_array('json', $formats, true)) {
            $paths['json_path'] = $this->store($data['uuid'].'.json', (string) json_encode(self::forJson($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
            // The promise in force when this document was filed. Kept on the
            // row as well as in the JSON so "what were the targets in March"
            // is answerable without opening a file.
            'remediation_policy' => $data['remediation']['policy'],
        ], $paths));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function html(array $data): string
    {
        return View::make('a11y-report::report.document', ['r' => $data])->render();
    }

    public function pdfAvailable(): bool
    {
        return $this->printer->available();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function pdfTitle(array $data): string
    {
        return $data['title'].': '.$data['subject']['name'].', '.\Illuminate\Support\Carbon::parse($data['generated_at'])->format('j F Y');
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
     * The report data as it is kept: everything the document was built from,
     * without the logo's bytes.
     *
     * The JSON is what a later scan is compared against and what a person
     * reads to see what changed between two reports. An embedded image would
     * be most of the file and would tell that reader nothing. What identifies
     * the mark stays behind: where it came from, its type, its size, and its
     * digest, so "the logo has since been replaced" is still answerable.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function forJson(array $data): array
    {
        if (isset($data['brand']['logo']['data_uri'])) {
            unset($data['brand']['logo']['data_uri']);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function coverageNote(array $data): string
    {
        $total = count($data['criteria']);
        $automated = count($data['methods']['automated_criteria']);
        $unevaluated = count($data['methods']['not_evaluated']);
        $people = count($data['methods']['assessed_by_people']);

        // Three counts of the same table, and never a sum. Semicolons made
        // them read as parts of one whole and they are not: a criterion the
        // checks can speak to is still "not evaluated" where they found
        // nothing, so it is counted twice on purpose, and 23 and 54 and 1
        // added to more than the 55 rows they describe.
        return sprintf(
            'Automated checks speak to %d of %d criteria. %d %s marked not evaluated, and %d %s a person\'s assessment.',
            $automated,
            $total,
            $unevaluated,
            $unevaluated === 1 ? 'is' : 'are',
            $people,
            $people === 1 ? 'carries' : 'carry',
        );
    }
}
