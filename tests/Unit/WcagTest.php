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

it('links every criterion to the W3C Understanding page for the standard, spelt the way the W3C spells it', function () {
    expect(Wcag::find('1.1.1')->slug())->toBe('non-text-content');
    expect(Wcag::find('1.2.1')->slug())->toBe('audio-only-and-video-only-prerecorded');
    expect(Wcag::find('3.3.4')->slug())->toBe('error-prevention-legal-financial-data');
    expect(Wcag::find('2.2.2')->slug())->toBe('pause-stop-hide');
    expect(Wcag::find('4.1.2')->slug())->toBe('name-role-value');

    expect(Wcag::urlFor('1.4.3', 'wcag22aa'))->toBe('https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html');
    expect(Wcag::urlFor('1.4.3', 'wcag21aa'))->toBe('https://www.w3.org/WAI/WCAG21/Understanding/contrast-minimum.html');
    expect(Wcag::specUrl('wcag21aa'))->toBe('https://www.w3.org/TR/WCAG21/');
    expect(Wcag::specUrl('wcag22aa'))->toBe('https://www.w3.org/TR/WCAG22/');

    foreach (Wcag::all() as $criterion) {
        expect($criterion->slug())->toMatch('/^[a-z0-9]+(-[a-z0-9]+)*$/', "{$criterion->number} has a clean slug");
    }
});

it('sends a criterion newer than the report standard to the version that has a page for it', function () {
    // 2.5.8 Target Size arrived in 2.2. A report set to 2.1 whose engine ran
    // the 2.2 ruleset can still cite it, and WCAG21 has no page to link to.
    expect(Wcag::urlFor('2.5.8', 'wcag21aa'))->toBe('https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html');
    // 4.1.1 Parsing left in 2.2 but its page remains, marked obsolete.
    expect(Wcag::urlFor('4.1.1', 'wcag22aa'))->toBe('https://www.w3.org/WAI/WCAG22/Understanding/parsing.html');
});

it('gives no link for a number the catalogue does not know, rather than guessing a URL', function () {
    expect(Wcag::urlFor('1.4.6', 'wcag22aa'))->toBeNull();
    expect(Wcag::urlFor('', 'wcag22aa'))->toBeNull();
    expect(Wcag::urlFor('Heading structure', 'wcag22aa'))->toBeNull();
});

it('describes every link a document for a standard can hold, for the PDF stamp', function () {
    $descriptions = Wcag::linkDescriptions('wcag22aa');

    expect($descriptions['https://www.w3.org/TR/WCAG22/'])->toBe('The WCAG 2.2 Recommendation at w3.org');
    expect($descriptions['https://www.w3.org/WAI/WCAG22/Understanding/non-text-content.html'])->toBe('Understanding success criterion 1.1.1 Non-text Content, at w3.org');

    foreach (Wcag::criteria('wcag22aa') as $criterion) {
        expect(array_key_exists($criterion->understandingUrl('2.2'), $descriptions))->toBeTrue("{$criterion->number} is described");
    }
});

// Opt in with A11Y_REPORT_CHECK_W3C=1: 105 requests to w3.org, thirteen
// seconds, and a dependency on the network that has no place in every run.
// Run it when a criterion is renamed or the W3C moves a page.
it('checks every derived Understanding URL against the W3C, when asked to', function () {
    if (getenv('A11Y_REPORT_CHECK_W3C') !== '1') {
        test()->markTestSkipped('Set A11Y_REPORT_CHECK_W3C=1 to check the derived URLs against w3.org.');
    }

    $missing = [];

    foreach (Wcag::STANDARDS as $standard => $meta) {
        foreach (Wcag::criteria($standard) as $criterion) {
            $url = $criterion->understandingUrl($meta['version']);
            $headers = @get_headers($url, true, stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => 10]]));
            $status = is_array($headers) ? (string) ($headers[0] ?? '') : '';

            // Rate limited is not missing. w3.org answered 429 to a second
            // run within minutes of the first; a skip says so, a failure
            // would say the URLs are wrong when they are not.
            if (str_contains($status, ' 429')) {
                test()->markTestSkipped("w3.org rate-limited this run at {$url}; try again later.");
            }

            if (! str_contains($status, ' 200')) {
                $missing[] = "{$url} ({$status})";
            }

            usleep(250_000);
        }
    }

    expect($missing)->toBe([], 'These derived URLs do not resolve: '.implode(', ', $missing));
})->group('network');
