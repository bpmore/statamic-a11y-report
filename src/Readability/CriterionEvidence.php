<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Readability;

use Bpmore\A11yReport\Models\Scan;
use Bpmore\A11yReport\Models\ScanPage;

/**
 * What a scan's reading levels say toward SC 3.1.5 Reading Level, and
 * nothing more than that.
 *
 * The criterion is Level AAA and is not satisfied by writing simply. It is
 * satisfied by offering a simpler version, or supplemental content, wherever
 * text asks for more than lower secondary education level once proper names
 * and titles are set aside. A scan can measure the first half of that
 * sentence: which pages read above that level, with names already left out,
 * because the engine sets them aside before it counts. It cannot see whether
 * a simpler version exists. So this is evidence toward the criterion for a
 * person to weigh, and the row it feeds is "not evaluated" until that person
 * says otherwise. Nothing here is a failure of anything.
 *
 * Lower secondary education ends after nine years of schooling, which is
 * Grade 9 in the scale the formulas use, so a page reads above it when its
 * band starts at Grade 10. A band that straddles the line (9 to 10) is not
 * counted: the formulas are not that precise, and the honest direction of
 * error for evidence is under, not over.
 */
final class CriterionEvidence
{
    public const CRITERION = '3.1.5';

    /** The last grade of lower secondary education, in the scale the formulas report. */
    public const LOWER_SECONDARY = 9;

    /** How many of the pages above the level are named, so a person knows where to look. */
    private const NAMED_PAGES = 20;

    /**
     * @return array{measured: bool, graded: int, above: int, pages_above: list<array{path: string, url: string, label: string}>, median: ?string, sentence: string}
     */
    public static function forScan(Scan $scan): array
    {
        $record = $scan->readability;

        if (! is_array($record) || ! ($record['enabled'] ?? false)) {
            return self::none('The reading level was not measured in this scan, so there is no evidence toward this criterion.');
        }

        $pages = (array) ($record['pages'] ?? []);
        $graded = (int) ($pages['graded'] ?? 0);

        if ($graded === 0) {
            return self::none('No page was graded for reading level in this scan, so there is no evidence toward this criterion. '.Sentence::forScan($record));
        }

        $above = [];
        $count = 0;

        ScanPage::where('scan_id', $scan->id)
            ->where('status', ScanPage::SCANNED)
            ->whereNotNull('readability')
            ->orderBy('path')
            ->lazy(500)
            ->each(function (ScanPage $page) use (&$above, &$count) {
                $reading = PageReading::fromArray((array) $page->readability);

                if (! $reading->isGraded() || $reading->band === null || $reading->band->low <= self::LOWER_SECONDARY) {
                    return;
                }

                $count++;

                if (count($above) < self::NAMED_PAGES) {
                    $above[] = ['path' => (string) $page->path, 'url' => (string) $page->url, 'label' => $reading->band->label()];
                }
            });

        $median = isset($record['median']['label']) ? (string) $record['median']['label'] : null;

        $sentence = sprintf(
            'Of the %d %s graded, %d %s above lower secondary level (Grade %d and up, with proper names and titles set aside before counting)%s. ',
            $graded,
            $graded === 1 ? 'page' : 'pages',
            $count,
            $count === 1 ? 'reads' : 'read',
            self::LOWER_SECONDARY + 1,
            $median === null ? '' : '; the median page reads at '.$median,
        );

        $sentence .= $count === 0
            ? 'No page asks more of a reader than the criterion allows, so nothing on the site needs a simpler version on this evidence. That is evidence toward the criterion, not a determination: what was graded is the pages as served, on the day of the scan.'
            : 'Whether a simpler version or supplemental content is offered for those pages is what this criterion asks, and no automated check can see it. This is evidence toward the criterion, not a determination.';

        return [
            'measured' => true,
            'graded' => $graded,
            'above' => $count,
            'pages_above' => $above,
            'median' => $median,
            'sentence' => $sentence,
        ];
    }

    /**
     * @return array{measured: bool, graded: int, above: int, pages_above: list<array{path: string, url: string, label: string}>, median: ?string, sentence: string}
     */
    private static function none(string $sentence): array
    {
        return ['measured' => false, 'graded' => 0, 'above' => 0, 'pages_above' => [], 'median' => null, 'sentence' => $sentence];
    }
}
