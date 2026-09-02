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

/**
 * The page's text, flattened. A utility's HTML arrives inside Inertia's JSON
 * page object, where every newline is a literal backslash-n.
 */
function pageText(string $html): string
{
    // Inertia puts the page object in an attribute, so the utility's HTML
    // arrives entity-encoded: `<svg` is `&lt;svg`. Decoded once here.
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return (string) preg_replace('/\s+/', ' ', str_replace(['\\n', '\\t', '\\r', '\\/'], [' ', ' ', ' ', '/'], $html));
}

/** The utility's own markup, as Vue will receive it: unpacked from Inertia's page object. */
function utilityHtml(): string
{
    $response = test()->actingAs(test()->user)->get(cp_route('utilities.index').'/a11y-report')->assertOk()->getContent();

    preg_match('/data-page="([^"]+)"/', $response, $m);
    $page = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);

    return (string) $page['props']['html'];
}

/** Point the addon at its own connection name, backed by the in-memory database. */
function ownConnection(): void
{
    config()->set('database.connections.'.ReportDatabase::DEFAULT_CONNECTION, config('database.connections.a11y_testing'));
    config()->set('statamic-a11y-report.connection', ReportDatabase::DEFAULT_CONNECTION);
    config()->set('queue.batching.database', ReportDatabase::DEFAULT_CONNECTION);
}

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
