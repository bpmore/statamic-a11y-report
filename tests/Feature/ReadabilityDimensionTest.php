<?php

declare(strict_types=1);

use Bpmore\A11yReport\Models\Scan;
use Bpmore\A11yReport\Models\ScanPage;
use Bpmore\A11yReport\Readability\PageReading;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;

/**
 * The reading level as a dimension of every scan: graded with the engine
 * Plain grades with, against the target the scan began with, and rolled up
 * onto the scan as a median band. Evidence about the writing, never a
 * WCAG result.
 */
const EASY_PAGE = '<p>A flu shot keeps you well. It is free. It takes five minutes. Ask us today.</p>';

const HARD_PAGE = '<p>Immunization substantially reduces hospitalization. Epidemiological surveillance corroborates it. Immunocompromised populations benefit disproportionately.</p>';

beforeEach(function () {
    $this->withStandardFakeViews();
    test()->viewShouldReturnRaw('default', '<html lang="en"><body><nav><a href="/">Home</a> <a href="/care">Find care</a></nav><main><h1>{{ title }}</h1>{{ body }}</main><footer>Immunocompromised populations benefit disproportionately from epidemiological surveillance.</footer></body></html>');

    Collection::make('pages')->routes('/{slug}')->save();

    app(ReportDatabase::class)->install();
});

it('grades every page it read, against the target the scan began with, and rolls the site up as a median band', function () {
    page('easy', EASY_PAGE);
    page('hard', HARD_PAGE);
    page('harder', HARD_PAGE);
    page('gallery', '<img src="/a.jpg" alt="A photo">');

    $scan = runScan();

    expect($scan->status)->toBe(Scan::COMPLETE);

    $record = $scan->readability;
    expect($record['enabled'])->toBeTrue();
    expect($record['engine'])->toBe('bpmore/readability-core');
    expect($record['engine_version'])->not->toBe('')->not->toBe('unknown');
    expect($record['locales'])->toBe(['en']);
    expect($record['target'])->toBe(['grade' => 8, 'tolerance' => 1, 'low' => 7, 'high' => 9, 'label' => 'Grade 7–9']);

    // The furniture around the main content is not the page: the footer
    // above is graduate-level prose on every page, and the easy page still
    // reads as easy.
    $easy = ScanPage::where('path', '/easy')->first()->readability;
    expect($easy['status'])->toBe(PageReading::GRADED);
    expect($easy['comparison'])->toBe('below');
    expect($easy['words'])->toBe(16);
    expect($easy['sentences'])->toBe(4);
    expect($easy['label'])->toMatch('/^Grade \d/');

    $hard = ScanPage::where('path', '/hard')->first()->readability;
    expect($hard['status'])->toBe(PageReading::GRADED);
    expect($hard['label'])->toBe('Grade 17+');
    expect($hard['comparison'])->toBe('above');
    expect($hard['grade'])->toBeGreaterThan(17);

    // A page with no prose is not a page that reads at grade zero.
    $gallery = ScanPage::where('path', '/gallery')->first()->readability;
    expect($gallery['status'])->toBe(PageReading::NOTHING);
    expect($gallery['grade'])->toBeNull();
    expect($gallery['reason'])->toContain('Nothing on the page was left to grade');

    // The median page, as a band, against the target. Three graded, two of
    // them hard: the median is hard.
    expect($record['pages'])->toBe(['graded' => 3, 'above' => 2, 'on_target' => 0, 'below' => 1, 'refused' => 0, 'nothing' => 1, 'failed' => 0]);
    expect($record['median']['label'])->toBe('Grade 17+');
    expect($record['median']['comparison'])->toBe('above');
});

it('measures against the target in force when the scan began, whatever the config says by the time a page is read', function () {
    config()->set('statamic-a11y-report.readability.target', ['grade' => 16, 'tolerance' => 2]);
    page('hard', HARD_PAGE);

    $scan = runScan();

    // Grade 17+ overlaps a target of 14 to 18, so the hard page is on target
    // here, where against the default it was above: the target the row
    // carries is the one the page was measured against.
    expect($scan->readability['target'])->toBe(['grade' => 16, 'tolerance' => 2, 'low' => 14, 'high' => 18, 'label' => 'Grade 14–18']);
    expect(ScanPage::first()->readability['comparison'])->toBe('on_target');

    // A record is rebuilt from the row, not from the config, so a page read
    // by a worker with a different config file is measured the same way.
    config()->set('statamic-a11y-report.readability.target', ['grade' => 8, 'tolerance' => 1]);
    $rebuilt = \Bpmore\A11yReport\Readability\Readability::fromRecord($scan->refresh()->readability);
    expect($rebuilt->targetGrade)->toBe(16);
    expect($rebuilt->targetTolerance)->toBe(2);
    expect($rebuilt->enabled)->toBeTrue();
    expect($rebuilt->grade(HARD_PAGE, 'en_US')->comparison?->value)->toBe('on_target');
});

it('takes the target from the settings screen once it has been saved, all the way onto the scan row', function () {
    saveSettings(['readability_target_grade' => 6, 'readability_target_tolerance' => 0]);
    page('easy', EASY_PAGE);

    $scan = runScan();

    expect($scan->readability['target'])->toBe(['grade' => 6, 'tolerance' => 0, 'low' => 6, 'high' => 6, 'label' => 'Grade 6']);
    expect(ScanPage::first()->readability['comparison'])->toBe('below');
});

