<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Document;

use Bpmore\A11yReport\Engine\Finding;
use Bpmore\A11yReport\Engine\ScanEngine;
use Bpmore\A11yReport\Models\CriterionAssessment;
use Bpmore\A11yReport\Models\Issue;
use Bpmore\A11yReport\Models\IssueState;
use Bpmore\A11yReport\Models\Report;
use Bpmore\A11yReport\Models\Scan;
use Bpmore\A11yReport\Models\ScanPage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Statamic\Facades\Site;

/**
 * Everything the conformance document says, as one array, from one scan.
 *
 * Built once and handed to every format, so the HTML, the JSON and one day
 * the PDF cannot disagree about a number. The shape is documented by
 * `build()` and pinned by the tests; the JSON export is this array verbatim,
 * less the logo's bytes, which `ReportWriter` drops from it.
 */
final class ReportBuilder
{
    /**
     * @param  array<string, mixed>  $config  the addon's `report` config block
     */
    public function __construct(
        private readonly ScanEngine $engine,
        private readonly array $config,
    ) {
        $this->evidence = new ScanEvidence($engine);
    }

    private readonly ScanEvidence $evidence;

    /**
     * @return array<string, mixed>
     */
    public function build(Scan $scan, ?string $generatedBy): array
    {
        if ($scan->status !== Scan::COMPLETE) {
            throw new \InvalidArgumentException("Scan {$scan->uuid} is {$scan->status}; only a complete scan can be reported on.");
        }

        $standard = $this->standardFor($scan);
        $criteriaList = Wcag::criteria($standard);
        $automated = $this->evidence->automatedCriteria($scan, $standard);
        $failures = $this->evidence->failuresByCriterion($scan);
        $assessments = $this->assessments($scan->site);
        $pagesRead = (int) $scan->pages_scanned;

        $rows = [];

        foreach ($criteriaList as $criterion) {
            $rows[] = array_merge(
                ['number' => $criterion->number, 'name' => $criterion->name, 'level' => $criterion->level, 'url' => $criterion->understandingUrl(Wcag::version($standard))],
                AssessmentMerger::merge($criterion, $automated, $failures, $assessments[$criterion->number] ?? null, $pagesRead),
            );
        }

        $previous = Report::where('site', $scan->site)->orderByDesc('id')->first();
        $subject = $this->subject($scan->site);

        return [
            'uuid' => (string) Str::uuid(),
            'title' => 'Accessibility Conformance Report',
            'kind' => 'self-assessment',
            'generated_at' => now()->toIso8601String(),
            'generated_by' => $generatedBy,
            'generator' => 'Accessibility Report for Statamic',
            'standard' => $standard,
            'standard_label' => Wcag::label($standard),
            'standard_url' => Wcag::specUrl($standard),
            'lang' => $this->lang($scan->site),
            'subject' => $subject,
            'evaluator' => [
                'name' => $this->config['evaluator']['name'] ?? null,
                'organization' => $this->config['evaluator']['organization'] ?? null,
                'email' => $this->config['evaluator']['email'] ?? null,
            ],
            // The customer's mark, and nothing the evaluator wears: a second
            // logo on the cover would invite a reader to take a
            // self-assessment for a third-party audit.
            'brand' => $this->brand($subject),
            'scan' => [
                'id' => $scan->id,
                'uuid' => $scan->uuid,
                'engine' => $scan->engine,
                'engine_version' => $scan->engine_version,
                'ruleset' => $scan->ruleset,
                'trigger' => $scan->trigger,
                'started_at' => $scan->started_at?->toIso8601String(),
                'finished_at' => $scan->finished_at?->toIso8601String(),
                'pages_total' => (int) $scan->pages_total,
                'pages_scanned' => $pagesRead,
                'pages_errored' => (int) $scan->pages_errored,
                'pages_skipped' => (int) $scan->pages_skipped,
                'scope' => (array) ($scan->scope ?? []),
                'scope_text' => $this->scopeText($scan),
            ],
            'coverage' => $this->coverage($scan),
            'methods' => [
                'automated_criteria' => $automated,
                'not_evaluated' => array_values(array_map(
                    fn (array $r) => $r['number'],
                    array_filter($rows, fn (array $r) => $r['status'] === CriterionAssessment::NOT_EVALUATED),
                )),
                'assessed_by_people' => array_values(array_map(
                    fn (array $r) => $r['number'],
                    array_filter($rows, fn (array $r) => $r['locked']),
                )),
            ],
            'criteria' => $rows,
            'summary' => [
                'issues_total' => (int) $scan->issues_total,
                'by_impact' => $this->byImpact($scan),
                'by_criterion' => $this->summaryByCriterion($failures, $standard),
                'outside_wcag' => $this->outsideWcag($scan),
                'diff' => $scan->diff,
                'previous_report' => $previous === null ? null : [
                    'uuid' => $previous->uuid,
                    'generated_at' => $previous->generated_at?->toIso8601String(),
                    'issues_total' => (int) ($previous->scan?->issues_total ?? 0),
                ],
            ],
            'issues' => $this->issues($scan),
            'issues_omitted' => max(0, $this->openIssueCount($scan) - $this->appendixLimit()),
            'remediation_plan' => $this->config['remediation_plan'] ?? null,
        ];
    }

