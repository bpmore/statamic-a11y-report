<?php

declare(strict_types=1);

use Bpmore\A11yReport\Models\Scan;
use Bpmore\A11yReport\Scan\Scans;
use Bpmore\A11yReport\Scan\ScanScope;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Bpmore\A11yReport\Tests\TestCase;
use Statamic\Facades\Entry;

// Only the feature tests boot Statamic. The engine and the fingerprint take
// values and return values, so their tests need no framework, and a suite
// that boots nothing for them cannot grow a dependency on something booted.
uses(TestCase::class)->in('Feature');

/**
 * Shared by every feature file that needs pages and scans. Pest functions are
 * global, so they live here once rather than in the first file that needed
 * them.
 */
const PLAIN = '<html lang="en"><body><h1>{{ title }}</h1>{{ body }}</body></html>';

function page(string $slug, string $body, string $collection = 'pages', bool $published = true, array $extra = []): void
{
    $entry = Entry::find(Entry::query()->where('collection', $collection)->where('slug', $slug)->first()?->id())
        ?? Entry::make()->collection($collection)->slug($slug);

    $entry->published($published)->data(array_merge(['title' => ucfirst($slug), 'body' => $body], $extra))->saveQuietly();
}

function runScan(array $sites = [], array $collections = [], ?string $since = null): Scan
{
    $scans = app(Scans::class);
    $scope = ScanScope::fromConfig((array) config('statamic-a11y-report.scan'), $sites, $collections, $since);

    return $scans->start($scans->create($scope, Scan::TRIGGER_CI, 'test'), sync: true);
}


/**
 * Whether markup Vue will compile is well formed.
 *
 * A utility view and a widget view become `defineComponent({ template })`
 * in the browser, where an unclosed tag is a compile error that shows as a
 * blank page and never as an exception on the server. Vue's template syntax
 * is close enough to XML that parsing the markup as XML, with the one void
 * element Blade emits made self-closing, catches the mistake here instead.
 */
function assertVueTemplateIsWellFormed(string $html): void
{
    $xml = preg_replace('/<input([^>]*?)\s*\/?>/', '<input$1 />', $html);

    // A bare boolean attribute (`pill`, `disabled`) is Vue, not XML. Given a
    // value so the parser accepts it; Vue never sees this copy.
    $xml = preg_replace_callback('/<[a-z][\w-]*(\s[^<>]*)?\/?>/i', function ($m) {
        return preg_replace_callback(
            '/(\s)([a-z][\w:@.-]*)(="[^"]*")?/i',
            fn ($a) => $a[1].$a[2].($a[3] ?? '="'.$a[2].'"'),
            $m[0],
        );
    }, (string) $xml);
    $xml = html_entity_decode((string) $xml, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $xml = str_replace('&', '&amp;', $xml);

    $previous = libxml_use_internal_errors(true);
    $ok = simplexml_load_string('<root>'.$xml.'</root>') !== false;
    $errors = array_map(fn ($e) => trim($e->message).' (line '.$e->line.')', libxml_get_errors());
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    expect($ok)->toBeTrue('the template is not well formed, so Vue would not compile it: '.implode('; ', $errors));
}

/**
 * A Blade directive glued to a word is not a directive. `scan@if (...)` is
 * printed as text, with no error, and the sentence after it wears a literal
 * "@if". Found on the overview page after the document view had the same
 * fault. Every view is swept for it.
 */
function assertNoGluedBladeDirectives(): void
{
    foreach (glob(__DIR__.'/../resources/views/**/*.blade.php') ?: [] as $file) {
        $hits = preg_match_all('/[A-Za-z0-9_)]@(if|endif|else|elseif|foreach|endforeach|plain)\b/', (string) file_get_contents($file), $m);
        expect($hits)->toBe(0, basename($file).' has a directive glued to a word: '.implode(', ', $m[0]));
    }
}

/**
 * A temporary storage path for reports, with the folders Laravel itself
 * needs there, so nothing lands in the harness's own storage directory.
 */
function tempStorage(): string
{
    $dir = sys_get_temp_dir().'/a11y-report-'.uniqid();

    foreach (['framework/cache', 'framework/views', 'framework/sessions'] as $sub) {
        mkdir($dir.'/'.$sub, 0755, true);
    }

    app()->useStoragePath($dir);

    return $dir;
}

/**
 * The page's text, flattened. A utility's HTML arrives inside Inertia's JSON
 * page object, where every newline is a literal backslash-n.
 */
function pageText(string $html): string
{
    // Inertia puts the page object in an attribute, so the utility's HTML
    // arrives entity-encoded: `<svg` is `&lt;svg`. Decoded once here.
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // And JSON-escaped: a quote inside an attribute arrives as a backslash
    // and a quote, so `text="Supports"` would never match without this.
    return (string) preg_replace('/\s+/', ' ', str_replace(['\\n', '\\t', '\\r', '\\/', '\\"'], [' ', ' ', ' ', '/', '"'], $html));
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

