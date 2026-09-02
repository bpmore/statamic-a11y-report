<?php

declare(strict_types=1);

use Bpmore\A11yReport\Models\Issue;
use Bpmore\A11yReport\Models\IssueState;
use Bpmore\A11yReport\Models\Scan;
use Bpmore\A11yReport\Models\ScanPage;
use Bpmore\A11yReport\Scan\Scans;
use Bpmore\A11yReport\Scan\ScanScope;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Statamic\Facades\Collection;

/**
 * The scan, end to end, on the sync queue: enumerate, batch, read, roll up,
 * and track a problem from one scan to the next.
 */
beforeEach(function () {
    $this->withStandardFakeViews();
    test()->viewShouldReturnRaw('default', PLAIN);

    Collection::make('pages')->routes('/{slug}')->save();
    Collection::make('posts')->routes('/blog/{slug}')->save();

    app(ReportDatabase::class)->install();
});

it('reads every published page and keeps what it found, page by page', function () {
    test()->viewShouldReturnRaw('default', '<html lang="en"><body><h1>{{ title }}</h1>{{ body }}<a href="/x">Read more</a></body></html>');

    page('one', '<p>Fine.</p>');
    page('two', '<p>Fine.</p>');
    page('three', '<img src="/only-here.jpg">');
    page('draft', '<img src="/never.jpg">', published: false);

    $scan = runScan();

    expect($scan->status)->toBe(Scan::COMPLETE);
    expect($scan->engine)->toBe('php');
    expect($scan->ruleset)->toBe('wcag22aa');
    expect($scan->batch_id)->not->toBeNull();
    expect($scan->pages_total)->toBe(3);
    expect($scan->pages_scanned)->toBe(3);
    expect($scan->pages_errored)->toBe(0);
    expect($scan->started_at)->not->toBeNull();
    expect($scan->finished_at)->not->toBeNull();

    // One template link on three pages is three issues with three
    // fingerprints, because the fingerprint carries the page. Grouping across
    // pages is the report's job later; here every observation is kept.
    expect($scan->issues_total)->toBe(4);
    expect($scan->issues_by_impact)->toBe(['critical' => 0, 'serious' => 4, 'moderate' => 0, 'minor' => 0]);
    expect(Issue::where('rule_id', 'link-unclear')->count())->toBe(3);
    expect(Issue::where('rule_id', 'link-unclear')->pluck('fingerprint')->unique())->toHaveCount(3);

    $image = Issue::where('rule_id', 'image-missing-alt')->first();
    expect($image->label)->toBe('WCAG 1.1.1');
    expect($image->wcag_criteria)->toBe(['1.1.1']);
    expect($image->impact)->toBe('serious');
    expect($image->pointer)->toBe('/only-here.jpg');
    expect($image->remedy)->toBe('Add a description');
    expect($image->page->url)->toContain('three');

    // Every page says how much of it was seen. A page with zero issues and
    // no coverage would look exactly like a clean page.
    foreach (ScanPage::all() as $p) {
        expect($p->status)->toBe(ScanPage::SCANNED);
        expect($p->coverage)->not->toBe([]);
        expect($p->coverage_summary)->toContain('checks ran in full');
        expect($p->render_ms)->not->toBeNull();
    }

    // And every problem is now something that can be followed.
    expect(IssueState::count())->toBe(4);
    expect(IssueState::where('status', IssueState::OPEN)->count())->toBe(4);
    expect(IssueState::first()->first_seen_at)->not->toBeNull();
});

it('stores a house rule under its plain name with no criterion attached', function () {
    page('one', '<h3>Skipped</h3>');

    runScan();

    $issue = Issue::where('rule_id', 'heading-skipped-level')->first();

    expect($issue)->not->toBeNull();
    expect($issue->label)->toBe('Heading structure');
    expect($issue->wcag_criteria)->toBe([]);
});

it('counts the same problem three times on one page as one issue seen three times', function () {
    page('one', '<a href="/a">Read more</a><a href="/b">Read more</a><a href="/c">Read more</a>');

    $scan = runScan();

    expect($scan->issues_total)->toBe(1);
    expect(Issue::first()->occurrences)->toBe(3);
});

