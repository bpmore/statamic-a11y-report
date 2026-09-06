<?php

declare(strict_types=1);

use Bpmore\A11yReport\Chrome\Browser;
use Bpmore\A11yReport\Engine\AxeEngine;
use Bpmore\A11yReport\Engine\Engines;
use Bpmore\A11yReport\Engine\PhpDomEngine;
use Bpmore\A11yReport\Engine\ScanEngine;
use Bpmore\A11yReport\Models\Issue;
use Bpmore\A11yReport\Models\IssueState;
use Bpmore\A11yReport\Models\Scan;
use Bpmore\A11yReport\Models\ScanPage;
use Bpmore\A11yReport\Scan\Scans;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Collection;

/**
 * Which engine a scan runs with, and what the scan row says about it.
 *
 * Two engines give two sets of answers, so the row has to carry which one ran,
 * at what version, and with which rules. The rule that matters most here is
 * the one about falling back: a scan that quietly ran the lesser engine would
 * be fine, because the row says so; a scan that silently reported the lesser
 * engine's results as the fuller one's would not.
 */
beforeEach(function () {
    $this->withStandardFakeViews();
    test()->viewShouldReturnRaw('default', PLAIN);
    Collection::make('pages')->routes('/{slug}')->save();
    app(ReportDatabase::class)->install();
});

it('builds the PHP checker by default, and names the rules it ran on the scan', function () {
    expect(app(ScanEngine::class))->toBeInstanceOf(PhpDomEngine::class);

    page('one', '<img src="/a.jpg">');
    $scan = runScan();

    expect($scan->engine)->toBe('php');
    expect($scan->ruleset)->toBe('wcag22aa');
    expect($scan->engine_version)->not->toBe('');
});

it('builds axe when it is asked for and Chrome is there', function () {
    if (! (new Browser)->available()) {
        test()->markTestSkipped('No Chrome on this machine.');
    }

    config()->set('statamic-a11y-report.engine', 'axe');

    $engine = app(ScanEngine::class);

    expect($engine)->toBeInstanceOf(AxeEngine::class);
    expect($engine->key())->toBe('axe');
    // The rules that ran, not the standard somebody asked for. Two scans that
    // ran different rules must not carry the same word for it.
    expect($engine->ruleset())->toBe('wcag22aa+best-practice');
    expect(count($engine->criteria()))->toBeGreaterThan(count(app(PhpDomEngine::class, [
        'checker' => new \Bpmore\A11yGate\Accessibility\StaticAccessibilityChecker,
        'version' => 'test',
    ])->criteria()));
});

it('runs the checker it has rather than failing every page when Chrome is missing', function () {
    Log::shouldReceive('warning')->once()->withArgs(fn ($m) => str_contains($m, 'needs Chrome'));

    config()->set('statamic-a11y-report.engine', 'axe');
    config()->set('statamic-a11y-report.chrome.binary', '/nowhere/at/all/chrome');

    expect(app(ScanEngine::class))->toBeInstanceOf(PhpDomEngine::class);

    page('one', '<img src="/a.jpg">');

    // Not a quiet swap. The row says php, and the report says which criteria
    // that engine can speak to, so a scan that fell back cannot be read as one
    // that did not.
    expect(runScan()->engine)->toBe('php');
});

it('says so and carries on when the config names an engine that does not exist', function () {
    Log::shouldReceive('warning')->once()->withArgs(fn ($m) => str_contains($m, 'there is no [pa11y] engine'));

    config()->set('statamic-a11y-report.engine', 'pa11y');

    expect(app(ScanEngine::class))->toBeInstanceOf(PhpDomEngine::class);
});

it('lets the command choose the engine, and refuses one that does not exist', function () {
    $this->artisan('statamic:a11y:scan', ['--engine' => 'pa11y', '--sync' => true])
        ->expectsOutputToContain('There is no [pa11y] engine')
        ->assertExitCode(1);

    expect(Scan::count())->toBe(0);

    // "axe" is a name it knows now, whether or not this machine can run it.
    config()->set('statamic-a11y-report.chrome.binary', '/nowhere/at/all/chrome');
    page('one', '<p>Fine.</p>');

    $this->artisan('statamic:a11y:scan', ['--engine' => 'axe', '--sync' => true])->assertExitCode(0);

    expect(Scan::count())->toBe(1);
});

it('tells the reader which engine the numbers came from, in its own name', function () {
    page('one', '<img src="/a.jpg">');
    $scan = runScan();

    // The engine key is a config value; the document names the thing itself,
    // because a reader of a conformance report should be able to look it up.
    $html = \Bpmore\A11yReport\Document\ReportWriter::html(
        app(\Bpmore\A11yReport\Document\ReportBuilder::class)->build($scan, 'tester'),
    );

    expect($html)->toContain('Accessibility Gate checker');
    expect($html)->toContain('cannot see anything a stylesheet decides');
});