    /**
     * The mark on the cover, with anything that could not be used said out
     * loud. A logo is decoration and a report is not: whatever is wrong with
     * a picture, the document is still owed, so every failure here is a
     * warning in the log rather than an exception.
     *
     * Which site's mark, if any, follows from what the report is about
     * rather than from how the scan was narrowed. A single-site install
     * scans without naming a site, and the report is still that site's.
     *
     * @param  array<string, mixed>  $subject
     * @return array<string, mixed>
     */
    private function brand(array $subject): array
    {
        $sites = (array) ($subject['sites'] ?? []);
        $site = count($sites) === 1 ? ($sites[0]['handle'] ?? null) : null;

        $brand = Brand::resolve((array) ($this->config['brand'] ?? []), is_string($site) ? $site : null);

        foreach ($brand['dropped'] as $reason) {
            Log::warning('[a11y-report] '.$reason);
        }

        return $brand;
    }

    private function standardFor(Scan $scan): string
    {
        $configured = $this->config['standard'] ?? null;

        return is_string($configured) && isset(Wcag::STANDARDS[$configured])
            ? $configured
            : Wcag::standardForRuleset((string) $scan->ruleset);
    }

    /** @return array<string, CriterionAssessment> by criterion number; a site's row beats the global one */
    private function assessments(?string $site): array
    {
        $rows = CriterionAssessment::whereNull('site')->get()->keyBy('criterion')->all();

        if ($site !== null) {
            foreach (CriterionAssessment::where('site', $site)->get() as $row) {
                $rows[$row->criterion] = $row;
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function subject(?string $site): array
    {
        $sites = ($site === null ? Site::all() : collect([Site::get($site)]))->filter()->values();

        return [
            'site' => $site,
            'sites' => $sites->map(fn ($s) => ['handle' => $s->handle(), 'name' => $s->name(), 'url' => $s->absoluteUrl()])->all(),
            'name' => $sites->map->name()->implode(', '),
            'url' => $sites->map->absoluteUrl()->implode(', '),
        ];
    }

    private function lang(?string $site): string
    {
        $s = $site === null ? Site::default() : (Site::get($site) ?? Site::default());

        return (string) ($s?->shortLocale() ?: 'en');
    }

    private function scopeText(Scan $scan): string
    {
        $scope = (array) ($scan->scope ?? []);
        $text = 'Every published page';

        if (($scope['collections'] ?? []) !== []) {
            $text .= ' in the '.implode(', ', $scope['collections']).' '.(count($scope['collections']) === 1 ? 'collection' : 'collections');
        }

        if (($scope['sites'] ?? []) !== []) {
            $text .= ' on the '.implode(', ', $scope['sites']).' '.(count($scope['sites']) === 1 ? 'site' : 'sites');
        }

        if (! empty($scope['since'])) {
            $text .= ', changed since '.substr((string) $scope['since'], 0, 10);
        }

        if (($scope['exclude_urls'] ?? []) !== []) {
            $text .= ', excluding URLs matching '.implode(', ', $scope['exclude_urls']);
        }

        return $text.'.';
    }

    /** @return array<string, mixed> */
    private function coverage(Scan $scan): array
    {
        $summaries = ScanPage::where('scan_id', $scan->id)
            ->where('status', ScanPage::SCANNED)
            ->selectRaw('coverage_summary, count(*) as n')
            ->groupBy('coverage_summary')
            ->orderByDesc('n')
            ->pluck('n', 'coverage_summary');

        return [
            'summaries' => $summaries->map(fn ($n, $summary) => ['summary' => (string) $summary, 'pages' => (int) $n])->values()->all(),
            'pages_partly_seen' => (int) $summaries->filter(fn ($n, $summary) => str_contains((string) $summary, 'partly') || str_contains((string) $summary, 'could not'))->sum(),
        ];
    }

    /** @return array<string, int> */
    private function byImpact(Scan $scan): array
    {
        $result = [];

        foreach (Finding::IMPACTS as $impact) {
            $result[$impact] = (int) (($scan->issues_by_impact ?? [])[$impact] ?? 0);
        }

        return $result;
    }

    /**
     * @param  array<string, array{issues: int, pages: int, rules: array<int, string>}>  $failures
     * @return array<int, array{number: string, name: string, issues: int, pages: int}>
     */
    private function summaryByCriterion(array $failures, string $standard): array
    {
        $rows = [];
        $inTable = array_map(fn ($c) => $c->number, Wcag::criteria($standard));

        foreach ($failures as $number => $f) {
            // Only criteria this report's table holds. An engine can cite one
            // the table does not have (axe tags some Level A rules with a AAA
            // criterion too), and a summary row for a criterion with no row
            // above it is a number a reader cannot place. The findings
            // themselves are still listed under Open issues with the label the
            // engine gave them.
            if (! in_array($number, $inTable, true)) {
                continue;
            }

            $rows[] = ['number' => $number, 'name' => Wcag::find($number)?->name ?? '', 'url' => Wcag::urlFor($number, $standard), 'issues' => $f['issues'], 'pages' => $f['pages']];
        }

        usort($rows, fn ($a, $b) => version_compare($a['number'], $b['number']));

        return $rows;
    }

    /**
     * Findings under a house rule, which cite no criterion and are reported
     * apart so they cannot be mistaken for one.
     *
     * @return array<int, array{label: string, rule_id: string, issues: int, pages: int}>
     */
    private function outsideWcag(Scan $scan): array
    {
        return Issue::where('scan_id', $scan->id)
            ->where('wcag_criteria', '[]')
            ->selectRaw('label, rule_id, count(*) as issues, count(distinct page_id) as pages')
            ->groupBy('label', 'rule_id')
            ->orderBy('label')
            ->get()
            ->map(fn ($r) => ['label' => (string) $r->label, 'rule_id' => (string) $r->rule_id, 'issues' => (int) $r->issues, 'pages' => (int) $r->pages])
            ->all();
    }

    private function openIssueCount(Scan $scan): int
    {
        return $this->openIssueQuery($scan)->count();
    }

    private function openIssueQuery(Scan $scan)
    {
        return Issue::where('a11y_issues.scan_id', $scan->id)
            ->join('a11y_scan_pages', 'a11y_scan_pages.id', '=', 'a11y_issues.page_id')
            ->leftJoin('a11y_issue_states', 'a11y_issue_states.fingerprint', '=', 'a11y_issues.fingerprint')
            ->whereIn('a11y_issue_states.status', [IssueState::OPEN, IssueState::IN_PROGRESS]);
    }

    /** @return array<int, array<string, mixed>> */
    private function issues(Scan $scan): array
    {
        $rank = "case a11y_issues.impact when 'critical' then 0 when 'serious' then 1 when 'moderate' then 2 else 3 end";

        return $this->openIssueQuery($scan)
            ->select([
                'a11y_scan_pages.url', 'a11y_scan_pages.path', 'a11y_scan_pages.site',
                'a11y_issues.label', 'a11y_issues.wcag_criteria', 'a11y_issues.impact', 'a11y_issues.rule_id',
                'a11y_issues.message', 'a11y_issues.remedy', 'a11y_issues.pointer', 'a11y_issues.selector', 'a11y_issues.occurrences',
                'a11y_issue_states.status as state', 'a11y_issue_states.first_seen_at',
            ])
            ->orderByRaw($rank)
            ->orderBy('a11y_scan_pages.path')
            ->orderBy('a11y_issues.id')
            ->limit($this->appendixLimit())
            ->get()
            ->map(fn ($i) => [
                'url' => $i->url,
                'path' => $i->path,
                'site' => $i->site,
                'label' => $i->label,
                'criteria' => (array) $i->wcag_criteria,
                'impact' => $i->impact,
                'rule_id' => $i->rule_id,
                'message' => $i->message,
                'remedy' => $i->remedy,
                'target' => $i->selector ?: $i->pointer,
                'occurrences' => (int) $i->occurrences,
                'state' => $i->state,
                'first_seen_at' => $i->first_seen_at,
            ])
            ->all();
    }

    private function appendixLimit(): int
    {
        return max(1, (int) ($this->config['appendix_limit'] ?? 1000));
    }
}
