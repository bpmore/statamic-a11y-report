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

it('renders markup Vue can compile, before and after scans', function () {
    assertVueTemplateIsWellFormed(widgetHtml());

    app(ReportDatabase::class)->install();
    page('one', '<img src="/a.jpg">');
    runScan();

    assertVueTemplateIsWellFormed(widgetHtml());
});