it('refuses a site whose language the formulas were not calibrated on, and says so on the page', function () {
    config()->set('statamic.system.multisite', true);
    Site::setSites([
        'default' => ['name' => 'English', 'locale' => 'en_US', 'url' => 'http://localhost/'],
        'fr' => ['name' => 'Français', 'locale' => 'fr_FR', 'url' => 'http://localhost/fr/'],
    ]);
    Collection::make('pages')->sites(['default', 'fr'])->routes('/{slug}')->save();

    page('easy', EASY_PAGE);
    \Statamic\Facades\Entry::make()->collection('pages')->locale('fr')->slug('facile')->published(true)
        ->data(['title' => 'Facile', 'body' => '<p>Un vaccin vous protège.</p>'])->saveQuietly();

    $scan = runScan();

    $french = ScanPage::where('site', 'fr')->first()->readability;
    expect($french['status'])->toBe(PageReading::REFUSED);
    expect($french['grade'])->toBeNull();
    expect($french['reason'])->toContain('fr');

    expect($scan->readability['pages']['graded'])->toBe(1);
    expect($scan->readability['pages']['refused'])->toBe(1);
});

it('leaves the dimension out when it is switched off, and the scan row says it was left out', function () {
    config()->set('statamic-a11y-report.readability.enabled', false);
    page('easy', EASY_PAGE);

    $scan = runScan();

    expect($scan->status)->toBe(Scan::COMPLETE);
    expect($scan->readability)->toBe(['enabled' => false]);
    expect(ScanPage::first()->readability)->toBeNull();
});

it('never fails a page, a scan or a deploy for its grade', function () {
    page('hard', HARD_PAGE);

    $scan = runScan();

    expect($scan->status)->toBe(Scan::COMPLETE);
    expect($scan->issues_total)->toBe(0);
    expect(ScanPage::first()->issues_count)->toBe(0);

    // And the CI thresholds have no key for it to be given.
    expect(array_keys((array) config('statamic-a11y-report.ci.fail_above')))->toBe(['critical', 'serious']);
});

it('keeps a page that was not read out of the roll-up rather than counting it as ungraded prose', function () {
    // The template goes blank for one entry, which the renderer refuses to
    // read as a clean page; the same shape the scan tests use.
    test()->viewShouldReturnRaw('default', '{{ if slug == "broken" }}{{ else }}<html lang="en"><body><main>{{ body }}</main></body></html>{{ /if }}');
    page('easy', EASY_PAGE);
    page('broken', EASY_PAGE);

    $scan = runScan();

    // A page that could not be read has no reading, and appears nowhere in
    // the counts: unknown is not "nothing to grade".
    expect($scan->pages_errored)->toBe(1);
    expect(ScanPage::where('status', ScanPage::ERROR)->first()->readability)->toBeNull();
    expect($scan->readability['pages'])->toBe(['graded' => 1, 'above' => 0, 'on_target' => 0, 'below' => 1, 'refused' => 0, 'nothing' => 0, 'failed' => 0]);
});

it('says what the site reads at on the overview, in the history, and at the end of a scan', function () {
    $user = \Statamic\Facades\User::make()->email('super@example.test')->makeSuper();
    $user->save();

    page('easy', EASY_PAGE);
    page('hard', HARD_PAGE);
    page('gallery', '<img src="/a.jpg" alt="A photo">');

    // One expectation for the line: two `expectsOutputToContain` on the
    // same line of output cannot both pass, because the first one to match
    // the write consumes it and the second never sees it.
    $this->artisan('statamic:a11y:scan', ['--sync' => true])
        ->expectsOutputToContain('Reading level: The median page reads at Grade 17+, above the target of Grade 7–9. 1 of 2 pages graded is above it. Not graded: 1 page with nothing to grade.')
        ->assertExitCode(0);

    $text = pageText($this->actingAs($user)->get(cp_route('utilities.index').'/a11y-report')->assertOk()->getContent());

    expect($text)->toContain('Reading level');
    expect($text)->toContain('1 of 2 pages graded is above it');
    expect($text)->toContain('1 above target');
    expect($text)->toContain('evidence about the writing and not a WCAG result');
    expect(str_contains(strtolower($text), 'fails wcag'))->toBeFalse('the reading level is never a WCAG failure');

    // Every state of the record compiles as a Vue template: measured,
    // switched off, and from before the dimension existed.
    assertVueTemplateIsWellFormed(utilityHtmlAs($user));
    Scan::query()->update(['readability' => json_encode(['enabled' => false])]);
    expect(pageText($this->actingAs($user)->get(cp_route('utilities.index').'/a11y-report')->getContent()))->toContain('Not measured: the reading-level dimension was switched off');
    assertVueTemplateIsWellFormed(utilityHtmlAs($user));
    Scan::query()->update(['readability' => null]);
    assertVueTemplateIsWellFormed(utilityHtmlAs($user));
});

/** The overview's own markup, as Vue will receive it, for a given user. */
function utilityHtmlAs(\Statamic\Contracts\Auth\User $user): string
{
    $response = test()->actingAs($user)->get(cp_route('utilities.index').'/a11y-report')->assertOk()->getContent();

    preg_match('/data-page="([^"]+)"/', $response, $m);
    $page = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);

    return (string) $page['props']['html'];
}
