<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Document;

/**
 * Every Level A and AA success criterion in WCAG 2.0, 2.1 and 2.2.
 *
 * A hard-coded list, not a lookup, so the answer to "which criteria does the
 * report table contain" is something you can read in a minute and a test can
 * count. WCAG 2.1 Level A and AA is 50 criteria; 2.2 removes 4.1.1 Parsing
 * and adds six, for 55. AAA is deliberately absent: a report that lists AAA
 * criteria invites a claim about them, and nothing here can support one.
 */
final class Wcag
{
    public const STANDARDS = [
        'wcag21aa' => ['version' => '2.1', 'label' => 'WCAG 2.1 Level AA'],
        'wcag22aa' => ['version' => '2.2', 'label' => 'WCAG 2.2 Level AA'],
    ];

    /** @var array<int, array{0: string, 1: string, 2: string, 3: string, 4?: string}> number, name, level, since, removedIn */
    private const CRITERIA = [
        ['1.1.1', 'Non-text Content', 'A', '2.0'],
        ['1.2.1', 'Audio-only and Video-only (Prerecorded)', 'A', '2.0'],
        ['1.2.2', 'Captions (Prerecorded)', 'A', '2.0'],
        ['1.2.3', 'Audio Description or Media Alternative (Prerecorded)', 'A', '2.0'],
        ['1.2.4', 'Captions (Live)', 'AA', '2.0'],
        ['1.2.5', 'Audio Description (Prerecorded)', 'AA', '2.0'],
        ['1.3.1', 'Info and Relationships', 'A', '2.0'],
        ['1.3.2', 'Meaningful Sequence', 'A', '2.0'],
        ['1.3.3', 'Sensory Characteristics', 'A', '2.0'],
        ['1.3.4', 'Orientation', 'AA', '2.1'],
        ['1.3.5', 'Identify Input Purpose', 'AA', '2.1'],
        ['1.4.1', 'Use of Color', 'A', '2.0'],
        ['1.4.2', 'Audio Control', 'A', '2.0'],
        ['1.4.3', 'Contrast (Minimum)', 'AA', '2.0'],
        ['1.4.4', 'Resize Text', 'AA', '2.0'],
        ['1.4.5', 'Images of Text', 'AA', '2.0'],
        ['1.4.10', 'Reflow', 'AA', '2.1'],
        ['1.4.11', 'Non-text Contrast', 'AA', '2.1'],
        ['1.4.12', 'Text Spacing', 'AA', '2.1'],
        ['1.4.13', 'Content on Hover or Focus', 'AA', '2.1'],
        ['2.1.1', 'Keyboard', 'A', '2.0'],
        ['2.1.2', 'No Keyboard Trap', 'A', '2.0'],
        ['2.1.4', 'Character Key Shortcuts', 'A', '2.1'],
        ['2.2.1', 'Timing Adjustable', 'A', '2.0'],
        ['2.2.2', 'Pause, Stop, Hide', 'A', '2.0'],
        ['2.3.1', 'Three Flashes or Below Threshold', 'A', '2.0'],
        ['2.4.1', 'Bypass Blocks', 'A', '2.0'],
        ['2.4.2', 'Page Titled', 'A', '2.0'],
        ['2.4.3', 'Focus Order', 'A', '2.0'],
        ['2.4.4', 'Link Purpose (In Context)', 'A', '2.0'],
        ['2.4.5', 'Multiple Ways', 'AA', '2.0'],
        ['2.4.6', 'Headings and Labels', 'AA', '2.0'],
        ['2.4.7', 'Focus Visible', 'AA', '2.0'],
        ['2.4.11', 'Focus Not Obscured (Minimum)', 'AA', '2.2'],
        ['2.5.1', 'Pointer Gestures', 'A', '2.1'],
        ['2.5.2', 'Pointer Cancellation', 'A', '2.1'],
        ['2.5.3', 'Label in Name', 'A', '2.1'],
        ['2.5.4', 'Motion Actuation', 'A', '2.1'],
        ['2.5.7', 'Dragging Movements', 'AA', '2.2'],
        ['2.5.8', 'Target Size (Minimum)', 'AA', '2.2'],
        ['3.1.1', 'Language of Page', 'A', '2.0'],
        ['3.1.2', 'Language of Parts', 'AA', '2.0'],
        ['3.2.1', 'On Focus', 'A', '2.0'],
        ['3.2.2', 'On Input', 'A', '2.0'],
        ['3.2.3', 'Consistent Navigation', 'AA', '2.0'],
        ['3.2.4', 'Consistent Identification', 'AA', '2.0'],
        ['3.2.6', 'Consistent Help', 'A', '2.2'],
        ['3.3.1', 'Error Identification', 'A', '2.0'],
        ['3.3.2', 'Labels or Instructions', 'A', '2.0'],
        ['3.3.3', 'Error Suggestion', 'AA', '2.0'],
        ['3.3.4', 'Error Prevention (Legal, Financial, Data)', 'AA', '2.0'],
        ['3.3.7', 'Redundant Entry', 'A', '2.2'],
        ['3.3.8', 'Accessible Authentication (Minimum)', 'AA', '2.2'],
        ['4.1.1', 'Parsing', 'A', '2.0', '2.2'],
        ['4.1.2', 'Name, Role, Value', 'A', '2.0'],
        ['4.1.3', 'Status Messages', 'AA', '2.1'],
    ];