it('does not let one engine call the other engine\'s findings fixed', function () {
    page('one', '<img src="/a.jpg">');
    runScan();

    $found = IssueState::first();
    expect($found->status)->toBe(IssueState::OPEN);
    expect($found->engine)->toBe('php');

    // The same page, read by the other engine, which looks for different
    // things and finds a different set. Built as rows rather than run, because
    // axe needs to reach the page over HTTP and the test harness serves
    // nothing; what is under test is the roll-up, and this is exactly the
    // state the roll-up meets.
    $axe = Scan::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'trigger' => Scan::TRIGGER_MANUAL,
        'status' => Scan::RUNNING,
        'engine' => 'axe',
        'engine_version' => '4.11.0',
        'ruleset' => 'wcag22aa+best-practice',
        'scope' => ['sites' => [], 'collections' => [], 'exclude_urls' => [], 'since' => null],
        'started_at' => now(),
    ]);

    $page = ScanPage::first();

    ScanPage::create([
        'scan_id' => $axe->id,
        'entry_id' => $page->entry_id,
        'site' => $page->site,
        'collection' => $page->collection,
        'url' => $page->url,
        'path' => $page->path,
        'status' => ScanPage::SCANNED,
        'issues_count' => 0,
        'coverage' => [['check' => 'cat.color', 'name' => 'Color', 'extent' => 'full', 'limit' => '', 'notice' => '']],
        'coverage_summary' => '1 of 1 checks ran in full.',
        'scanned_at' => now(),
    ]);

    app(Scans::class)->finalize($axe->id);

    // Not looking for something is not evidence that anybody fixed it. Before
    // this rule the first axe scan of a site marked every issue the checker
    // had ever found as fixed, and the report printed the number.
    expect(IssueState::find($found->fingerprint)->status)->toBe(IssueState::OPEN);

    // And the sentence the report prints compares like with like: there was no
    // earlier axe scan, so there is nothing to compare this one to.
    $axe->refresh();
    expect($axe->diff['previous_scan_id'])->toBeNull();
    expect($axe->diff['fixed'])->toBe(0);
});

it('reports what the engine that ran could cite, not what the one bound now can', function () {
    page('one', '<img src="/a.jpg">');
    $php = runScan();

    expect($php->criteria)->toContain('1.1.1');

    // A scan by the other engine, on a site running this one. Before the
    // criteria were recorded on the row, a report built from it said no
    // criterion had been evaluated automatically, of a scan that evaluated two
    // dozen, because it asked the engine that happened to be bound rather than
    // the one that ran.
    $axe = Scan::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'trigger' => Scan::TRIGGER_MANUAL,
        'status' => Scan::COMPLETE,
        'engine' => 'axe',
        'engine_version' => '4.11.0',
        'ruleset' => 'wcag22aa+best-practice',
        'criteria' => ['1.1.1', '1.4.3', '4.1.2'],
        'scope' => ['sites' => [], 'collections' => [], 'exclude_urls' => [], 'since' => null],
        'started_at' => now(),
        'finished_at' => now(),
        'pages_total' => 1,
        'pages_scanned' => 1,
        'issues_total' => 0,
        'issues_by_impact' => ['critical' => 0, 'serious' => 0, 'moderate' => 0, 'minor' => 0],
    ]);

    $page = ScanPage::first();

    ScanPage::create([
        'scan_id' => $axe->id,
        'entry_id' => $page->entry_id,
        'site' => $page->site,
        'collection' => $page->collection,
        'url' => $page->url,
        'path' => $page->path,
        'status' => ScanPage::SCANNED,
        'issues_count' => 0,
        'coverage' => [['check' => 'cat.color', 'name' => 'Color', 'extent' => 'full', 'limit' => '', 'notice' => '']],
        'coverage_summary' => '1 of 1 checks ran in full.',
        'scanned_at' => now(),
    ]);

    expect(app(ScanEngine::class)->key())->toBe('php');

    $data = app(\Bpmore\A11yReport\Document\ReportBuilder::class)->build($axe->fresh(), 'tester');

    expect($data['methods']['automated_criteria'])->toBe(['1.1.1', '1.4.3', '4.1.2']);
});

