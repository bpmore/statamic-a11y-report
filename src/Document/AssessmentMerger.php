<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Document;

use Bpmore\A11yReport\Models\CriterionAssessment;

/**
 * One row of the conformance table, from what the engine found and what a
 * person decided.
 *
 * The rules, in the order they are applied, because getting them wrong
 * destroys the product:
 *
 * 1. A person's locked assessment wins outright. The engine's evidence is
 *    appended as a separate sentence and changes nothing.
 * 2. Where the engine found failures under a criterion, the row is
 *    "does not support", unless rule 1 applies. An image with no
 *    description fails 1.1.1; that is not a matter of opinion.
 * 3. Where the engine found no failures, the row is NOT "supports". The
 *    engine tests part of a criterion, and finding nothing in the part it
 *    tests is evidence, not a determination. The row stays "not evaluated"
 *    with the evidence in its remarks, until a person looks and locks it.
 * 4. An unlocked assessment a person has written stands, except against
 *    rule 2: a failure the engine can show beats an unlocked "supports".
 * 5. Nothing else is ever "supports". The default is "not evaluated".
 */
final class AssessmentMerger
{
    /**
     * @param  array<int, string>  $automated  criteria the engine can cite
     * @param  array<string, array{issues: int, pages: int, rules: array<int, string>}>  $failures  by criterion number
     * @return array{status: string, method: string, remarks: string, evidence: string, assessed_by: ?string, assessed_at: ?string, locked: bool, automated: bool}
     */
    public static function merge(
        Criterion $criterion,
        array $automated,
        array $failures,
        ?CriterionAssessment $human,
        int $pagesRead,
    ): array {
        $covered = in_array($criterion->number, $automated, true);
        $failure = $failures[$criterion->number] ?? null;

        $evidence = match (true) {
            $failure !== null => sprintf(
                'Automated checks found %d %s on %d %s (%s).',
                $failure['issues'],
                $failure['issues'] === 1 ? 'issue' : 'issues',
                $failure['pages'],
                $failure['pages'] === 1 ? 'page' : 'pages',
                implode(', ', $failure['rules']),
            ),
            $covered => sprintf(
                'Automated checks for the parts of this criterion they test found no failures across %d %s. That is evidence, not a determination: the criterion as a whole was not evaluated automatically.',
                $pagesRead,
                $pagesRead === 1 ? 'page' : 'pages',
            ),
            default => 'Not covered by automated checks.',
        };

        if ($human !== null && $human->locked) {
            return self::row(
                status: $human->status ?: CriterionAssessment::NOT_EVALUATED,
                method: $human->method ?: 'manual',
                remarks: (string) $human->remarks,
                evidence: $evidence,
                human: $human,
                automated: $covered,
            );
        }

        if ($failure !== null) {
            $remarks = $human !== null && trim((string) $human->remarks) !== ''
                ? 'Unlocked assessment on file: '.trim((string) $human->remarks)
                : '';

            return self::row(CriterionAssessment::DOES_NOT_SUPPORT, 'automated', $remarks, $evidence, $human, true);
        }

        if ($human !== null) {
            return self::row(
                status: $human->status ?: CriterionAssessment::NOT_EVALUATED,
                method: $human->method ?: 'manual',
                remarks: (string) $human->remarks,
                evidence: $evidence,
                human: $human,
                automated: $covered,
            );
        }

        return self::row(CriterionAssessment::NOT_EVALUATED, $covered ? 'automated' : 'manual', '', $evidence, null, $covered);
    }

    /** @return array<string, mixed> */
    private static function row(string $status, string $method, string $remarks, string $evidence, ?CriterionAssessment $human, bool $automated): array
    {
        return [
            'status' => $status,
            'method' => $method,
            'remarks' => $remarks,
            'evidence' => $evidence,
            'assessed_by' => $human?->assessed_by,
            'assessed_at' => $human?->assessed_at?->toIso8601String(),
            'locked' => (bool) ($human?->locked ?? false),
            'automated' => $automated,
        ];
    }

    public static function label(string $status): string
    {
        return match ($status) {
            CriterionAssessment::SUPPORTS => 'Supports',
            CriterionAssessment::PARTIALLY_SUPPORTS => 'Partially supports',
            CriterionAssessment::DOES_NOT_SUPPORT => 'Does not support',
            CriterionAssessment::NOT_APPLICABLE => 'Not applicable',
            default => 'Not evaluated',
        };
    }
}
