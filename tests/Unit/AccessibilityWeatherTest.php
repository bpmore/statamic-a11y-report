<?php

declare(strict_types=1);

use Bpmore\A11yReport\Weather\AccessibilityWeather;

/**
 * The weather decision, with no Site Weather and no database: the part of the
 * band that is this addon's own.
 */
function open(int $critical = 0, int $serious = 0, int $moderate = 0, int $minor = 0): array
{
    return ['critical' => $critical, 'serious' => $serious, 'moderate' => $moderate, 'minor' => $minor];
}

it('is clear when nothing is open', function () {
    expect(AccessibilityWeather::decide(open(), 120, [3, 0]))
        ->toBe(['state' => 'clear', 'headline' => 'No open issues across 120 pages']);
});

it('is fair when only moderate and minor issues are open', function () {
    $decision = AccessibilityWeather::decide(open(moderate: 4, minor: 8), 120, [12]);

    expect($decision['state'])->toBe('fair')
        ->and($decision['headline'])->toBe('12 open issues, none critical');
});

it('is overcast for a serious barrier with nothing critical', function () {
    expect(AccessibilityWeather::decide(open(serious: 1, minor: 2), 120, [3])['state'])->toBe('overcast');
});

it('rains for anything critical that is neither widespread nor rising', function () {
    $decision = AccessibilityWeather::decide(open(critical: 3, serious: 20), 120, [30, 23]);

    expect($decision['state'])->toBe('rain')
        ->and($decision['headline'])->toBe('23 open issues, 3 critical');
});

it('storms when critical issues reach a tenth of the pages scanned', function () {
    expect(AccessibilityWeather::decide(open(critical: 12), 120, [12])['state'])->toBe('storm')
        ->and(AccessibilityWeather::decide(open(critical: 11), 120, [11])['state'])->toBe('rain');
});

it('storms when critical issues are open and the total is rising', function () {
    $decision = AccessibilityWeather::decide(open(critical: 1, minor: 40), 500, [30, 35, 41]);

    expect($decision['state'])->toBe('storm')
        ->and($decision['headline'])->toBe('41 open issues, 1 critical, and rising');
});

it('does not read a single scan as a trend', function () {
    expect(AccessibilityWeather::decide(open(critical: 1), 500, [1])['state'])->toBe('rain')
        ->and(AccessibilityWeather::decide(open(critical: 1), 500, [])['state'])->toBe('rain');
});

it('says rising without storming when nothing critical is open', function () {
    $decision = AccessibilityWeather::decide(open(serious: 2, minor: 10), 500, [8, 12]);

    expect($decision['state'])->toBe('overcast')
        ->and($decision['headline'])->toBe('12 open issues, none critical, and rising');
});

it('counts in words that agree with their numbers', function () {
    expect(AccessibilityWeather::decide(open(minor: 1), 1, [1])['headline'])->toBe('1 open issue, none critical')
        ->and(AccessibilityWeather::decide(open(), 1, [])['headline'])->toBe('No open issues across 1 page')
        ->and(AccessibilityWeather::decide(open(critical: 1200, minor: 300), 4000, [1500])['headline'])->toBe('1,500 open issues, 1,200 critical');
});

it('does not divide by zero pages', function () {
    expect(AccessibilityWeather::decide(open(critical: 5), 0, [5])['state'])->toBe('rain');
});
