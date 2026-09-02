<?php

declare(strict_types=1);

use Bpmore\A11yReport\Document\Criterion;
use Bpmore\A11yReport\Document\Wcag;

it('lists 50 Level A and AA criteria for WCAG 2.1 and 55 for 2.2', function () {
    expect(Wcag::criteria('wcag21aa'))->toHaveCount(50);
    expect(Wcag::criteria('wcag22aa'))->toHaveCount(55);
});

it('keeps Parsing in 2.1 and drops it from 2.2, and adds the six 2.2 criteria', function () {
    $numbers = fn (string $standard) => array_map(fn (Criterion $c) => $c->number, Wcag::criteria($standard));

    expect(in_array('4.1.1', $numbers('wcag21aa'), true))->toBeTrue();
    expect(in_array('4.1.1', $numbers('wcag22aa'), true))->toBeFalse();

    foreach (['2.4.11', '2.5.7', '2.5.8', '3.2.6', '3.3.7', '3.3.8'] as $added) {
        expect(in_array($added, $numbers('wcag22aa'), true))->toBeTrue("2.2 lists {$added}");
        expect(in_array($added, $numbers('wcag21aa'), true))->toBeFalse("2.1 does not list {$added}");
    }
});

it('lists no AAA criterion, because nothing here can support an AAA claim', function () {
    foreach (Wcag::all() as $criterion) {
        expect(in_array($criterion->level, ['A', 'AA'], true))->toBeTrue("{$criterion->number} is {$criterion->level}");
    }
});

it('is in ascending order with no duplicates', function () {
    $numbers = array_map(fn (Criterion $c) => $c->number, Wcag::all());
    $sorted = $numbers;
    usort($sorted, 'version_compare');

    expect($numbers)->toBe($sorted);
    expect(array_unique($numbers))->toHaveCount(count($numbers));
});

it('finds a criterion by number and follows the scan ruleset to a standard', function () {
    expect(Wcag::find('1.4.3')?->name)->toBe('Contrast (Minimum)');
    expect(Wcag::find('9.9.9'))->toBeNull();
    expect(Wcag::standardForRuleset('wcag22aa'))->toBe('wcag22aa');
    expect(Wcag::standardForRuleset('wcag22aaa'))->toBe('wcag22aa');
    expect(Wcag::standardForRuleset('wcag21aa'))->toBe('wcag21aa');
    expect(Wcag::label('wcag21aa'))->toBe('WCAG 2.1 Level AA');
});
