<?php

declare(strict_types=1);

use Bpmore\A11yReport\Trends\TrendChart;

/**
 * The chart is a string, so it is tested by reading it. Boots nothing.
 */
function point(string $date, int $value): array
{
    return ['at' => new DateTimeImmutable($date), 'value' => $value, 'label' => (new DateTimeImmutable($date))->format('j M')];
}

/** @return array<int, float> */
function circleXs(string $svg): array
{
    preg_match_all('/<circle cx="([\d.]+)"/', $svg, $m);

    return array_map('floatval', $m[1]);
}

it('draws nothing for no scans, so the view can say so in words', function () {
    expect(TrendChart::render([]))->toBe('');
});

it('names itself and describes the data for anyone who cannot see it', function () {
    $svg = TrendChart::render([point('2026-08-01', 4), point('2026-08-15', 2), point('2026-09-01', 5)]);

    expect($svg)->toContain('role="img"');
    expect($svg)->toContain('aria-labelledby=');
    expect($svg)->toContain('<title id="a11y-trend-');
    expect($svg)->toContain('Issues found per scan, 1 Aug to 1 Sep');
    expect($svg)->toContain('3 completed scans. Lowest 2, highest 5, latest 5.');
});

it('puts one point per scan, each carrying its own value', function () {
    $svg = TrendChart::render([point('2026-08-01', 4), point('2026-08-15', 2), point('2026-09-01', 5)]);

    expect(circleXs($svg))->toHaveCount(3);
    expect($svg)->toContain('<title>1 Aug: 4 issues</title>');
    expect($svg)->toContain('<title>15 Aug: 2 issues</title>');
    expect($svg)->toContain('<title>1 Sep: 5 issues</title>');
    expect(substr_count($svg, '<path'))->toBe(2);
});

it('spaces points by time, not by scan number', function () {
    // Three scans: two a day apart, then one three weeks later. Drawn by
    // index the gaps would be equal, which would read as a steady cadence
    // the site did not have.
    $xs = circleXs(TrendChart::render([point('2026-08-01', 1), point('2026-08-02', 1), point('2026-08-23', 1)]));

    expect($xs[1] - $xs[0])->toBeLessThan(($xs[2] - $xs[1]) / 10);
});

it('keeps its aspect ratio so text and markers are not stretched', function () {
    $svg = TrendChart::render([point('2026-08-01', 4), point('2026-09-01', 7)]);

    expect($svg)->not->toContain('preserveAspectRatio="none"');
    expect($svg)->toContain('height:auto');
});

it('spaces scans within a day evenly and labels them with the time', function () {
    // Three scans in one afternoon. On a time axis two of them would sit on
    // top of each other at the right edge, which is what a screenshot of the
    // first version showed.
    $svg = TrendChart::render([
        ['at' => new DateTimeImmutable('2026-09-02 13:41'), 'value' => 93, 'label' => '2 Sep'],
        ['at' => new DateTimeImmutable('2026-09-02 13:42'), 'value' => 90, 'label' => '2 Sep'],
        ['at' => new DateTimeImmutable('2026-09-02 13:59'), 'value' => 94, 'label' => '2 Sep'],
    ]);

    $xs = circleXs($svg);
    expect(round($xs[1] - $xs[0]))->toBe(round($xs[2] - $xs[1]));
    expect($svg)->toContain('>2 Sep 13:41</text>');
    expect($svg)->toContain('>2 Sep 13:59</text>');
    expect($svg)->toContain('<title>2 Sep 13:42: 90 issues</title>');
});

it('puts the latest value label below its marker when the marker is at the top', function () {
    $svg = TrendChart::render([point('2026-08-01', 4), point('2026-09-01', 7)]);

    preg_match_all('/<circle cx="([\d.]+)" cy="([\d.]+)"/', $svg, $m);
    $lastY = (float) end($m[2]);
    preg_match('/<text x="[\d.]+" y="([\d.]+)" [^>]*font-weight="600"[^>]*>7<\/text>/', $svg, $t);

    expect((float) $t[1])->toBeGreaterThan($lastY, 'the label is below the marker, not on it');
});

it('labels the axes in text and the latest value directly, and colours nothing by hue', function () {
    $svg = TrendChart::render([point('2026-08-01', 4), point('2026-09-01', 7)]);

    expect($svg)->toContain('>7</text>');
    expect($svg)->toContain('>0</text>');
    expect($svg)->toContain('>1 Aug</text>');
    expect($svg)->toContain('>1 Sep</text>');
    expect(substr_count($svg, 'currentColor'))->toBeGreaterThan(3);
    expect(preg_match('/#[0-9a-f]{3,6}\b|rgb\(/i', $svg))->toBe(0);
});

it('handles a single scan without dividing by nothing', function () {
    $svg = TrendChart::render([point('2026-09-01', 3)]);

    expect(circleXs($svg))->toHaveCount(1);
    expect($svg)->toContain('1 completed scan.');
    expect(substr_count($svg, '<path'))->toBe(0);
});

it('is a bare line with one marker when drawn as a sparkline', function () {
    $svg = TrendChart::render([point('2026-08-01', 4), point('2026-08-15', 2), point('2026-09-01', 5)], 320, 56, sparkline: true);

    expect($svg)->not->toContain('<text');
    expect(circleXs($svg))->toHaveCount(1);
    expect($svg)->toContain('<title id=');
});

it('escapes what it prints', function () {
    $svg = TrendChart::render([['at' => new DateTimeImmutable('2026-09-01'), 'value' => 1, 'label' => '<b>x</b>']]);

    expect($svg)->not->toContain('<b>');
    expect($svg)->toContain('&lt;b&gt;x&lt;/b&gt;');
});