    /**
     * The criteria the given standard's conformance table lists, in order.
     *
     * @return array<int, Criterion>
     */
    public static function criteria(string $standard): array
    {
        $version = self::version($standard);

        return array_values(array_filter(self::all(), fn (Criterion $c) => $c->isIn($version)));
    }

    /** @return array<int, Criterion> */
    public static function all(): array
    {
        return array_map(fn (array $row) => new Criterion($row[0], $row[1], $row[2], $row[3], $row[4] ?? null), self::CRITERIA);
    }

    public static function find(string $number): ?Criterion
    {
        foreach (self::all() as $criterion) {
            if ($criterion->number === $number) {
                return $criterion;
            }
        }

        return null;
    }

    public static function version(string $standard): string
    {
        return self::STANDARDS[$standard]['version'] ?? self::STANDARDS['wcag22aa']['version'];
    }

    /** The W3C Recommendation itself, for the standard's label to point at. */
    public static function specUrl(string $standard): string
    {
        return 'https://www.w3.org/TR/WCAG'.str_replace('.', '', self::version($standard)).'/';
    }

    /**
     * The Understanding page for a criterion number under a standard, or
     * null for a number the catalogue does not know. Null, not a guessed
     * URL: a link that 404s from a conformance document is worse than
     * plain text, and a number outside Level A and AA is not one this
     * addon should be sending anybody to read up on as if it were in scope.
     */
    public static function urlFor(string $number, string $standard): ?string
    {
        return self::find($number)?->understandingUrl(self::version($standard));
    }

    /**
     * Every URL a document for this standard may link to, with the words
     * a PDF reader should say for it. PDF/UA requires an alternate
     * description on every link annotation, and Chrome writes none, so the
     * PDF stamp looks each link's destination up here.
     *
     * @return array<string, string> url => description
     */
    public static function linkDescriptions(string $standard): array
    {
        $version = self::version($standard);
        $out = [self::specUrl($standard) => 'The WCAG '.$version.' Recommendation at w3.org'];

        foreach (self::all() as $criterion) {
            $out[$criterion->understandingUrl($version)] = "Understanding success criterion {$criterion->number} {$criterion->name}, at w3.org";
        }

        return $out;
    }

    public static function label(string $standard): string
    {
        return self::STANDARDS[$standard]['label'] ?? self::STANDARDS['wcag22aa']['label'];
    }

    /**
     * The standard a scan's ruleset implies. The gate's AAA setting only
     * raises the target size floor; the report table is still Level A and AA,
     * because nothing here can support an AAA claim.
     */
    public static function standardForRuleset(string $ruleset): string
    {
        return str_starts_with($ruleset, 'wcag21') ? 'wcag21aa' : 'wcag22aa';
    }
}
