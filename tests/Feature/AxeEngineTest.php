<?php

declare(strict_types=1);

use Bpmore\A11yReport\Chrome\Browser;
use Bpmore\A11yReport\Chrome\ChromeProtocolError;
use Bpmore\A11yReport\Chrome\PageNotReadable;
use Bpmore\A11yReport\Chrome\DevTools;
use Bpmore\A11yReport\Engine\Axe\AxeSource;
use Bpmore\A11yReport\Engine\AxeEngine;
use Bpmore\A11yReport\Engine\RenderedPage;

/**
 * axe-core in a real browser, against real pages over real HTTP.
 *
 * Served by PHP's own web server rather than read off disk, because two of
 * the things this engine must get right are only true over HTTP: a page that
 * answers 404 is not the page that was asked for, and a page that cannot be
 * reached is not a page with nothing wrong. Neither can be exercised through
 * a file: URL.
 *
 * Needs Chrome, and skips loudly without one, the same way the PDF tests do.
 */
function axeChrome(): ?array
{
    static $state = null;

    if ($state !== null) {
        return $state['engine'] === null ? null : $state;
    }

    $state = ['engine' => null, 'base' => null, 'server' => null];

    if (! (new Browser)->available()) {
        return null;
    }

    $root = __DIR__.'/../__fixtures__/axe';

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $port = random_int(20000, 60000);
        $process = proc_open(
            sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($root)),
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            continue;
        }

        $base = "http://127.0.0.1:{$port}";
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            if (@file_get_contents($base.'/clean.html') !== false) {
                $state = [
                    'engine' => new AxeEngine(new DevTools(new Browser, 30, 100), 'wcag22aa', true),
                    'base' => $base,
                    'server' => $process,
                ];

                return $state;
            }

            usleep(100_000);
        }

        proc_terminate($process, 9);
        proc_close($process);
    }

    return null;
}

function axeScan(string $page, ?AxeEngine $engine = null)
{
    $chrome = axeChrome();

    if ($chrome === null) {
        test()->markTestSkipped('No Chrome on this machine, or no port to serve the fixtures on.');
    }

    return ($engine ?? $chrome['engine'])->scan(new RenderedPage($chrome['base'].$page, ''));
}

afterAll(function () {
    $chrome = axeChrome();

    if ($chrome === null) {
        return;
    }

    $chrome['engine']->close();
    proc_terminate($chrome['server'], 9);
    proc_close($chrome['server']);
});

it('finds a contrast failure, which is the whole reason for a browser', function () {
    $result = axeScan('/contrast.html');

    $contrast = array_values(array_filter($result->findings, fn ($f) => $f->ruleId === 'color-contrast'));

    expect($contrast)->not->toBe([], 'axe found the low contrast text');
    expect($contrast[0]->criteria)->toContain('1.4.3');
    expect($contrast[0]->label)->toBe('WCAG 1.4.3');
    expect($contrast[0]->selector)->not->toBeNull();
    // The colours it measured are the evidence, and they only exist because a
    // browser computed them. Reading the markup gives none of this.
    expect($contrast[0]->remedy)->toContain('contrast');
})->group('chrome');

it('cites the criterion for a WCAG rule and keeps a house rule\'s own name', function () {
    $result = axeScan('/broken.html');
    $byRule = [];

    foreach ($result->findings as $finding) {
        $byRule[$finding->ruleId] = $finding;
    }

    expect($byRule)->toHaveKey('image-alt');
    expect($byRule['image-alt']->label)->toBe('WCAG 1.1.1');
    expect($byRule['image-alt']->criteria)->toBe(['1.1.1']);
    expect($byRule['image-alt']->impact)->toBe('critical');

    expect($byRule)->toHaveKey('button-name');
    expect($byRule['button-name']->criteria)->toContain('4.1.2');

    // axe's best-practice rules cite nothing, and must not arrive wearing a
    // criterion borrowed from the rule next to them.
    expect($byRule)->toHaveKey('heading-order');
    expect($byRule['heading-order']->criteria)->toBe([]);
    expect($byRule['heading-order']->label)->toBe('Heading order');
})->group('chrome');

