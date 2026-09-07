<?php

declare(strict_types=1);

use Bpmore\A11yReport\Models\IssueState;
use Bpmore\A11yReport\Models\Scan;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Statamic\Facades\Collection;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * The report's page under Tools: what is open, the last scan, the trend, the
 * history, and the button that starts a scan.
 */
beforeEach(function () {
    $this->withStandardFakeViews();
    test()->viewShouldReturnRaw('default', PLAIN);
    Collection::make('pages')->routes('/{slug}')->save();

    $this->user = User::make()->email('super@example.test')->makeSuper();
    $this->user->save();
});

function reportPage(?string $query = null): string
{
    return pageText(test()->actingAs(test()->user)->get(cp_route('utilities.index').'/a11y-report'.($query ? '?'.$query : ''))->assertOk()->getContent());
}

it('is reachable under Tools and offers the first scan when the database is its own', function () {
    ownConnection();

    $text = reportPage();

    expect($text)->toContain('Not set up yet');
    expect($text)->toContain('Run the first scan');
});

it('asks for the install command when the tables would go in somebody else\'s database', function () {
    $text = reportPage();

    expect($text)->toContain('Not set up yet');
    expect($text)->toContain('php please a11y:report:install');
    expect($text)->not->toContain('Run the first scan');
});

it('shows what is open, the last scan, the trend and the history once scans exist', function () {
    app(ReportDatabase::class)->install();

    page('one', '<img src="/a.jpg">');
    page('two', '<a href="#">Somewhere</a>');
    runScan();
    runScan();

    $text = reportPage();

    expect($text)->toContain('Open now');
    expect($text)->toContain('1 serious');
    expect($text)->toContain('1 moderate');
    expect($text)->toContain('0 critical');
    expect($text)->toContain('Last scan');
    expect($text)->toContain('Pages read');
    expect($text)->toContain('2 of 2');
    expect($text)->toContain('0 new, 0 fixed, 2 unchanged');
    expect($text)->toContain('<svg');
    expect($text)->toContain('Issues found per scan');
    expect($text)->toContain('Scan history');
    expect($text)->toContain('first scan');
    expect(substr_count($text, '<ui-table-row>'))->toBe(2);
});

it('never calls anything compliant or certified, and says what a clean scan proves', function () {
    app(ReportDatabase::class)->install();
    page('one', '<p>Fine.</p>');
    runScan();

    $text = strtolower(reportPage());

    expect(str_contains($text, 'compliant'))->toBeFalse('the report never uses the word compliant');
    expect(str_contains($text, 'certif'))->toBeFalse('the report never uses the word certified');
    expect(str_contains($text, 'has not proven the site accessible'))->toBeTrue('a clean scan must be said to prove nothing');
});

it('keeps a brace in the data from breaking the page', function () {
    // The page is compiled by Vue, and `{{` in a value would be read as an
    // interpolation and blank the whole page with no server-side error.
    app(ReportDatabase::class)->install();
    page('one', '<p>Fine.</p>');
    $scan = runScan();
    $scan->update(['status' => Scan::FAILED, 'error' => 'template said {{ boom }}']);

    // Raw, not decoded: what matters is what Vue will see once Inertia has
    // unpacked the page, which is the entity, not the brace.
    $html = test()->actingAs(test()->user)->get(cp_route('utilities.index').'/a11y-report')->getContent();

    expect($html)->toContain('&amp;#123;&amp;#123; boom &amp;#125;&amp;#125;');
    expect($html)->not->toContain('{{ boom }}');
    expect(pageText($html))->toContain('template said');
});

it('renders markup Vue can compile, before and after scans', function () {
    // Two renders: the "not set up" branch and the full page with every
    // panel, a failed scan with an error, and a history table.
    ownConnection();
    assertVueTemplateIsWellFormed(utilityHtml());

    app(ReportDatabase::class)->install();
    page('one', '<img src="/a.jpg">');
    page('two', '<a href="#">Somewhere</a>');
    runScan();
    runScan()->update(['status' => Scan::FAILED, 'error' => 'template said {{ boom }} & more']);

    assertVueTemplateIsWellFormed(utilityHtml());
});

it('has no directive glued to a word in any view', function () {
    assertNoGluedBladeDirectives();
});