it('records a page that cannot be read and carries on with the rest', function () {
    // The template goes blank for one entry, which the renderer refuses to
    // read as a clean page. The other two are read, the scan completes, and
    // the broken page is counted on its own rather than as clean.
    test()->viewShouldReturnRaw('default', '{{ if slug == "broken" }}{{ else }}'.PLAIN.'{{ /if }}');

    page('one', '<p>Fine.</p>');
    page('broken', '<p>Fine.</p>');
    page('three', '<img src="/a.jpg">');

    $scan = runScan();

    expect($scan->status)->toBe(Scan::COMPLETE);
    expect($scan->pages_scanned)->toBe(2);
    expect($scan->pages_errored)->toBe(1);
    expect($scan->issues_total)->toBe(1);

    $broken = ScanPage::where('url', 'like', '%broken%')->first();
    expect($broken->status)->toBe(ScanPage::ERROR);
    expect($broken->error)->toContain('came back empty');
    expect($broken->coverage)->toBeNull();
});

it('fails the scan rather than completing it when no page at all could be read', function () {
    test()->viewShouldReturnRaw('default', '');

    page('one', '<p>Fine.</p>');

    $scan = runScan();

    expect($scan->status)->toBe(Scan::FAILED);
    expect($scan->pages_errored)->toBe(1);
    expect($scan->error)->toContain('no page could be read');
    expect(IssueState::count())->toBe(0);
});

it('finishes a scan of nothing instead of leaving it running', function () {
    $scan = runScan(collections: ['nothing-here']);

    expect($scan->status)->toBe(Scan::COMPLETE);
    expect($scan->pages_total)->toBe(0);
    expect($scan->batch_id)->toBeNull();
});

it('resumes with the pages the last batch never reached', function () {
    page('one', '<img src="/a.jpg">');
    page('two', '<img src="/b.jpg">');

    $scan = runScan();
    expect($scan->issues_total)->toBe(2);

    // A worker died after page one. Page two is still pending, the scan still
    // says running, and the batch it was in is gone.
    $two = ScanPage::where('url', 'like', '%two')->first();
    Issue::where('page_id', $two->id)->delete();
    $two->update(['status' => ScanPage::PENDING, 'issues_count' => 0, 'coverage' => null, 'scanned_at' => null]);
    $scan->update(['status' => Scan::RUNNING, 'finished_at' => null, 'issues_total' => 0]);
    $oldBatch = $scan->batch_id;

    $resumed = app(Scans::class)->resume($scan->fresh(), sync: true);

    expect($resumed->status)->toBe(Scan::COMPLETE);
    expect($resumed->batch_id)->not->toBe($oldBatch);
    expect($resumed->pages_scanned)->toBe(2);
    expect($resumed->issues_total)->toBe(2);
    expect(ScanPage::where('status', ScanPage::PENDING)->count())->toBe(0);
    // Page one was not read twice: the resumed batch held only page two.
    expect(Issue::count())->toBe(2);
});

it('leaves a page alone when a retried job finds it already read', function () {
    page('one', '<img src="/a.jpg">');

    $scan = runScan();
    $page = ScanPage::first();

    app(Scans::class)->scanPage($scan->id, $page->id);

    expect(Issue::count())->toBe(1);
    expect($page->fresh()->scanned_at->equalTo($page->scanned_at))->toBeTrue();
});

it('closes a problem that is gone from a page it read, and reopens it when it comes back', function () {
    page('one', '<img src="/a.jpg">');
    runScan();

    $fingerprint = Issue::first()->fingerprint;
    expect(IssueState::find($fingerprint)->status)->toBe(IssueState::OPEN);

    page('one', '<img src="/a.jpg" alt="A description">');
    $second = runScan();

    $state = IssueState::find($fingerprint);
    expect($state->status)->toBe(IssueState::FIXED);
    expect($state->resolved_at)->not->toBeNull();
    expect($second->diff)->toMatchArray(['new' => 0, 'fixed' => 1, 'unchanged' => 0]);
    expect($second->diff['previous_scan_id'])->not->toBeNull();

    page('one', '<img src="/a.jpg">');
    $third = runScan();

    $state = IssueState::find($fingerprint);
    expect($state->status)->toBe(IssueState::OPEN);
    expect($state->resolved_at)->toBeNull();
    expect($state->last_scan_id)->toBe($third->id);
    expect($third->diff)->toMatchArray(['new' => 1, 'fixed' => 0, 'unchanged' => 0]);
});

