<?php

declare(strict_types=1);

use Bpmore\A11yReport\Readability\Sentence;

/**
 * One sentence per state of the reading-level record, so the overview, the
 * history and the command never disagree about what a scan found.
 */
function record(array $pages, ?array $median): array
{
    return [
        'enabled' => true,
        'target' => ['grade' => 8, 'tolerance' => 1, 'low' => 7, 'high' => 9, 'label' => 'Grade 7–9'],
        'pages' => array_merge(['graded' => 0, 'above' => 0, 'on_target' => 0, 'below' => 0, 'refused' => 0, 'nothing' => 0, 'failed' => 0], $pages),
        'median' => $median,
    ];
}

it('says the median against the target and how many pages sit above it', function () {
    $median = ['grade' => 9.4, 'low' => 9, 'high' => 10, 'label' => 'Grade 9–10', 'comparison' => 'above'];

    expect(Sentence::forScan(record(['graded' => 40, 'above' => 12, 'on_target' => 20, 'below' => 8], $median)))
        ->toBe('The median page reads at Grade 9–10, above the target of Grade 7–9. 12 of 40 pages graded are above it.');

    expect(Sentence::forScan(record(['graded' => 3, 'above' => 1, 'on_target' => 2], $median)))
        ->toBe('The median page reads at Grade 9–10, above the target of Grade 7–9. 1 of 3 pages graded is above it.');

    $within = ['grade' => 8.0, 'low' => 8, 'high' => 9, 'label' => 'Grade 8–9', 'comparison' => 'on_target'];
    expect(Sentence::forScan(record(['graded' => 1, 'on_target' => 1], $within)))
        ->toBe('The median page reads at Grade 8–9, within the target of Grade 7–9. The one page graded is not above it.');
    expect(Sentence::forScan(record(['graded' => 5, 'on_target' => 5], $within)))
        ->toBe('The median page reads at Grade 8–9, within the target of Grade 7–9. None of the 5 pages graded is above it.');

    expect(Sentence::forHistory(record(['graded' => 40, 'above' => 12], $median)))->toBe('Grade 9–10, 12 above target');
});

it('counts the pages that got no grade, by why', function () {
    $median = ['grade' => 9.4, 'low' => 9, 'high' => 10, 'label' => 'Grade 9–10', 'comparison' => 'above'];

    expect(Sentence::forScan(record(['graded' => 2, 'above' => 2, 'refused' => 3, 'nothing' => 1, 'failed' => 1], $median)))
        ->toEndWith('Not graded: 3 pages in a language the formulas do not cover, 1 page with nothing to grade, 1 page the grader could not read.');
});

it('says why no page was graded, when every page had the same reason', function () {
    expect(Sentence::forScan(record(['refused' => 4], null)))
        ->toBe('No page was graded: every page read is on a site whose language the formulas were not calibrated on.');
    expect(Sentence::forScan(record(['nothing' => 2], null)))
        ->toStartWith('No page was graded: no page read had prose left');
    expect(Sentence::forScan(record(['refused' => 1, 'nothing' => 1], null)))
        ->toBe('No page was graded. Not graded: 1 page in a language the formulas do not cover, 1 page with nothing to grade.');
    expect(Sentence::forScan(record([], null)))->toBe('No page was graded.');
    expect(Sentence::forHistory(record(['refused' => 4], null)))->toBe('no page graded');
});

it('says when the dimension was switched off, and nothing for a scan from before it existed', function () {
    expect(Sentence::forScan(['enabled' => false]))->toBe('Not measured: the reading-level dimension was switched off for this scan.');
    expect(Sentence::forHistory(['enabled' => false]))->toBe('not measured');
    expect(Sentence::forHistory(null))->toBe('');
});