it('starts a scan from the button and records who pressed it', function () {
    app(ReportDatabase::class)->install();
    page('one', '<img src="/a.jpg">');

    $this->actingAs($this->user)
        ->post(cp_route('utilities.a11y-report.run'))
        ->assertRedirect()
        ->assertSessionHas('success');

    $scan = Scan::first();
    expect($scan->trigger)->toBe(Scan::TRIGGER_MANUAL);
    expect($scan->initiated_by)->toBe('super@example.test');
    // The harness queue is sync, so the whole scan ran inside the request.
    expect($scan->status)->toBe(Scan::COMPLETE);
    expect($scan->issues_total)->toBe(1);
});

it('creates its own tables the first time the button is pressed', function () {
    ownConnection();
    page('one', '<p>Fine.</p>');

    $this->actingAs($this->user)->post(cp_route('utilities.a11y-report.run'))->assertSessionHas('success');

    expect(app(ReportDatabase::class)->isInstalled())->toBeTrue();
    expect(Scan::first()->status)->toBe(Scan::COMPLETE);
});

it('shows the report but not the button to somebody who may not run a scan', function () {
    app(ReportDatabase::class)->install();

    Role::make('reader')->permissions(['access cp', 'access a11y-report utility'])->save();
    $reader = User::make()->email('reader@example.test')->assignRole('reader');
    $reader->save();

    $text = pageText($this->actingAs($reader)->get(cp_route('utilities.index').'/a11y-report')->assertOk()->getContent());
    expect($text)->not->toContain('Run a scan now');
    expect($text)->not->toContain('Run the first scan');

    $this->actingAs($reader)->post(cp_route('utilities.a11y-report.run'))->assertForbidden();
    expect(Scan::count())->toBe(0);
});

it('scopes the button to a site when one is selected', function () {
    app(ReportDatabase::class)->install();
    page('one', '<p>Fine.</p>');

    $this->actingAs($this->user)->post(cp_route('utilities.a11y-report.run'), ['site' => 'default']);

    expect(Scan::first()->site)->toBe('default');
    expect(Scan::first()->scope['sites'])->toBe(['default']);
});

it('records the impact on the open issue, which is what the overview counts', function () {
    app(ReportDatabase::class)->install();
    page('one', '<img src="/a.jpg">');
    runScan();

    expect(IssueState::first()->impact)->toBe('serious');
});

it('says when a scan is not moving, and what to run', function () {
    app(ReportDatabase::class)->install();
    page('one', '<p>Fine.</p>');
    $scan = runScan();

    // A scan that finished is never stale, whatever its age.
    $scan->update(['started_at' => now()->subHours(3), 'finished_at' => now()->subHours(3)]);
    expect(reportPage())->not->toContain('This scan is not moving');

    // Queued twenty minutes ago and nothing read: stale, with the cure.
    $scan->update(['status' => Scan::QUEUED, 'finished_at' => null, 'started_at' => null, 'created_at' => now()->subMinutes(20)]);
    \Bpmore\A11yReport\Models\ScanPage::where('scan_id', $scan->id)->update(['status' => 'pending', 'scanned_at' => null]);
    $text = reportPage();
    expect($text)->toContain('This scan is not moving');
    expect($text)->toContain('has been <strong>queued</strong> for 20 minutes');
    expect($text)->toContain('0 of 1 pages read and nothing read at all');
    expect($text)->toContain('php artisan queue:work sync');
    expect($text)->toContain('php please a11y:scan --resume='.$scan->uuid.' --sync');

    // Running with a page read two minutes ago: moving, so no warning.
    $scan->update(['status' => Scan::RUNNING, 'started_at' => now()->subMinutes(20)]);
    \Bpmore\A11yReport\Models\ScanPage::where('scan_id', $scan->id)->update(['status' => 'scanned', 'scanned_at' => now()->subMinutes(2)]);
    expect(reportPage())->not->toContain('This scan is not moving');

    // And the wait is configurable.
    config()->set('statamic-a11y-report.scan.stale_after_minutes', 1);
    expect(reportPage())->toContain('has been <strong>running</strong> for 2 minutes');
});

it('heads the chart with what it draws, not the window it searched', function () {
    app(ReportDatabase::class)->install();
    page('one', '<img src="/a.jpg">');
    runScan();
    runScan();

    $text = reportPage();

    // "Issues found, last 90 days" named the window the scans were looked for
    // in, not the span the chart draws. Every scan on a young install happens
    // in one afternoon, so it read as an axis running three months over an
    // axis running five hours, and a chart that has to be argued with takes
    // the rest of the page down with it.
    expect($text)->toContain('Issues found per scan');
    expect($text)->not->toContain('Issues found, last 90 days');

    // The window still gets said, underneath, where it is a fact about what
    // was included rather than a claim about the axis.
    expect($text)->toContain('Scans from the last 90 days are shown');

    // And the description carries the span actually drawn.
    expect($text)->toMatch('/at the time it finished, \d+ \w+ \d\d:\d\d to \d\d:\d\d\./');
});