it('leaves the house rules out when they are turned off', function () {
    $chrome = axeChrome();

    if ($chrome === null) {
        test()->markTestSkipped('No Chrome on this machine.');
    }

    $strict = new AxeEngine(new DevTools(new Browser, 30, 100), 'wcag22aa', false);

    try {
        $rules = array_map(fn ($f) => $f->ruleId, $strict->scan(new RenderedPage($chrome['base'].'/broken.html', ''))->findings);

        expect($rules)->toContain('image-alt');
        expect($rules)->not->toContain('heading-order');
    } finally {
        $strict->close();
    }
})->group('chrome');

it('reports a clean page as clean, and still says how much of it was decided', function () {
    $result = axeScan('/clean.html');

    expect($result->findings)->toBe([]);
    // A page with no findings and no coverage is indistinguishable from a page
    // nobody checked, which is the thing this product exists not to produce.
    expect($result->coverage)->not->toBe([]);
    expect($result->coverageSummary)->toContain('checks ran in full');

    foreach ($result->coverage as $entry) {
        expect($entry['extent'])->toBe('full');
        expect($entry['check'])->toStartWith('cat.');
        expect($entry['name'])->not->toBe('');
    }
})->group('chrome');

it('records a check it could not settle as partly covered rather than as a pass', function () {
    $result = axeScan('/undecided.html');

    $partial = array_values(array_filter($result->coverage, fn ($c) => $c['extent'] === 'partial'));

    expect($partial)->not->toBe([], 'the video with no captions is something axe cannot decide');
    expect($partial[0]['limit'])->toContain('could not decide');
    expect($result->coverageSummary)->toContain('ran partly');
})->group('chrome');

it('refuses a page that answered with something other than the page', function () {
    $chrome = axeChrome();

    if ($chrome === null) {
        test()->markTestSkipped('No Chrome on this machine.');
    }

    // A published entry whose route is broken serves the site's 404 page.
    // Scanning that would file the 404 page's problems against the entry, in a
    // document somebody hands to a regulator.
    expect(fn () => $chrome['engine']->scan(new RenderedPage($chrome['base'].'/no-such-page.html', '')))
        ->toThrow(PageNotReadable::class, 'answered 404');
})->group('chrome');

it('refuses a page it could not reach at all, rather than reporting it clean', function () {
    $chrome = axeChrome();

    if ($chrome === null) {
        test()->markTestSkipped('No Chrome on this machine.');
    }

    expect(fn () => $chrome['engine']->scan(new RenderedPage('http://a-host-that-does-not-exist.invalid/', '')))
        ->toThrow(PageNotReadable::class);
})->group('chrome');

it('carries on with the same browser after a page it refused', function () {
    $chrome = axeChrome();

    if ($chrome === null) {
        test()->markTestSkipped('No Chrome on this machine.');
    }

    $before = new ReflectionProperty(AxeEngine::class, 'chrome');
    $starts = $before->getValue($chrome['engine'])->starts();

    try {
        $chrome['engine']->scan(new RenderedPage($chrome['base'].'/no-such-page.html', ''));
    } catch (PageNotReadable) {
        // Expected. What matters is that the browser survived it.
    }

    // Kept, not thrown away and started again. A site with fifty broken routes
    // would otherwise pay fifty browser starts for information the first
    // attempt already had, and every one of them is about a second. Counted
    // rather than asked whether it is open, because a browser that was thrown
    // away and immediately restarted is open too.
    expect($before->getValue($chrome['engine'])->starts())->toBe($starts);

    // And the run carries on.
    expect(axeScan('/broken.html')->findings)->not->toBe([]);
})->group('chrome');

it('reports the version that actually ran, and the rules it actually ran', function () {
    $chrome = axeChrome();

    if ($chrome === null) {
        test()->markTestSkipped('No Chrome on this machine.');
    }

    $engine = $chrome['engine'];

    expect($engine->key())->toBe('axe');
    expect($engine->ruleset())->toBe('wcag22aa+best-practice');

    // The version on every scan row is the bundle's, so the bundle had better
    // be what ran. The engine refuses a result from any other axe, and this is
    // the check that the refusal is not permanently firing.
    $reported = axeScan('/clean.html');
    expect($reported->findings)->toBe([]);
    expect($engine->version())->toBe(AxeSource::version());
})->group('chrome');

