<?php

declare(strict_types=1);

use Bpmore\A11yGate\Accessibility\Remediation;
use Bpmore\A11yGate\Accessibility\StaticAccessibilityChecker;
use Bpmore\A11yGate\Accessibility\Violation;
use Bpmore\A11yReport\Engine\Finding;
use Bpmore\A11yReport\Engine\PhpDomEngine;
use Bpmore\A11yReport\Engine\RenderedPage;

/**
 * The engine that wraps the gate's checker. Boots nothing: the checker takes
 * HTML and the engine takes the checker.
 */
function engine(): PhpDomEngine
{
    return new PhpDomEngine(new StaticAccessibilityChecker, '1.2.3');
}

it('runs the same checker the gate runs and keeps the coverage with the findings', function () {
    $result = engine()->scan(new RenderedPage(
        'https://example.test/page',
        '<html lang="en"><body><h1>Title</h1><img src="/a.jpg"></body></html>',
    ));

    expect($result->findings)->toHaveCount(1);
    expect($result->findings[0]->ruleId)->toBe('image-missing-alt');
    expect($result->findings[0]->pointer)->toBe('/a.jpg');
    expect($result->coverage)->not->toBe([]);
    expect($result->coverageSummary)->toContain('checks ran in full');
    expect(engine()->key())->toBe('php');
    expect(engine()->version())->toBe('1.2.3');
});

it('parses a criterion out of a WCAG label and nothing out of a house rule', function () {
    expect(PhpDomEngine::criteria_of('WCAG 2.4.4'))->toBe(['2.4.4']);
    expect(PhpDomEngine::criteria_of('WCAG 1.1.1'))->toBe(['1.1.1']);
    expect(PhpDomEngine::criteria_of('Heading structure'))->toBe([]);
    expect(PhpDomEngine::criteria_of('Link check'))->toBe([]);
    expect(PhpDomEngine::criteria_of('Reading level (guide)'))->toBe([]);
});

it('never invents a criterion for any rule in the table', function () {
    // The table is the contract: a label that is not "WCAG x.y.z" is a house
    // rule precisely because no criterion covers it, and the report must not
    // promote it to one on the way into the database.
    expect(Remediation::RULES)->not->toBe([]);

    foreach (Remediation::RULES as $rule => $r) {
        $finding = PhpDomEngine::finding(Remediation::violation($rule, 'x'));

        expect($finding->label)->toBe($r['wcag']);

        $expected = preg_match('/^WCAG (\d+\.\d+\.\d+)$/', $r['wcag'], $m) ? [$m[1]] : [];
        expect($finding->criteria)->toBe($expected, "rule {$rule} cites {$r['wcag']}");
    }
});

it('maps a refusal to serious and a warning to moderate, and nothing to critical', function () {
    // The checker has no impact scale of its own, so this is a translation,
    // and it is pinned so it cannot drift into a claim.
    $error = new Violation('image-missing-alt', Violation::ERROR, 'WCAG 1.1.1', 'm', 'c', '/a.jpg');
    $warning = new Violation('link-vague', Violation::WARN, 'WCAG 2.4.4', 'm', 'c', 'more');

    expect(PhpDomEngine::finding($error)->impact)->toBe(Finding::SERIOUS);
    expect(PhpDomEngine::finding($warning)->impact)->toBe(Finding::MODERATE);

    $impacts = array_map(
        fn (string $rule) => PhpDomEngine::finding(Remediation::violation($rule))->impact,
        array_keys(Remediation::RULES),
    );

    expect(in_array(Finding::CRITICAL, $impacts, true))->toBeFalse();
});

it('carries the remedy and drops an empty pointer rather than storing a blank', function () {
    $finding = PhpDomEngine::finding(Remediation::violation('heading-missing-h1'));

    expect($finding->remedy)->toBe('Add a heading');
    expect($finding->pointer)->toBeNull();
    expect($finding->selector)->toBeNull();
    expect($finding->target())->toBe('');
});

it('reports the criteria its rules can cite, derived from the table and nothing else', function () {
    $criteria = engine()->criteria();

    foreach (['1.1.1', '1.2.1', '1.2.2', '2.4.4', '2.5.8', '4.1.2'] as $expected) {
        expect(in_array($expected, $criteria, true))->toBeTrue("the engine cites {$expected}");
    }

    $fromTable = [];
    foreach (Remediation::RULES as $r) {
        foreach (PhpDomEngine::criteria_of($r['wcag']) as $n) {
            $fromTable[$n] = $n;
        }
    }

    expect(count($criteria))->toBe(count($fromTable));
    expect(in_array('1.4.3', $criteria, true))->toBeFalse('contrast is not something this engine can see');
});