it('does not say a site has no pages when the scan has not listed them yet', function () {
    app(ReportDatabase::class)->install();

    // What the control panel's button leaves behind where no worker is
    // running: listing the pages is itself a queued job, so the scan has
    // none and `pages_total` is zero.
    $scan = Scan::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'trigger' => Scan::TRIGGER_MANUAL,
        'status' => Scan::QUEUED,
        'engine' => 'php',
        'engine_version' => 'test',
        'ruleset' => 'wcag22aa',
        'scope' => ['sites' => [], 'collections' => [], 'exclude_urls' => [], 'since' => null],
        'created_at' => now()->subMinutes(20),
    ]);

    $text = reportPage();

    expect($text)->toContain('This scan is not moving');
    // "0 of 0 pages read" reads as a site with nothing on it, which is a
    // different problem with a different cure, and it sent the first reader
    // of this screen looking for the wrong one.
    expect($text)->toContain('has not listed the pages to read yet');
    expect($text)->not->toContain('0 of 0 pages read');
});

it('warns that cron may not find the php the crontab line names', function () {
    app(ReportDatabase::class)->install();
    page('one', '<p>Fine.</p>');
    $scan = runScan();

    $scan->update(['status' => Scan::QUEUED, 'finished_at' => null, 'started_at' => null, 'created_at' => now()->subMinutes(20)]);
    \Bpmore\A11yReport\Models\ScanPage::where('scan_id', $scan->id)->update(['status' => 'pending', 'scanned_at' => null]);

    $text = reportPage();

    // The line every guide prints, and the reason it is wrong more often than
    // it is right: cron runs with almost no environment, and on Herd, Valet,
    // Homebrew or a version manager `php` is not on the PATH it gets. The
    // line then fails silently for ever, and a schedule that never runs looks
    // exactly like one nobody set up.
    expect($text)->toContain('php artisan schedule:run');
    expect($text)->toContain('which php');
    expect($text)->toContain("does not use your shell's");
});

it('keeps saying a scan is stuck after a later one has finished', function () {
    app(ReportDatabase::class)->install();
    page('one', '<p>Fine.</p>');

    // Wedged half an hour ago, on a queue nobody is working.
    $stuck = runScan();
    $stuck->update(['status' => Scan::RUNNING, 'finished_at' => null, 'created_at' => now()->subMinutes(30), 'started_at' => now()->subMinutes(30)]);
    \Bpmore\A11yReport\Models\ScanPage::where('scan_id', $stuck->id)->update(['status' => 'pending', 'scanned_at' => null]);

    expect(reportPage())->toContain('php please a11y:scan --resume='.$stuck->uuid.' --sync');

    // Somebody runs one by hand, and it finishes. Nothing about the wedged
    // scan has changed: a scheduled scan is still skipped while anything is
    // queued or running, and it is still the thing to clear.
    $later = runScan();

    expect($later->status)->toBe(Scan::COMPLETE);

    $text = reportPage();

    // Asking the newest scan instead of the oldest unfinished one took the
    // panel off the screen here and left the schedule stopped with nothing
    // on the page saying so.
    expect($text)->toContain('This scan is not moving');
    expect($text)->toContain('php please a11y:scan --resume='.$stuck->uuid.' --sync');
    expect($text)->toContain('every scheduled scan is skipped');
});

it('names the oldest unfinished scan, and counts the rest', function () {
    app(ReportDatabase::class)->install();
    page('one', '<p>Fine.</p>');

    $first = runScan();
    $second = runScan();

    foreach ([[$first, 40], [$second, 25]] as [$scan, $minutes]) {
        $scan->update(['status' => Scan::RUNNING, 'finished_at' => null, 'created_at' => now()->subMinutes($minutes), 'started_at' => now()->subMinutes($minutes)]);
        \Bpmore\A11yReport\Models\ScanPage::where('scan_id', $scan->id)->update(['status' => 'pending', 'scanned_at' => null]);
    }

    $stale = (new \Bpmore\A11yReport\Trends\Overview(app(ReportDatabase::class)))->stale(10);

    // The oldest, because clearing it is the first step, and a count of the
    // rest, because it may not be the last.
    expect($stale['scan']->id)->toBe($first->id);
    expect($stale['others'])->toBe(1);
    expect($stale['minutes'])->toBe(40);

    expect(reportPage())->toContain('1 other scan has not finished either');
});