it('reads a queued page with the engine its own scan names, not the one the config names', function () {
    page('one', '<img src="/a.jpg">');
    runScan();
    $done = ScanPage::first();

    // The row a `--engine=axe` run without `--sync` leaves behind. The console
    // process chose the engine and queued the pages; the worker that reads
    // them is another process, and its config file still says php.
    $axe = Scan::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'trigger' => Scan::TRIGGER_MANUAL,
        'status' => Scan::RUNNING,
        'engine' => 'axe',
        'engine_version' => '4.11.0',
        'ruleset' => 'wcag22aa+best-practice',
        'criteria' => ['1.1.1', '1.4.3', '4.1.2'],
        'scope' => ['sites' => [], 'collections' => [], 'exclude_urls' => [], 'since' => null],
        'started_at' => now(),
        'pages_total' => 1,
    ]);

    $queued = ScanPage::create([
        'scan_id' => $axe->id,
        'entry_id' => $done->entry_id,
        'site' => $done->site,
        'collection' => $done->collection,
        'url' => $done->url,
        'path' => $done->path,
        'status' => ScanPage::PENDING,
    ]);

    config()->set('statamic-a11y-report.engine', 'php');
    config()->set('statamic-a11y-report.chrome.binary', '/nowhere/at/all/chrome');

    app(Scans::class)->scanPage($axe->id, $queued->id);

    // Not read at all, rather than read by the other engine. The page has an
    // image with no description, which the PHP checker would have found, so
    // an empty scan is the proof that it never ran: a row saying axe filled
    // with the checker's findings would have the report claim two dozen
    // criteria were evaluated of a scan that evaluated six.
    expect($queued->refresh()->status)->toBe(ScanPage::ERROR);
    expect($queued->error)->toContain('no Chrome was found');
    expect(Issue::where('scan_id', $axe->id)->count())->toBe(0);
});

it('builds the engine a scan row names even while the config names another', function () {
    if (! (new Browser)->available()) {
        test()->markTestSkipped('No Chrome on this machine.');
    }

    config()->set('statamic-a11y-report.engine', 'php');

    $engines = app(Engines::class);

    // Two different questions, and the whole of the fix is that they are not
    // answered by the same lookup: what a new scan should run with, and how to
    // rebuild the one a scan row already names.
    expect($engines->configured())->toBeInstanceOf(PhpDomEngine::class);
    expect($engines->make('axe'))->toBeInstanceOf(AxeEngine::class);

    // Held, so a scan of hundreds of pages starts one browser and not hundreds.
    expect($engines->make('axe'))->toBe($engines->make('axe'));
});

it('refuses to read a page for a scan run by an engine it does not have', function () {
    page('one', '<img src="/a.jpg">');
    runScan();
    $done = ScanPage::first();

    $gone = Scan::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'trigger' => Scan::TRIGGER_MANUAL,
        'status' => Scan::RUNNING,
        'engine' => 'pa11y',
        'engine_version' => '1.0.0',
        'ruleset' => 'wcag22aa',
        'scope' => ['sites' => [], 'collections' => [], 'exclude_urls' => [], 'since' => null],
        'started_at' => now(),
        'pages_total' => 1,
    ]);

    $queued = ScanPage::create([
        'scan_id' => $gone->id,
        'entry_id' => $done->entry_id,
        'site' => $done->site,
        'collection' => $done->collection,
        'url' => $done->url,
        'path' => $done->path,
        'status' => ScanPage::PENDING,
    ]);

    app(Scans::class)->scanPage($gone->id, $queued->id);

    expect($queued->refresh()->status)->toBe(ScanPage::ERROR);
    expect($queued->error)->toContain('pa11y');
    expect(Issue::where('scan_id', $gone->id)->count())->toBe(0);
});

it('lets the screen turn off the rules no criterion requires', function () {
    if (! (new Browser)->available()) {
        test()->markTestSkipped('No Chrome on this machine.');
    }

    config()->set('statamic-a11y-report.engine', 'axe');

    // The file says run them, which is the shipped default.
    expect(app(Engines::class)->configured()->ruleset())->toBe('wcag22aa+best-practice');

    saveSettings(['axe_best_practices' => false]);
    app()->forgetInstance(Engines::class);
    app()->forgetInstance(ScanEngine::class);

    $engine = app(Engines::class)->configured();

    // The screen wins, and the row says which way it ran, so two scans with
    // different numbers carry the difference on them.
    expect($engine->ruleset())->toBe('wcag22aa');

    // And it reaches nothing the report claims. The criteria an engine can
    // cite come from the standard's tags and never from this switch: turning
    // it off must not make a criterion look evaluated, or unevaluated, or
    // anything else it was not.
    app()->forgetInstance(Engines::class);
    config()->set('statamic-a11y-report.axe.best_practices', true);
    $with = app(Engines::class)->make('axe')->criteria();

    expect($engine->criteria())->toBe($with);
});
