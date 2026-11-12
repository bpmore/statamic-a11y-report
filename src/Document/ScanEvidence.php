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
     * The criteria the engine that ran the scan can cite, of the ones this
     * report's table contains.
     *
     * Empty when that engine is not the one bound now, because then its
     * results cannot be attributed and the honest answer is that nothing was
     * evaluated.
     *
     * Narrowed to the standard because an engine may cite more than a table
     * holds. axe tags some Level A rules with a Level AAA criterion as well,
     * and counting those would have the limits section say the checks cover
     * more criteria than the table has rows, which is a claim about an
     * evaluation of something the document does not report on.
     *
     * @return array<int, string>
     */
    public function automatedCriteria(Scan $scan, ?string $standard = null): array
    {
        // What the engine recorded while it was running, in preference to
        // asking whichever engine happens to be bound now. A scan run with one
        // engine and reported on a site configured for the other used to say
        // nothing had been evaluated automatically.
        $criteria = is_array($scan->criteria) ? $scan->criteria : null;

        if ($criteria === null) {
            // Scans from before the criteria were recorded. The engine that
            // ran is the only one that can answer for them, so a scan by any
            // other engine is honestly unattributable.
            if ($scan->engine !== $this->engine->key()) {
                return [];
            }

            $criteria = $this->engine->criteria();
        }

        if ($standard === null) {
            return $criteria;
        }

        $inTable = array_map(fn ($c) => $c->number, Wcag::criteria($standard));

        return array_values(array_intersect($criteria, $inTable));
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

    /**
     * The latest complete scan that covers a site: one named for it, or one
     * of every site, whichever is newer. Never a scan of some other site.
     *
     * With no site asked for, the newest complete scan, whatever it covered.
     * This used to prefer a scan of every site over a newer one of a named
     * site, and a preference that beats recency is not a preference, it is
     * a way to report on the wrong week. Recency wins here too: a scan of
     * every site covers the one asked for, and a newer one of those is the
     * newer evidence.
     *
     * With a site asked for and no scan named for it, this used to fall back
     * to the newest scan of anything, which on a multisite install could be
     * another site's alone, and the document that came out of it wore that
     * other site's name. The one command-line path did not fall back at all
     * and said there was no scan. Both now answer this one question.
     */
    public static function latestScan(?string $site): ?Scan
    {
        return Scan::where('status', Scan::COMPLETE)
            ->when($site !== null, fn ($q) => $q->where(fn ($q) => $q->where('site', $site)->orWhereNull('site')))
            ->orderByDesc('id')
            ->first();
    }
}
