<?php

declare(strict_types=1);

use Bpmore\A11yReport\Document\Brand;
use Bpmore\A11yReport\Document\ReportWriter;
use Bpmore\A11yReport\Settings;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Statamic\Facades\Addon;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;

/**
 * The customer's own mark on the cover, which is the only thing about a
 * conformance document a customer may change the look of.
 *
 * Everything here is about a report being wrong rather than plain: a logo
 * nobody can have described to them, an accent too pale to read, one site's
 * mark on a document that speaks for several. A bad picture must cost a
 * customer their picture, never their report.
 */
beforeEach(function () {
    $this->withStandardFakeViews();
    test()->viewShouldReturnRaw('default', PLAIN);
    Collection::make('pages')->routes('/{slug}')->save();
    app(ReportDatabase::class)->install();
    tempStorage();
});

function logoFile(string $name): string
{
    return __DIR__.'/../__fixtures__/'.$name;
}

function brand(array $values): void
{
    foreach ($values as $key => $value) {
        config()->set('statamic-a11y-report.report.brand.'.$key, $value);
    }
}

/**
 * The document and its data, from a scan of whatever sites are named.
 *
 * Every site gets a page of its own first, because a scan with nothing in it
 * finishes without a report to look at.
 *
 * @return array{0: string, 1: array<string, mixed>}
 */
function documentWithBrand(array $sites = []): array
{
    foreach (Site::all() as $site) {
        $slug = 'one-'.$site->handle();

        // Found first, then made: a second document from the same test would
        // otherwise scan the same address twice and record the issue twice.
        $entry = Entry::query()->where('collection', 'pages')->where('slug', $slug)->first()
            ?? Entry::make()->collection('pages')->locale($site->handle())->slug($slug);

        $entry->published(true)->data(['title' => 'One', 'body' => '<img src="/a.jpg">'])->saveQuietly();
    }

    $writer = app(ReportWriter::class);
    $report = $writer->write(runScan(sites: $sites), 'tester');

    return [
        (string) file_get_contents((string) $writer->absolutePath($report->html_path)),
        (array) json_decode((string) file_get_contents((string) $writer->absolutePath($report->json_path)), true),
    ];
}

/**
 * One more place the site keeps pictures, in an empty directory of its own.
 *
 * Never the shared temporary directory: Statamic lists a container's files,
 * and on a Linux runner that directory is full of other processes' folders
 * this one cannot open, which fails as `UnableToListContents` rather than as
 * anything to do with the test.
 */
function makeContainer(string $handle, ?string $root = null): void
{
    $root ??= sys_get_temp_dir().'/a11y-container-'.$handle.'-'.uniqid();

    if (! is_dir($root)) {
        mkdir($root, 0755, true);
    }

    config()->set('filesystems.disks.'.$handle, ['driver' => 'local', 'root' => $root]);
    AssetContainer::make($handle)->disk($handle)->save();
}

/** An English and a French site, with the pages collection on both. */
function twoSites(): void
{
    Site::setSites([
        'default' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US'],
        'fr' => ['name' => 'French', 'url' => 'http://localhost/fr/', 'locale' => 'fr_FR'],
    ]);

    Collection::make('pages')->routes('/{slug}')->sites(['default', 'fr'])->save();
}

it('prints the logo on the cover, embedded, and fetches nothing to do it', function () {
    brand(['logo' => logoFile('logo.svg'), 'logo_alt' => 'Example Trust', 'accent' => '#0f3d2e']);

    [$html, $json] = documentWithBrand();

    expect($html)->toContain('data:image/svg+xml;base64,');
    expect($html)->toContain('alt="Example Trust"');
    expect($html)->toContain('h1, h2 { color: #0f3d2e; }');

    // Nothing the print step would have to go and get. Chrome fetches no
    // external resource for print and would leave a hole where the mark is.
    expect(preg_match('/<(?:img|link|script)[^>]+(?:src|href)="(?!data:)[^"]*(?:https?:)?\/\//i', $html))
        ->toBe(0, 'the document asks the printer to fetch something');

    // The mark is above the heading and is not one.
    expect(preg_match('/<img class="mark".*?<h1>/s', $html))->toBe(1, 'the mark sits above the title');

    expect($json['brand']['logo']['alt'])->toBe('Example Trust');
    expect($json['brand']['logo_from'])->toBe('global');
    expect($json['brand']['accent'])->toBe('#0f3d2e');
    expect($json['brand']['dropped'])->toBe([]);
});

