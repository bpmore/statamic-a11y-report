<?php

declare(strict_types=1);

use Bpmore\A11yReport\Engine\AxeEngine;
use Bpmore\A11yReport\Engine\Finding;

/**
 * Turning an axe result into the shape everything downstream reads.
 *
 * No browser here: these are the rules about what a finding is allowed to
 * claim, and they are the same rules whether Chrome is installed or not.
 */
function violation(array $overrides = []): array
{
    return array_merge([
        'id' => 'image-alt',
        'impact' => 'critical',
        'help' => 'Images must have alternative text',
        'helpUrl' => 'https://dequeuniversity.com/rules/axe/4.11/image-alt',
        'tags' => ['cat.text-alternatives', 'wcag2a', 'wcag111', 'section508'],
    ], $overrides);
}

function node(array $overrides = []): array
{
    return array_merge([
        'target' => ['img.hero'],
        'html' => '<img class="hero" src="/a.jpg">',
        'impact' => 'critical',
        'summary' => 'Fix any of the following: Element has no alt attribute',
    ], $overrides);
}

it('labels a finding with the criterion its rule cites, and carries every one it cites', function () {
    $f = AxeEngine::finding(violation(['tags' => ['cat.name-role-value', 'wcag2a', 'wcag244', 'wcag412']]), node());

    expect($f->label)->toBe('WCAG 2.4.4');
    expect($f->criteria)->toBe(['2.4.4', '4.1.2']);
});

it('gives a rule that cites no criterion its own plain name and no borrowed one', function () {
    // axe's best-practice rules are house rules by the definition this product
    // already uses. A house rule that arrived in the report wearing a
    // criterion would be citing what the check cannot establish.
    $f = AxeEngine::finding(violation([
        'id' => 'heading-order',
        'impact' => 'moderate',
        'help' => 'Heading levels should only increase by one',
        'tags' => ['cat.semantics', 'best-practice'],
    ]), node());

    expect($f->label)->toBe('Heading order');
    expect($f->criteria)->toBe([]);
    expect($f->message)->toBe('Heading levels should only increase by one');
});

it('keeps the element, the markup and the advice, so a person can find and fix it', function () {
    $f = AxeEngine::finding(violation(), node());

    expect($f->ruleId)->toBe('image-alt');
    expect($f->selector)->toBe('img.hero');
    expect($f->target())->toBe('img.hero');
    expect($f->snippet)->toBe('<img class="hero" src="/a.jpg">');
    expect($f->remedy)->toContain('no alt attribute');
    expect($f->helpUrl)->toContain('image-alt');
    expect($f->pointer)->toBeNull();
});

it('keeps the frame an element was in, so two elements cannot share a fingerprint', function () {
    $f = AxeEngine::finding(violation(), node(['target' => [['iframe#one', 'img'], 'img']]));
    $g = AxeEngine::finding(violation(), node(['target' => [['iframe#two', 'img'], 'img']]));

    expect($f->target())->toBe('iframe#one >>> img >>> img');
    expect($f->target())->not->toBe($g->target());
});

it('takes the element\'s own grade over the rule\'s, and refuses a grade it does not know', function () {
    expect(AxeEngine::finding(violation(['impact' => 'critical']), node(['impact' => 'minor']))->impact)->toBe('minor');
    expect(AxeEngine::finding(violation(['impact' => 'serious']), node(['impact' => null]))->impact)->toBe('serious');

    // Never guessed upward: these numbers are what a deploy pipeline fails on.
    expect(AxeEngine::finding(violation(['impact' => 'catastrophic']), node(['impact' => 'urgent']))->impact)->toBe(Finding::MODERATE);
});

it('reports a category axe could not decide as partly covered, not as passed', function () {
    // The distinction the whole coverage idea exists for: a check that could
    // not tell must not look like a check that passed.
    $coverage = AxeEngine::coverage([
        'violations' => [['id' => 'image-alt', 'tags' => ['cat.text-alternatives']]],
        'incomplete' => [['id' => 'color-contrast', 'help' => 'Elements must meet minimum color contrast ratio thresholds', 'tags' => ['cat.color'], 'nodes' => 3]],
        'passes' => [['id' => 'html-has-lang', 'tags' => ['cat.language']]],
        'inapplicable' => [['id' => 'video-caption', 'tags' => ['cat.text-alternatives']]],
    ]);

    $byCheck = array_column($coverage, null, 'check');

    expect(array_keys($byCheck))->toBe(['cat.color', 'cat.language', 'cat.text-alternatives']);
    expect($byCheck['cat.color']['extent'])->toBe('partial');
    expect($byCheck['cat.color']['limit'])->toContain('could not decide');
    expect($byCheck['cat.color']['name'])->toBe('Color');

    // Nothing to look at is a complete answer, not a gap: a page with no
    // video is a full pass for the caption rule.
    expect($byCheck['cat.text-alternatives']['extent'])->toBe('full');
    expect($byCheck['cat.language']['extent'])->toBe('full');
    expect(AxeEngine::summary($coverage))->toBe('2 of 3 checks ran in full, 1 ran partly.');
});

it('spells ARIA as ARIA on the screen of an accessibility product', function () {
    $coverage = AxeEngine::coverage(['passes' => [['id' => 'aria-roles', 'tags' => ['cat.aria']]]]);

    expect($coverage[0]['name'])->toBe('ARIA');
});

it('says nothing rather than something reassuring when no check reported', function () {
    expect(AxeEngine::summary([]))->toBe('No check reported on this page.');
});
