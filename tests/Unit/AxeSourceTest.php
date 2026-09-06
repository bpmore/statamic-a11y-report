<?php

declare(strict_types=1);

use Bpmore\A11yReport\Engine\Axe\AxeSource;

/**
 * What can be read out of the bundled axe-core without starting a browser.
 *
 * The interface asks an engine which criteria it can cite and says why the
 * list must be derived rather than typed: a hand-kept list drifts, and the
 * drift shows up as a conformance table claiming an evaluation nobody ran.
 * These tests are what makes the derivation trustworthy; the one in
 * AxeEngineTest that compares this against a real `axe.getRules()` is what
 * makes it stay trustworthy across an upgrade.
 */
it('reads the version out of the bundle rather than being told it', function () {
    expect(AxeSource::version())->toMatch('/^\d+\.\d+\.\d+/');
});

it('finds every rule in the bundle, each one filed under a category', function () {
    $rules = AxeSource::rules();

    // A round number would be a coincidence; the point is that it parsed a
    // hundred-odd rules rather than two or none.
    expect(count($rules))->toBeGreaterThan(80);

    foreach ($rules as $id => $tags) {
        expect($tags)->not->toBe([], "{$id} has tags");
        expect(array_filter($tags, fn ($t) => str_starts_with($t, 'cat.')))->not->toBe([], "{$id} is filed under a category");
    }

    // Spot checks, so a parse that returned plausible rubbish is caught.
    expect($rules['color-contrast'] ?? [])->toContain('wcag143');
    expect($rules['image-alt'] ?? [])->toContain('wcag111');
    expect($rules['heading-order'] ?? [])->toContain('best-practice');
    expect($rules['heading-order'] ?? [])->not->toContain('wcag2a');
});

it('reads a criterion number the way WCAG numbers them, not the way they concatenate', function () {
    // The one that matters: 1.4.10 and 1.4.1 are different criteria, and
    // "wcag1410" read greedily is the wrong one in a conformance table.
    expect(AxeSource::criteriaOfTags(['wcag1410']))->toBe(['1.4.10']);
    expect(AxeSource::criteriaOfTags(['wcag141']))->toBe(['1.4.1']);
    expect(AxeSource::criteriaOfTags(['wcag258']))->toBe(['2.5.8']);
    expect(AxeSource::criteriaOfTags(['wcag2a', 'wcag111', 'cat.color', 'TTv5']))->toBe(['1.1.1']);
    // Level tags are not criteria, and neither is anything else in the list.
    expect(AxeSource::criteriaOfTags(['wcag2a', 'wcag2aa', 'best-practice', 'ACT', 'EN-9.1.1.1']))->toBe([]);
});

it('offers a Level A and AA rule set per WCAG version and never a AAA one', function () {
    $tags22 = AxeSource::tagsForStandard('wcag22aa');
    $tags21 = AxeSource::tagsForStandard('wcag21aa');

    expect($tags22)->toContain('wcag22aa');
    expect($tags21)->not->toContain('wcag22aa');

    foreach ([$tags21, $tags22] as $tags) {
        foreach ($tags as $tag) {
            expect(str_ends_with($tag, 'aaa'))->toBeFalse("{$tag} is not a AAA tag");
        }
    }

    // An unknown standard gets the current one rather than nothing, because
    // scanning with no rules at all would report a site as having no problems.
    expect(AxeSource::tagsForStandard('nonsense'))->toBe($tags22);
});

it('can cite several times as many criteria as reading markup can, and 2.2 more than 2.1', function () {
    $criteria = AxeSource::criteriaFor(AxeSource::tagsForStandard('wcag22aa'));

    expect(count($criteria))->toBeGreaterThan(15);
    expect($criteria)->toContain('1.4.3');   // colour contrast, the whole reason for a browser
    expect($criteria)->toContain('1.1.1');
    expect($criteria)->toContain('4.1.2');
    expect(count(AxeSource::criteriaFor(AxeSource::tagsForStandard('wcag21aa'))))
        ->toBeLessThan(count($criteria));

    // Sorted as versions, so 1.4.10 follows 1.4.4 rather than 1.4.1.
    $sorted = $criteria;
    usort($sorted, 'version_compare');
    expect($criteria)->toBe($sorted);
});

it('says so rather than scanning with nothing when the bundle is not there', function () {
    $real = AxeSource::path();
    $moved = $real.'.moved';

    rename($real, $moved);
    AxeSource::forget();

    try {
        expect(fn () => AxeSource::source())->toThrow(RuntimeException::class, 'axe-core is missing');
    } finally {
        rename($moved, $real);
        AxeSource::forget();
    }
});