it('keeps what the mark was in the JSON, and not the picture itself', function () {
    brand(['logo' => logoFile('logo.png'), 'logo_alt' => 'Example Trust']);

    [$html, $json] = documentWithBrand();

    expect($html)->toContain('data:image/png;base64,');
    expect(array_key_exists('data_uri', $json['brand']['logo']))->toBeFalse('the JSON carries the logo bytes, which nobody reading a diff of two reports wants');
    expect($json['brand']['logo']['media_type'])->toBe('image/png');
    expect($json['brand']['logo']['bytes'])->toBe(filesize(logoFile('logo.png')));
    expect($json['brand']['logo']['sha256'])->toBe(hash_file('sha256', logoFile('logo.png')));
    expect($json['brand']['logo']['source'])->toBe(logoFile('logo.png'));
});

it('leaves out a logo nobody can have described to them, and still writes the report', function () {
    brand(['logo' => logoFile('logo.svg'), 'logo_alt' => '   ']);

    [$html, $json] = documentWithBrand();

    expect($html)->not->toContain('class="mark"');
    expect(str_contains($html, 'Accessibility Conformance Report'))->toBeTrue('the report is written anyway');
    expect($json['brand']['logo'])->toBeNull();
    expect(str_contains(implode(' ', $json['brand']['dropped']), 'no words to stand in for it'))
        ->toBeTrue('the report says why it has no logo: '.implode(' ', $json['brand']['dropped']));
});

it('ignores an accent too pale to read on the page, and leaves the headings black', function () {
    brand(['accent' => '#9fd7c4']);

    [$html, $json] = documentWithBrand();

    expect(str_contains($html, '#9fd7c4'))->toBeFalse('a colour that fails 1.4.3 was printed in an accessibility report');
    expect($json['brand']['accent'])->toBeNull();
    expect(str_contains(implode(' ', $json['brand']['dropped']), '1.4.3'))
        ->toBeTrue('the report names the criterion the colour failed: '.implode(' ', $json['brand']['dropped']));
});

it('refuses a picture that is not one, and a drawing that does more than draw', function (string $file, string $needle) {
    brand(['logo' => logoFile($file), 'logo_alt' => 'Example Trust']);

    [$html, $json] = documentWithBrand();

    expect($html)->not->toContain('class="mark"');
    expect($json['brand']['logo'])->toBeNull();
    expect(str_contains(implode(' ', $json['brand']['dropped']), $needle))
        ->toBeTrue("expected [{$needle}] in: ".implode(' ', $json['brand']['dropped']));
})->with([
    'a text file' => ['not-a-logo.txt', 'is not one of'],
    'a drawing carrying script' => ['scripted.svg', 'carries script'],
    'a drawing pointing elsewhere' => ['remote.svg', 'not in the file'],
    'nothing at all' => ['no-such-logo.svg', 'was not found'],
]);

it('refuses a logo too big to embed in every report', function () {
    $big = sys_get_temp_dir().'/a11y-big-'.uniqid().'.png';
    file_put_contents($big, str_repeat(file_get_contents(logoFile('logo.png')), (int) ceil(Brand::MAX_BYTES / filesize(logoFile('logo.png'))) + 1));
    brand(['logo' => $big, 'logo_alt' => 'Example Trust']);

    [$html, $json] = documentWithBrand();

    expect(filesize($big))->toBeGreaterThan(Brand::MAX_BYTES);
    expect($html)->not->toContain('class="mark"');
    expect(str_contains(implode(' ', $json['brand']['dropped']), 'over the '.Brand::MAX_BYTES))->toBeTrue();
});

it('gives a site its own mark, and the install\'s to every site without one', function () {
    twoSites();

    brand([
        'logo' => logoFile('logo.png'),
        'logo_alt' => 'Example Trust',
        'accent' => '#0f3d2e',
        'sites' => [
            'fr' => ['logo' => logoFile('logo.svg'), 'logo_alt' => 'Example SARL', 'accent' => '#5b1a1a'],
        ],
    ]);

    [$french, $frenchJson] = documentWithBrand(['fr']);
    expect($french)->toContain('alt="Example SARL"');
    expect($french)->toContain('data:image/svg+xml;base64,');
    expect($french)->toContain('h1, h2 { color: #5b1a1a; }');
    expect($frenchJson['brand']['logo_from'])->toBe('site:fr');

    [$english, $englishJson] = documentWithBrand(['default']);
    expect($english)->toContain('alt="Example Trust"');
    expect($english)->toContain('data:image/png;base64,');
    expect($english)->toContain('h1, h2 { color: #0f3d2e; }');
    expect($englishJson['brand']['logo_from'])->toBe('global');
});

