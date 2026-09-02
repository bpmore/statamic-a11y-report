<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Document;

use Bpmore\A11yReport\Engine\ScanEngine;
use Bpmore\A11yReport\Models\Issue;
use Bpmore\A11yReport\Models\Scan;

/**
 * What one completed scan can say about each success criterion: which
 * criteria its engine could cite at all, and which it found failures under.
 * Shared by the report and the worksheet so the two never disagree.
 */
final class ScanEvidence
{
    public function __construct(private readonly ScanEngine $engine) {}

    /**
     * The criteria the engine that ran the scan can cite. Empty when that
     * engine is not the one bound now, because then its results cannot be
     * attributed and the honest answer is that nothing was evaluated.
     *
     * @return array<int, string>
     */
    public function automatedCriteria(Scan $scan): array
    {
        return $scan->engine === $this->engine->key() ? $this->engine->criteria() : [];
    }

    /**
     * @return array<string, array{issues: int, pages: int, rules: array<int, string>}>
     */
    public function failuresByCriterion(Scan $scan): array
    {
        $failures = [];

        Issue::where('scan_id', $scan->id)->orderBy('id')->chunk(500, function ($issues) use (&$failures) {
            foreach ($issues as $issue) {
                foreach ((array) $issue->wcag_criteria as $number) {
                    $failures[$number] ??= ['issues' => 0, 'pages' => [], 'rules' => []];
                    $failures[$number]['issues']++;
                    $failures[$number]['pages'][$issue->page_id] = true;
                    $failures[$number]['rules'][$issue->rule_id] = true;
                }
            }
        });

        foreach ($failures as $number => $f) {
            $failures[$number] = ['issues' => $f['issues'], 'pages' => count($f['pages']), 'rules' => array_keys($f['rules'])];
        }

        return $failures;
    }

    /** The latest complete scan for a site, or for every site when there is none for it. */
    public static function latestScan(?string $site): ?Scan
    {
        $query = Scan::where('status', Scan::COMPLETE)->orderByDesc('id');

        if ($site !== null) {
            $scan = (clone $query)->where('site', $site)->first();

            if ($scan !== null) {
                return $scan;
            }
        }

        return $query->whereNull('site')->first() ?? $query->first();
    }
}