it('fingerprints a problem by site and path, with no domain in it', function () {
    // The rows from a scratch site read `http://localhost:8000/about`, and a
    // fingerprint built on that would make the same page on staging and on
    // production two different problems. The harness cannot change its site
    // URL mid-test, so the property is pinned directly: what was stored is
    // exactly what the site handle and the path alone produce.
    page('one', '<img src="/a.jpg">');
    runScan();

    $issue = Issue::first();
    $stored = ScanPage::first();

    expect($stored->url)->toStartWith('http');
    expect($stored->path)->toBe('/one');
    expect($issue->fingerprint)->toBe(\Bpmore\A11yReport\Engine\Fingerprint::for(
        'image-missing-alt',
        '/a.jpg',
        \Bpmore\A11yReport\Engine\Fingerprint::page('default', '/one'),
    ));
    expect(IssueState::first()->path)->toBe('/one');
    expect(IssueState::first()->site)->toBe('default');
});

it('does not close a problem on a page a scoped scan never read', function () {
    page('one', '<img src="/a.jpg">');
    page('post', '<img src="/b.jpg">', collection: 'posts');
    runScan();

    expect(IssueState::where('status', IssueState::OPEN)->count())->toBe(2);

    // Fix the page, then scan only the posts. The page's problem is gone in
    // reality and unknown to this scan, and unknown is not fixed.
    page('one', '<p>Fine.</p>');
    $scoped = runScan(collections: ['posts']);

    expect($scoped->pages_total)->toBe(1);
    expect(IssueState::where('status', IssueState::OPEN)->count())->toBe(2);
    expect($scoped->diff['fixed'])->toBe(0);

    // A full scan then sees it.
    runScan();
    expect(IssueState::where('status', IssueState::FIXED)->count())->toBe(1);
});

it('does not overrule a decision a person made', function () {
    page('one', '<img src="/a.jpg">');
    runScan();

    $fingerprint = Issue::first()->fingerprint;
    IssueState::find($fingerprint)->update(['status' => IssueState::WONT_FIX, 'note' => 'Decorative, agreed with the author.', 'updated_by' => 'somebody']);

    // Still on the page: stays won't fix. Gone from the page: still won't
    // fix. The scanner reports, it does not decide.
    runScan();
    expect(IssueState::find($fingerprint)->status)->toBe(IssueState::WONT_FIX);

    page('one', '<p>Fine.</p>');
    runScan();
    expect(IssueState::find($fingerprint)->status)->toBe(IssueState::WONT_FIX);
    expect(IssueState::find($fingerprint)->note)->toBe('Decorative, agreed with the author.');
});

it('narrows to a site, a collection, and a change date, and records the scope it used', function () {
    $old = ['updated_at' => now()->subDays(10)->timestamp];

    page('one', '<p>Fine.</p>', extra: $old);
    page('old', '<p>Fine.</p>', extra: $old);
    page('fresh', '<p>Fine.</p>', extra: ['updated_at' => now()->subHour()->timestamp]);
    page('post', '<p>Fine.</p>', collection: 'posts', extra: $old);
    // No timestamp and, in this harness, no file either: nothing says when it
    // changed. Kept, because the cheap mistake is reading a page that did not
    // change and the expensive one is skipping a page that did.
    page('unknown', '<p>Fine.</p>');

    expect(runScan()->pages_total)->toBe(5);
    expect(runScan(collections: ['posts'])->pages_total)->toBe(1);
    expect(runScan(sites: ['default'])->pages_total)->toBe(5);
    expect(runScan(sites: ['nowhere'])->pages_total)->toBe(0);

    $since = runScan(since: '2 days ago');
    expect($since->pages_total)->toBe(2);
    $urls = ScanPage::where('scan_id', $since->id)->pluck('url')->implode(' ');
    expect($urls)->toContain('fresh');
    expect($urls)->toContain('unknown');
    expect($urls)->not->toContain('old');
    expect($since->scope['since'])->not->toBeNull();

    $sited = runScan(sites: ['default']);
    expect($sited->site)->toBe('default');
    expect($sited->scope['sites'])->toBe(['default']);
    expect(runScan()->site)->toBeNull();
});