it('lets a site change the ink without changing the mark', function () {
    twoSites();

    brand([
        'logo' => logoFile('logo.png'),
        'logo_alt' => 'Example Trust',
        'accent' => '#0f3d2e',
        'sites' => ['fr' => ['accent' => '#5b1a1a']],
    ]);

    [$html, $json] = documentWithBrand(['fr']);

    expect($html)->toContain('alt="Example Trust"');
    expect($html)->toContain('h1, h2 { color: #5b1a1a; }');
    expect($json['brand']['logo_from'])->toBe('global');
    expect($json['brand']['accent_from'])->toBe('site:fr');
});

it('refuses a site\'s logo that has no words of its own rather than borrowing another\'s', function () {
    twoSites();

    brand([
        'logo' => logoFile('logo.png'),
        'logo_alt' => 'Example Trust',
        'sites' => ['fr' => ['logo' => logoFile('logo.svg')]],
    ]);

    [$html, $json] = documentWithBrand(['fr']);

    expect(str_contains($html, 'Example Trust'))->toBeFalse('the French site borrowed the English organisation\'s name for its own logo');
    expect($html)->not->toContain('class="mark"');
    expect($json['brand']['logo'])->toBeNull();
});

it('puts no site\'s mark on a report that speaks for more than one site', function () {
    twoSites();

    // Both sites have a mark, so taking either one would show here. A
    // report that quietly wore whichever site sorted first would be the
    // English company's logo on a document about the French site too.
    brand(['sites' => [
        'default' => ['logo' => logoFile('logo.png'), 'logo_alt' => 'Example Trust', 'accent' => '#0f3d2e'],
        'fr' => ['logo' => logoFile('logo.svg'), 'logo_alt' => 'Example SARL', 'accent' => '#5b1a1a'],
    ]]);

    [$html, $json] = documentWithBrand();

    expect($json['subject']['sites'])->toHaveCount(2);
    expect($html)->not->toContain('class="mark"');

    foreach (['Example Trust', 'Example SARL', '#0f3d2e', '#5b1a1a'] as $oneSites) {
        expect(str_contains($html, $oneSites))->toBeFalse("a report covering both sites wore [{$oneSites}]");
    }

    expect($json['brand']['logo'])->toBeNull();
    expect($json['brand']['accent'])->toBeNull();
});

it('gives a single-site install its own mark without being told which site it is', function () {
    // One site, and a scan that names no site because there is only one to
    // name. The report is still that site's, so its mark is too.
    brand(['sites' => ['default' => ['logo' => logoFile('logo.svg'), 'logo_alt' => 'Example Trust']]]);

    [$html, $json] = documentWithBrand();

    expect($json['subject']['sites'])->toHaveCount(1);
    expect($html)->toContain('alt="Example Trust"');
    expect($json['brand']['logo_from'])->toBe('site:default');
});

it('reaches the cover and nothing that makes the document evidence', function () {
    brand(['logo' => logoFile('logo.svg'), 'logo_alt' => 'Example Trust', 'accent' => '#0f3d2e']);

    [$html] = documentWithBrand();

    // Template text no setting may remove, checked with a mark set because
    // "brand it" is the request most likely to arrive with "and drop that
    // paragraph" attached to it.
    expect($html)->toContain('This document is a self-assessment.');
    expect($html)->toContain('It is not a certification and not a third-party audit.');
    expect($html)->toContain('Scope and limits');
    expect(str_contains($html, 'Automated testing'))->toBeTrue('the limits section is intact');

    // The status colours carry meaning and are not the customer's to choose.
    expect($html)->toContain('.status-does_not_support { color: #8a1c1c; }');
});

