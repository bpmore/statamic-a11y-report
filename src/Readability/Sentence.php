<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Readability;

/**
 * The reading-level record of a scan, in a sentence: for the overview, the
 * history table and the command's summary, so the three say the same thing
 * in the same words.
 *
 * A record is one of four things, and each gets its own sentence rather
 * than a blank: not measured, because the dimension was switched off; no
 * page graded, with why when every page had the same reason; the median,
 * against the target, with how many pages sit above it. A scan from before
 * the dimension existed has no record, and a caller that has none says
 * nothing.
 */
final class Sentence
{
    /**
     * @param  array<string, mixed>  $record
     */
    public static function forScan(array $record): string
    {
        if (! ($record['enabled'] ?? false)) {
            return 'Not measured: the reading-level dimension was switched off for this scan.';
        }

        $pages = (array) ($record['pages'] ?? []);
        $median = $record['median'] ?? null;
        $target = (string) ($record['target']['label'] ?? '');

        if (! is_array($median)) {
            return self::nothingGraded($pages);
        }

        $graded = (int) ($pages['graded'] ?? 0);
        $above = (int) ($pages['above'] ?? 0);

        $sentence = 'The median page reads at '.$median['label'].', '.self::comparison((string) ($median['comparison'] ?? '')).' the target of '.$target.'. ';
        $sentence .= $above === 0
            ? ($graded === 1 ? 'The one page graded is not above it.' : "None of the {$graded} pages graded is above it.")
            : "{$above} of {$graded} pages graded ".($above === 1 ? 'is' : 'are').' above it.';

        $left = self::notGraded($pages);

        return $left === '' ? $sentence : $sentence.' '.$left;
    }

    /**
     * The history table's cell: the median band, or the shortest reason
     * there is none.
     *
     * @param  array<string, mixed>|null  $record
     */
    public static function forHistory(?array $record): string
    {
        if ($record === null) {
            return '';
        }

        if (! ($record['enabled'] ?? false)) {
            return 'not measured';
        }

        $median = $record['median'] ?? null;

        if (! is_array($median)) {
            return 'no page graded';
        }

        $pages = (array) ($record['pages'] ?? []);
        $above = (int) ($pages['above'] ?? 0);

        return $median['label'].', '.$above.' above target';
    }

    /**
     * @param  array<string, int>  $pages
     */
    private static function nothingGraded(array $pages): string
    {
        $refused = (int) ($pages['refused'] ?? 0);
        $nothing = (int) ($pages['nothing'] ?? 0);
        $failed = (int) ($pages['failed'] ?? 0);

        if ($refused + $nothing + $failed === 0) {
            return 'No page was graded.';
        }

        if ($nothing === 0 && $failed === 0) {
            return 'No page was graded: every page read is on a site whose language the formulas were not calibrated on.';
        }

        if ($refused === 0 && $failed === 0) {
            return 'No page was graded: no page read had prose left once names, quotations, code, references and the site\'s furniture were set aside.';
        }

        return 'No page was graded. '.self::notGraded($pages);
    }

    /**
     * The pages that got no grade, each kind counted, or nothing when
     * every page was graded.
     *
     * @param  array<string, int>  $pages
     */
    private static function notGraded(array $pages): string
    {
        $parts = [];

        foreach (['refused' => 'in a language the formulas do not cover', 'nothing' => 'with nothing to grade', 'failed' => 'the grader could not read'] as $key => $why) {
            $n = (int) ($pages[$key] ?? 0);

            if ($n > 0) {
                $parts[] = $n.' '.($n === 1 ? 'page' : 'pages').' '.$why;
            }
        }

        return $parts === [] ? '' : 'Not graded: '.implode(', ', $parts).'.';
    }

    private static function comparison(string $value): string
    {
        return match ($value) {
            'above' => 'above',
            'below' => 'below',
            default => 'within',
        };
    }
}
