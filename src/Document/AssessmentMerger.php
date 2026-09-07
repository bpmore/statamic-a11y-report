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
 *
 * Rule 1 is the only way a row can claim something the scan disagrees with,
 * because rule 2 settles every unlocked case against the person. Where it
 * happens the row is marked `contradicted`, which changes no determination
 * and only says the two do not agree.
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
            $status = $human->status ?: CriterionAssessment::NOT_EVALUATED;

            return self::row(
                status: $status,
                method: $human->method ?: 'manual',
                remarks: (string) $human->remarks,
                evidence: $evidence,
                human: $human,
                automated: $covered,
                // Agreeing with the failures is not a contradiction, and a
                // criterion nobody has failed has nothing to contradict.
                contradicted: $failure !== null && $status !== CriterionAssessment::DOES_NOT_SUPPORT,
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
    private static function row(string $status, string $method, string $remarks, string $evidence, ?CriterionAssessment $human, bool $automated, bool $contradicted = false): array
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
            // A person's locked answer standing over failures the scan can
            // show. Rule 1 says it stands, and it does; this only says that
            // the two disagree.
            //
            // Only a locked row can be here. Rule 2 turns an unlocked answer
            // into "does not support" the moment the engine finds anything,
            // which is the product already treating this conflict as
            // something that matters. Locked, it resolves the other way, and
            // said nothing at all.
            //
            // It is the shortest path in this product to a claim a scan
            // disagrees with, in a document filed as evidence, and a reader
            // had to notice a sentence of remarks to see it.
            'contradicted' => $contradicted,
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
