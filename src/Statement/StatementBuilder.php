<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Statement;

use Bpmore\A11yReport\Document\ReportWriter;
use Bpmore\A11yReport\Document\Wcag;
use Bpmore\A11yReport\Models\CriterionAssessment;
use Bpmore\A11yReport\Models\Report;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Illuminate\Support\Carbon;
use Statamic\Facades\Site;

/**
 * What the public accessibility statement says, for one site.
 *
 * Everything factual comes from the latest conformance report for the site,
 * read live, so the statement can never describe an evaluation older than
 * the newest report. Everything organisational (commitment, contact,
 * feedback, escalation, enforcement) comes from config, global with a
 * per-site override, because it is the organisation's words and not the
 * scanner's.
 *
 * The conformance status is derived, never typed. Three values: partially
 * conformant when any criterion failed or partly supports; not fully
 * evaluated when any criterion has no determination; fully conformant only
 * when every criterion carries a determination of supports or not
 * applicable. There is no way to configure a better answer than the report
 * gives, and that is the point of generating it.
 */
final class StatementBuilder
{
    public const SECTION_508 = 'section508';

    public const EN_301_549 = 'en301549';

    public const TEMPLATES = [self::SECTION_508, self::EN_301_549];

    /**
     * @param  array<string, mixed>  $config  the `statement` config block
     */
    public function __construct(
        private readonly ReportDatabase $database,
        private readonly ReportWriter $writer,
        private readonly array $config,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(?string $siteHandle = null, ?string $template = null): array
    {
        $site = ($siteHandle !== null ? Site::get($siteHandle) : null) ?? Site::current() ?? Site::default();
        $handle = $site?->handle();

        $text = $this->textFor($handle);
        $template = in_array($template, self::TEMPLATES, true) ? $template : ($text['template'] ?? self::SECTION_508);

        return [
            'site' => ['handle' => $handle, 'name' => $site?->name(), 'url' => $site?->absoluteUrl()],
            'template' => in_array($template, self::TEMPLATES, true) ? $template : self::SECTION_508,
            'organization' => $text['organization'] ?: $site?->name(),
            'commitment' => $text['commitment'] ?: null,
            'feedback' => $text['feedback'] ?: null,
            'contact' => array_filter((array) ($text['contact'] ?? []), fn ($v) => is_string($v) && $v !== ''),
            'escalation' => $text['escalation'] ?: null,
            'enforcement' => array_filter((array) ($text['enforcement'] ?? []), fn ($v) => is_string($v) && $v !== ''),
            'statement_date' => now()->toIso8601String(),
            'report' => $this->report($handle),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function textFor(?string $site): array
    {
        $global = $this->config;
        unset($global['sites'], $global['route']);

        $override = $site !== null ? (array) (($this->config['sites'] ?? [])[$site] ?? []) : [];

        return array_replace_recursive($global, $override);
    }

    /**
     * The latest report for the site, or for every site, read from its JSON.
     *
     * @return array<string, mixed>|null
     */
    private function report(?string $site): ?array
    {
        if (! $this->database->isInstalled()) {
            return null;
        }

        $report = null;

        if ($site !== null) {
            $report = Report::where('site', $site)->orderByDesc('id')->first();
        }

        $report ??= Report::whereNull('site')->orderByDesc('id')->first();

        if ($report === null) {
            return null;
        }

        $path = $this->writer->absolutePath($report->json_path);
        $data = $path !== null && is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($data)) {
            // The row exists and the file does not: the report was generated
            // and its JSON removed. Say what the row knows and nothing more.
            return [
                'uuid' => $report->uuid,
                'generated_at' => $report->generated_at?->toIso8601String(),
                'evaluated_at' => $report->scan?->finished_at?->toIso8601String(),
                'standard' => $report->standard,
                'standard_label' => Wcag::label((string) $report->standard),
                'pages_read' => (int) ($report->scan?->pages_scanned ?? 0),
                'pages_total' => (int) ($report->scan?->pages_total ?? 0),
                'engine' => trim(($report->scan?->engine ?? '').' '.($report->scan?->engine_version ?? '')),
                'criteria_total' => count(Wcag::criteria((string) $report->standard)),
                'counts' => null,
                'assessed_by_people' => 0,
                'does_not_support' => [],
                'partially_supports' => [],
                'issues_total' => (int) ($report->scan?->issues_total ?? 0),
                'by_impact' => (array) ($report->scan?->issues_by_impact ?? []),
                'outside_wcag' => [],
                'status' => 'not_fully_evaluated',
                'detail_missing' => true,
            ];
        }

        $counts = [];

        foreach ([CriterionAssessment::SUPPORTS, CriterionAssessment::PARTIALLY_SUPPORTS, CriterionAssessment::DOES_NOT_SUPPORT, CriterionAssessment::NOT_APPLICABLE, CriterionAssessment::NOT_EVALUATED] as $status) {
            $counts[$status] = 0;
        }

        $failing = [];
        $partial = [];

        foreach ($data['criteria'] as $row) {
            $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;

            if ($row['status'] === CriterionAssessment::DOES_NOT_SUPPORT) {
                $failing[] = ['number' => $row['number'], 'name' => $row['name'], 'remarks' => $row['remarks'], 'evidence' => $row['evidence']];
            } elseif ($row['status'] === CriterionAssessment::PARTIALLY_SUPPORTS) {
                $partial[] = ['number' => $row['number'], 'name' => $row['name'], 'remarks' => $row['remarks'], 'evidence' => $row['evidence']];
            }
        }

        return [
            'uuid' => $data['uuid'],
            'generated_at' => $data['generated_at'],
            'evaluated_at' => $data['scan']['finished_at'] ?? $data['generated_at'],
            'standard' => $data['standard'],
            'standard_label' => $data['standard_label'],
            'pages_read' => (int) $data['scan']['pages_scanned'],
            'pages_total' => (int) $data['scan']['pages_total'],
            'engine' => trim($data['scan']['engine'].' '.$data['scan']['engine_version']),
            'criteria_total' => count($data['criteria']),
            'counts' => $counts,
            'assessed_by_people' => count($data['methods']['assessed_by_people'] ?? []),
            'does_not_support' => $failing,
            'partially_supports' => $partial,
            'issues_total' => (int) $data['summary']['issues_total'],
            'by_impact' => (array) $data['summary']['by_impact'],
            'outside_wcag' => (array) ($data['summary']['outside_wcag'] ?? []),
            'status' => self::status($counts),
            'detail_missing' => false,
        ];
    }

    /**
     * @param  array<string, int>  $counts
     */
    public static function status(array $counts): string
    {
        if (($counts[CriterionAssessment::DOES_NOT_SUPPORT] ?? 0) > 0 || ($counts[CriterionAssessment::PARTIALLY_SUPPORTS] ?? 0) > 0) {
            return 'partially_conformant';
        }

        if (($counts[CriterionAssessment::NOT_EVALUATED] ?? 0) > 0) {
            return 'not_fully_evaluated';
        }

        return 'fully_conformant';
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'fully_conformant' => 'Fully conformant',
            'partially_conformant' => 'Partially conformant',
            default => 'Not fully evaluated',
        };
    }

    public static function date(?string $iso): string
    {
        return $iso === null ? 'an unrecorded date' : Carbon::parse($iso)->format('j F Y');
    }
}
