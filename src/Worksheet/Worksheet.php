<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Worksheet;

use Bpmore\A11yReport\Document\AssessmentMerger;
use Bpmore\A11yReport\Document\ScanEvidence;
use Bpmore\A11yReport\Document\Wcag;
use Bpmore\A11yReport\Models\CriterionAssessment;
use Bpmore\A11yReport\Models\Scan;

/**
 * The criteria worksheet: every success criterion for one site (or the
 * global default), what the latest scan says about it, and what a person
 * has written. The merge is the report's own `AssessmentMerger`, so what the
 * worksheet shows as the effective result is exactly what the next report
 * will print.
 */
final class Worksheet
{
    public const STATUSES = [
        CriterionAssessment::SUPPORTS,
        CriterionAssessment::PARTIALLY_SUPPORTS,
        CriterionAssessment::DOES_NOT_SUPPORT,
        CriterionAssessment::NOT_APPLICABLE,
        CriterionAssessment::NOT_EVALUATED,
    ];

    public const METHODS = ['manual', 'automated', 'both'];

    public function __construct(private readonly ScanEvidence $evidence) {}

    /**
     * @return array{site: ?string, scan: ?Scan, standard: string, rows: array<int, array<string, mixed>>}
     */
    public function build(?string $site): array
    {
        $scan = ScanEvidence::latestScan($site);
        $standard = $scan !== null ? Wcag::standardForRuleset((string) $scan->ruleset) : 'wcag22aa';
        $automated = $scan !== null ? $this->evidence->automatedCriteria($scan) : [];
        $failures = $scan !== null ? $this->evidence->failuresByCriterion($scan) : [];
        $pages = (int) ($scan?->pages_scanned ?? 0);

        $stored = CriterionAssessment::query()
            ->when($site === null, fn ($q) => $q->whereNull('site'), fn ($q) => $q->where('site', $site))
            ->get()
            ->keyBy('criterion');

        // The global rows a site inherits when it has none of its own, shown
        // so a person editing a site sees what they are overriding.
        $inherited = $site === null ? collect() : CriterionAssessment::whereNull('site')->get()->keyBy('criterion');

        $rows = [];

        foreach (Wcag::criteria($standard) as $criterion) {
            $own = $stored->get($criterion->number);
            $effective = AssessmentMerger::merge($criterion, $automated, $failures, $own ?? $inherited->get($criterion->number), $pages);

            $rows[] = [
                'number' => $criterion->number,
                'name' => $criterion->name,
                'level' => $criterion->level,
                'automated' => in_array($criterion->number, $automated, true),
                'failure' => $failures[$criterion->number] ?? null,
                'evidence' => $effective['evidence'],
                'effective_status' => $effective['status'],
                'own' => $own,
                'inherited' => $own === null ? $inherited->get($criterion->number) : null,
            ];
        }

        return ['site' => $site, 'scan' => $scan, 'standard' => $standard, 'rows' => $rows];
    }

    /**
     * Write what changed, and only what changed. A row whose status is left
     * empty is deleted: the person is handing the criterion back to the
     * automated result. Returns the numbers of the criteria written.
     *
     * @param  array<string, array<string, mixed>>  $input  by criterion number: status, method, remarks, locked
     * @return array{written: array<int, string>, deleted: array<int, string>}
     */
    public function save(?string $site, array $input, ?string $by): array
    {
        $written = [];
        $deleted = [];
        $now = now();

        $existing = CriterionAssessment::query()
            ->when($site === null, fn ($q) => $q->whereNull('site'), fn ($q) => $q->where('site', $site))
            ->get()
            ->keyBy('criterion');

        foreach ($input as $number => $fields) {
            $criterion = Wcag::find((string) $number);

            if ($criterion === null || ! is_array($fields)) {
                continue;
            }

            $status = (string) ($fields['status'] ?? '');
            $row = $existing->get($criterion->number);

            if ($status === '') {
                if ($row !== null) {
                    $row->delete();
                    $deleted[] = $criterion->number;
                }

                continue;
            }

            if (! in_array($status, self::STATUSES, true)) {
                continue;
            }

            $values = [
                'status' => $status,
                'method' => in_array($fields['method'] ?? null, self::METHODS, true) ? $fields['method'] : 'manual',
                'remarks' => trim((string) ($fields['remarks'] ?? '')) ?: null,
                'locked' => (bool) ($fields['locked'] ?? false),
            ];

            $unchanged = $row !== null
                && $row->status === $values['status']
                && $row->method === $values['method']
                && ($row->remarks ?? null) === $values['remarks']
                && (bool) $row->locked === $values['locked'];

            if ($unchanged) {
                continue;
            }

            CriterionAssessment::updateOrCreate(
                ['site' => $site, 'criterion' => $criterion->number],
                array_merge($values, ['level' => $criterion->level, 'assessed_by' => $by, 'assessed_at' => $now]),
            );

            $written[] = $criterion->number;
        }

        return ['written' => $written, 'deleted' => $deleted];
    }
}