it('leaves out the URLs the config excludes', function () {
    config()->set('statamic-a11y-report.scan.exclude_urls', ['*/two']);

    page('one', '<p>Fine.</p>');
    page('two', '<img src="/a.jpg">');

    $scan = runScan();

    expect($scan->pages_total)->toBe(1);
    expect($scan->issues_total)->toBe(0);
});

it('runs as a command, exits zero under the thresholds, and non-zero above them', function () {
    page('one', '<img src="/a.jpg">');

    // One serious issue, limit of five: passes, and says what it cannot see.
    $this->artisan('statamic:a11y:scan', ['--sync' => true])
        ->expectsOutputToContain('Status: complete')
        ->expectsOutputToContain('1 serious')
        ->expectsOutputToContain('has not been proven accessible')
        ->assertExitCode(0);

    config()->set('statamic-a11y-report.ci.fail_above.serious', 0);

    $this->artisan('statamic:a11y:scan', ['--sync' => true])
        ->expectsOutputToContain('above the limit of 0')
        ->assertExitCode(1);
});

it('exits non-zero when a page could not be read, whatever the thresholds say', function () {
    test()->viewShouldReturnRaw('default', '{{ if slug == "broken" }}{{ else }}'.PLAIN.'{{ /if }}');

    page('one', '<p>Fine.</p>');
    page('broken', '<p>Fine.</p>');

    $this->artisan('statamic:a11y:scan', ['--sync' => true])
        ->expectsOutputToContain('1 pages could not be read')
        ->assertExitCode(1);
});

it('queues the scan by default and says how to pick it up', function () {
    // The default queue in the harness is sync, so the batch runs to
    // completion inside dispatch. What this pins is the path: no `--sync`
    // means the batch goes to the queue connection the site configured.
    page('one', '<p>Fine.</p>');

    $this->artisan('statamic:a11y:scan')->assertExitCode(0);

    $scan = Scan::first();
    expect($scan->trigger)->toBe(Scan::TRIGGER_MANUAL);
    expect($scan->status)->toBe(Scan::COMPLETE);
});

it('can be resumed from the command line by its id', function () {
    page('one', '<img src="/a.jpg">');
    $scan = runScan();

    ScanPage::first()->update(['status' => ScanPage::PENDING]);
    Issue::query()->delete();
    $scan->update(['status' => Scan::RUNNING, 'finished_at' => null]);

    $this->artisan('statamic:a11y:scan', ['--resume' => $scan->uuid, '--sync' => true])
        ->expectsOutputToContain('Resuming scan')
        ->expectsOutputToContain('Status: complete')
        ->assertExitCode(0);

    expect(Issue::count())->toBe(1);

    $this->artisan('statamic:a11y:scan', ['--resume' => 'not-a-scan'])
        ->expectsOutputToContain('No scan has the id')
        ->assertExitCode(1);
});

it('refuses an engine that does not exist rather than quietly using another', function () {
    $this->artisan('statamic:a11y:scan', ['--engine' => 'axe', '--sync' => true])
        ->expectsOutputToContain('There is no [axe] engine yet')
        ->assertExitCode(1);

    expect(Scan::count())->toBe(0);
});

it('creates its own tables when the connection is its own file, and asks otherwise', function () {
    // Drop the tables the harness installed so the command meets a bare
    // connection, as it would on a fresh site.
    foreach (['a11y_reports', 'a11y_criteria_assessments', 'a11y_issue_states', 'a11y_issues', 'a11y_scan_pages', 'a11y_scans'] as $table) {
        \Illuminate\Support\Facades\Schema::connection('a11y_testing')->dropIfExists($table);
    }

    // Somebody else's database: say so and stop.
    $this->artisan('statamic:a11y:scan', ['--sync' => true])
        ->expectsOutputToContain('Run: php please a11y:report:install')
        ->assertExitCode(1);

    // The addon's own file: just do it. The default connection name is
    // pointed at the in-memory database so nothing is written to disk.
    config()->set('database.connections.'.ReportDatabase::DEFAULT_CONNECTION, config('database.connections.a11y_testing'));
    config()->set('statamic-a11y-report.connection', ReportDatabase::DEFAULT_CONNECTION);

    $this->artisan('statamic:a11y:scan', ['--sync' => true])
        ->expectsOutputToContain('Report tables are in place')
        ->expectsOutputToContain('Status: complete')
        ->assertExitCode(0);
});