it('derives the same rules a real axe reports, so an upgrade cannot drift quietly', function () {
    $chrome = axeChrome();

    if ($chrome === null) {
        test()->markTestSkipped('No Chrome on this machine.');
    }

    // The interface asks an engine which criteria it can cite and says the
    // list must be derived from the rules, never typed. It is derived by
    // reading the bundle. This is what keeps that reading honest: the browser
    // is asked the same question, and the two answers have to match.
    $chrome['engine']->scan(new RenderedPage($chrome['base'].'/clean.html', ''));

    $reflection = new ReflectionProperty(AxeEngine::class, 'chrome');
    $devtools = $reflection->getValue($chrome['engine']);

    $json = $devtools->evaluate('JSON.stringify(axe.getRules().map(function (r) { return { id: r.ruleId, tags: r.tags }; }))');
    $live = collect(json_decode((string) $json, true))->keyBy('id');
    $parsed = AxeSource::rules();

    expect(count($parsed))->toBe($live->count(), 'the bundle parses to the same number of rules the browser reports');

    foreach ($live as $id => $rule) {
        expect($parsed)->toHaveKey($id);
        expect(array_values(array_diff($rule['tags'], $parsed[$id])))->toBe([], "the tags parsed for {$id} are the tags it has");
    }

    // And therefore the criteria, which is the number the report prints.
    $fromBrowser = [];

    foreach ($live as $rule) {
        if (array_intersect($rule['tags'], AxeSource::tagsForStandard('wcag22aa')) !== []) {
            $fromBrowser = [...$fromBrowser, ...AxeSource::criteriaOfTags($rule['tags'])];
        }
    }

    $fromBrowser = array_values(array_unique($fromBrowser));
    usort($fromBrowser, 'version_compare');

    expect(AxeSource::criteriaFor(AxeSource::tagsForStandard('wcag22aa')))->toBe($fromBrowser);
})->group('chrome');

it('refuses a result from an axe that is not the one it ships', function () {
    $chrome = axeChrome();

    if ($chrome === null) {
        test()->markTestSkipped('No Chrome on this machine.');
    }

    // A site with its own copy of axe on the page wins: it is loaded after
    // the script this engine injects. Every scan row, and the Evaluation
    // methods section of every report, records the bundled version, so a
    // result produced by some other axe would be attributed to rules that did
    // not produce it. Refused, and counted as a page that could not be read.
    expect(fn () => $chrome['engine']->scan(new RenderedPage($chrome['base'].'/hijack.html', '')))
        ->toThrow(PageNotReadable::class, 'axe-core 1.0.0 ran on');
})->group('chrome');

it('blames the page, not the browser, when a page never finishes loading', function () {
    $chrome = axeChrome();

    if ($chrome === null) {
        test()->markTestSkipped('No Chrome on this machine.');
    }

    // A socket that accepts and answers nothing. The page's image never
    // arrives, so the load event never fires, which is the one kind of stuck
    // page a scan of a real site meets: a third-party script or an image on a
    // host that has gone away.
    $sink = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

    if ($sink === false) {
        test()->markTestSkipped('No port to hang a request on.');
    }

    $port = (int) explode(':', (string) stream_socket_get_name($sink, false))[1];

    // Its own engine, on a short deadline, so the test does not wait thirty
    // seconds for an answer it can have in two.
    $engine = new AxeEngine(new DevTools(new Browser, 2, 0), 'wcag22aa', true);
    $starts = new ReflectionProperty(AxeEngine::class, 'chrome');

    try {
        expect(fn () => $engine->scan(new RenderedPage($chrome['base']."/hang.php?port={$port}", '')))
            // Not a ChromeProtocolError. The browser is fine and this page is
            // not, and the two are told apart precisely so this one is not
            // retried: the read timeout used to arrive first and as the wrong
            // type, and every stuck page cost a browser thrown away and a
            // second wait for the same answer.
            ->toThrow(PageNotReadable::class, 'did not finish loading');

        // One browser, not two. The retry is for a browser that died, and this
        // is not one.
        expect($starts->getValue($engine)->starts())->toBe(1);
    } finally {
        $engine->close();
        fclose($sink);
    }
})->group('chrome');