it('reads a logo the picture chooser saved, by its name and by its full reference', function () {
    $dir = sys_get_temp_dir().'/a11y-brand-'.uniqid();
    mkdir($dir, 0755, true);
    copy(logoFile('logo.png'), $dir.'/mark.png');
    makeContainer('brand_pics', $dir);

    // What the chooser saves: the name of the file, inside the one container
    // the chooser was pointed at.
    brand(['logo' => 'mark.png', 'logo_alt' => 'Example Trust', 'container' => 'brand_pics']);
    [$byName, $json] = documentWithBrand();
    expect($byName)->toContain('data:image/png;base64,');
    expect($json['brand']['logo']['sha256'])->toBe(hash_file('sha256', logoFile('logo.png')));

    // What somebody writes by hand: the container and the name together, so
    // it does not depend on which container the chooser happens to use.
    brand(['logo' => 'brand_pics::mark.png', 'container' => null]);
    [$byReference] = documentWithBrand();
    expect($byReference)->toContain('data:image/png;base64,');
});

it('offers a picture chooser when the site keeps pictures in one place, and words when it does not', function () {
    // Asked of the decision itself rather than of a built blueprint, which
    // Statamic resolves once per request and would answer from memory here.
    $logo = function (?string $configured) {
        $blueprint = Brand::addSettingsSection(['tabs' => ['report' => ['sections' => []]]], Brand::container($configured));

        return collect($blueprint['tabs']['report']['sections'][0]['fields'])->firstWhere('handle', 'brand_logo')['field'];
    };

    // No container at all: nothing to pick from, so a plain field.
    expect($logo(null)['type'])->toBe('text');

    makeContainer('brand_pics');
    expect($logo(null)['type'])->toBe('assets');
    expect($logo(null)['container'])->toBe('brand_pics');

    // Two containers and no answer about which. An asset field with no
    // container throws while it renders and would take the whole settings
    // screen down with it, so the plain field comes back.
    makeContainer('other_pics');
    expect($logo(null)['type'])->toBe('text');

    // Named, so there is an answer again.
    expect($logo('other_pics')['type'])->toBe('assets');
    expect($logo('other_pics')['container'])->toBe('other_pics');

    // Named and since deleted: a plain field, not a broken screen.
    expect($logo('gone')['type'])->toBe('text');
});

it('leaves a form it does not recognise alone', function () {
    expect(Brand::addSettingsSection(['tabs' => ['statement' => []]], null))->toBe(['tabs' => ['statement' => []]]);
});

it('shows the picture chooser on the screen itself, with the mark section on the report tab', function () {
    makeContainer('brand_pics');

    $user = User::make()->email('super@example.test')->makeSuper();
    $user->save();

    $content = html_entity_decode(
        $this->actingAs($user)->get(cp_route('addons.settings.edit', 'statamic-a11y-report'))->assertOk()->getContent(),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8',
    );

    expect($content)->toContain('Your mark on the cover');
    expect($content)->toContain('brand_logo');
    expect($content)->toContain('"type":"assets"');
});

it('still opens the settings screen on a site with two places for pictures', function () {
    makeContainer('brand_pics');
    makeContainer('other_pics');

    $user = User::make()->email('super@example.test')->makeSuper();
    $user->save();

    $this->actingAs($user)->get(cp_route('addons.settings.edit', 'statamic-a11y-report'))->assertOk();
});

it('turns the rows on the screen into a mark for each site named on them', function () {
    saveSettings([
        'brand_logo' => 'logo.png',
        'brand_logo_alt' => 'Example Trust',
        'brand_sites' => [
            ['site' => 'fr', 'logo' => 'logo-fr.svg', 'logo_alt' => 'Example SARL', 'accent' => '#5b1a1a'],
            // A relationship field hands back a list even when it takes one.
            ['site' => ['de'], 'accent' => '#1a3d5b', 'logo' => '', 'logo_alt' => null],
            // Nothing filled in, and no site named: neither is an answer, and
            // an empty row must not blank what the file says.
            ['site' => 'es'],
            ['site' => null, 'accent' => '#000000'],
        ],
    ]);

    $brand = app(Settings::class)->effective()['report']['brand'];

    expect($brand['logo'])->toBe('logo.png');
    expect($brand['sites'])->toBe([
        'fr' => ['logo' => 'logo-fr.svg', 'logo_alt' => 'Example SARL', 'accent' => '#5b1a1a'],
        'de' => ['accent' => '#1a3d5b'],
    ]);
});

it('leaves the file in charge of the marks until somebody fills a row in', function () {
    config()->set('statamic-a11y-report.report.brand.sites', ['fr' => ['accent' => '#5b1a1a']]);

    saveSettings(['brand_sites' => [], 'brand_accent' => '']);

    expect(app(Settings::class)->effective()['report']['brand']['sites'])->toBe(['fr' => ['accent' => '#5b1a1a']]);
});