it('marks an issue on a page that is no longer served as page removed, not fixed, and reopens it if the page returns', function () {
    page('one', '<img src="/a.jpg">');
    page('two', '<img src="/b.jpg">');
    runScan();

    $gone = IssueState::where('path', '/one')->first();

    // Deleted: the page is not served, so a full scan does not meet it.
    removePage('one');
    $scan = runScan();

    $gone->refresh();
    expect($gone->status)->toBe(IssueState::PAGE_REMOVED);
    expect($gone->resolved_at)->not->toBeNull();
    expect($gone->last_scan_id)->toBe($scan->id);
    expect(IssueState::where('path', '/two')->first()->status)->toBe(IssueState::OPEN);
    expect(IssueState::where('status', IssueState::FIXED)->count())->toBe(0);

    // Back at the same address with the problem still there: so is the issue.
    page('one', '<img src="/a.jpg">');
    runScan();
    expect($gone->fresh()->status)->toBe(IssueState::OPEN);
    expect($gone->fresh()->resolved_at)->toBeNull();
});

it('marks a moved page as removed and its new address as new', function () {
    page('old-address', '<img src="/a.jpg">');
    $first = runScan();
    expect($first->issues_total)->toBe(1);

    removePage('old-address');
    page('new-address', '<img src="/a.jpg">');
    $second = runScan();

    expect(IssueState::where('path', '/old-address')->first()->status)->toBe(IssueState::PAGE_REMOVED);
    expect(IssueState::where('path', '/new-address')->first()->status)->toBe(IssueState::OPEN);
    expect($second->diff)->toMatchArray(['new' => 1, 'fixed' => 0, 'unchanged' => 0]);
});

it('does not call a page removed when the scan could not have met it', function () {
    page('one', '<img src="/a.jpg">');
    page('post', '<img src="/b.jpg">', collection: 'posts');
    page('printable', '<img src="/c.jpg">');
    runScan();

    removePage('one');
    removePage('post', 'posts');
    removePage('printable');

    // Narrowed by a change date: nothing unchanged is enumerated, so nothing is removed.
    runScan(since: '1 day ago');
    expect(IssueState::where('status', IssueState::PAGE_REMOVED)->count())->toBe(0);

    // Narrowed to one collection: only that collection's pages can be removed.
    runScan(collections: ['posts']);
    expect(IssueState::where('path', '/blog/post')->first()->status)->toBe(IssueState::PAGE_REMOVED);
    expect(IssueState::where('path', '/one')->first()->status)->toBe(IssueState::OPEN);

    // An excluded URL was left out on purpose, not removed.
    config()->set('statamic-a11y-report.scan.exclude_urls', ['*/printable']);
    runScan();
    expect(IssueState::where('path', '/one')->first()->status)->toBe(IssueState::PAGE_REMOVED);
    expect(IssueState::where('path', '/printable')->first()->status)->toBe(IssueState::OPEN);
});

it('does not call a page removed when it errored, and leaves a person\'s decision alone', function () {
    page('one', '<img src="/a.jpg">');
    page('broken', '<img src="/b.jpg">');
    runScan();

    IssueState::where('path', '/one')->update(['status' => IssueState::WONT_FIX]);
    removePage('one');

    test()->viewShouldReturnRaw('default', '{{ if slug == "broken" }}{{ else }}'.PLAIN.'{{ /if }}');
    $scan = runScan();

    expect($scan->pages_errored)->toBe(1);
    expect(IssueState::where('path', '/broken')->first()->status)->toBe(IssueState::OPEN);
    expect(IssueState::where('path', '/one')->first()->status)->toBe(IssueState::WONT_FIX);
});
