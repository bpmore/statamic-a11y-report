<?php

declare(strict_types=1);

use Bpmore\A11yReport\Storage\ReportDatabase;
use Statamic\Facades\Collection;
use Statamic\Widgets\Loader;

beforeEach(function () {
    $this->withStandardFakeViews();
    test()->viewShouldReturnRaw('default', PLAIN);
    Collection::make('pages')->routes('/{slug}')->save();
});

function widgetHtml(): string
{
    return (string) preg_replace('/\s+/', ' ', (string) app(Loader::class)->load('accessibility_report', ['type' => 'accessibility_report'])->html());
}

it('is a widget with a plain handle and says so before any scan', function () {
    expect(widgetHtml())->toContain('No scan has run yet');
});

it('shows open issues by impact and a line for the last 30 days', function () {
    app(ReportDatabase::class)->install();
    page('one', '<img src="/a.jpg">');
    runScan();
    runScan();

    $html = widgetHtml();

    expect($html)->toContain('open issue');
    expect($html)->toContain('1 serious');
    expect($html)->toContain('<svg');
    expect($html)->toContain('last 30 days');
    expect($html)->toContain('Last scan');
    expect($html)->toContain('Open the report');
});

it('shows the last scan\'s status as text, never as markup or an expression', function () {
    // The status is a word this addon writes, but it is a column, and the
    // widget is on every dashboard. It went through `@plain` into
    // `ui-description`'s `text` prop, which is rendered with v-html after
    // Vue has decoded the attribute, so the escaping did not hold.
    app(ReportDatabase::class)->install();
    page('one', '<p>Fine.</p>');
    runScan()->update(['status' => '<img src=x onerror=1>{{ 1+1 }}']);

    $html = widgetHtml();

    // Not the well-formedness check: it decodes entities before parsing, so
    // the escaped element reads as an element to it. The glob test covers
    // this view's shape; what matters here is what the entities stay as.
    expect($html)->toContain(': &lt;img src=x onerror=1&gt;&#123;&#123; 1+1 &#125;&#125;, 1 pages read');
    expect(str_contains($html, '<img'))->toBeFalse('the status reached Vue as an element');
    expect(str_contains($html, '{{ 1+1'))->toBeFalse('the status reached Vue as an expression');
    expect(preg_match('/(?:text|heading|subheading)="[^"]*(?:img|1\+1)/', $html))->toBe(0, 'the status is in a prop Vue renders with v-html');
});

it('renders markup Vue can compile, before and after scans', function () {
    assertVueTemplateIsWellFormed(widgetHtml());

    app(ReportDatabase::class)->install();
    page('one', '<img src="/a.jpg">');
    runScan();

    assertVueTemplateIsWellFormed(widgetHtml());
});

it('shows the scan to a widget given the one site\'s handle, as the README\'s second form has it', function () {
    app(ReportDatabase::class)->install();
    page('one', '<img src="/a.jpg">');
    runScan();

    // `['type' => 'accessibility_report', 'site' => 'default']`, from the
    // README. On a fresh install this said "No completed scan in the last
    // 30 days" beside an open-issue count from that very scan: the issue
    // states carried the site and the scan row did not.
    $html = (string) preg_replace('/\s+/', ' ', (string) app(Loader::class)->load('accessibility_report', ['type' => 'accessibility_report', 'site' => 'default'])->html());

    expect(str_contains($html, 'No completed scan'))->toBeFalse('the site-scoped widget cannot see the scan');
    expect($html)->toContain('Last scan');
    expect($html)->toContain('1 serious');
});
