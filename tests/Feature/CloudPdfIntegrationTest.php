<?php

declare(strict_types=1);

use Bpmore\A11yReport\Document\ReportWriter;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Statamic\Facades\Collection;

it('prints a real report through the approved remote browser with its evidence and PDF tags intact', function () {
    if (getenv('RUN_CLOUD_BROWSER_TESTS') !== '1') {
        test()->markTestSkipped('Explicit Cloud browser integration opt-in required.');
    }
    config([
        'statamic-a11y-report.chrome.driver' => 'cloudflare',
        'statamic-a11y-report.chrome.account_id' => getenv('SCAN_CLOUDFLARE_ACCOUNT_ID'),
        'statamic-a11y-report.chrome.api_token' => getenv('SCAN_CLOUDFLARE_API_TOKEN'),
        'statamic-a11y-report.chrome.binary' => '/nowhere/chrome',
        'statamic-a11y-report.engine' => 'php',
    ]);
    $this->withStandardFakeViews();
    test()->viewShouldReturnRaw('default', PLAIN);
    Collection::make('pages')->routes('/{slug}')->save();
    app(ReportDatabase::class)->install();
    tempStorage();
    page('cloud-proof', '<h3>Skipped heading</h3><img src="/public-fixture.jpg">');
    $report = app(ReportWriter::class)->write(runScan(), 'Cloud migration test', ['pdf', 'json']);
    $path = app(ReportWriter::class)->absolutePath($report->pdf_path);
    $pdf = file_get_contents($path);
    expect(str_starts_with($pdf, '%PDF-'))->toBeTrue();
    expect(str_contains($pdf, '/StructTreeRoot'))->toBeTrue();
    expect(preg_match('/\/MarkInfo\s*<<[^>]*?\/Marked\s*true/', $pdf))->toBe(1);
    expect(str_contains($pdf, '/Outlines'))->toBeTrue();
    expect(str_contains($pdf, '<pdfuaid:part>1</pdfuaid:part>'))->toBeTrue();
    $directory = getenv('CLOUD_BROWSER_PROOF_DIRECTORY');
    if (is_string($directory) && is_dir($directory)) {
        $target = $directory.'/hada-real-cloud-report.pdf';
        copy($path, $target);
        chmod($target, 0600);
    }
});
