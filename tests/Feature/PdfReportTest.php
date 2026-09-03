<?php

declare(strict_types=1);

use Bpmore\A11yReport\Document\ReportWriter;
use Bpmore\A11yReport\Pdf\ChromePrinter;
use Bpmore\A11yReport\Pdf\ChromeUnavailable;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Statamic\Facades\Collection;

/**
 * The tagged PDF. Needs Chrome, so it skips without one and says so,
 * loudly enough that a runner with no Chrome cannot pass this file quietly:
 * the CI workflow checks the browser is there before the suite runs.
 */
beforeEach(function () {
    $this->withStandardFakeViews();
    test()->viewShouldReturnRaw('default', PLAIN);
    Collection::make('pages')->routes('/{slug}')->save();
    app(ReportDatabase::class)->install();
    tempStorage();
});

function pdfBytes(): array
{
    if (! app(ChromePrinter::class)->available()) {
        test()->markTestSkipped('No Chrome on this machine: the PDF cannot be produced here.');
    }

    page('one', '<img src="/a.jpg">');
    page('two', '<h3>Skipped</h3>');
    $report = app(ReportWriter::class)->write(runScan(), 'tester', ['pdf', 'json']);
    $path = app(ReportWriter::class)->absolutePath($report->pdf_path);

    return [$report, (string) file_get_contents($path), $path];
}

it('prints a tagged PDF with a structure tree, headings, tables, a language, an outline, and its title', function () {
    [$report, $pdf, $path] = pdfBytes();

    expect($report->html_path)->not->toBeNull('the PDF is printed from the HTML, so the HTML is kept beside it');
    expect(str_starts_with($pdf, '%PDF-'))->toBeTrue();
    expect(preg_match('/\/MarkInfo\s*<<[^>]*?\/Marked\s*true/', $pdf))->toBe(1, 'the PDF is marked as tagged');
    expect($pdf)->toContain('/StructTreeRoot');
    expect(preg_match('/\/Lang\s*\(en\)/', $pdf))->toBe(1);
    expect($pdf)->toContain('/Outlines');
    expect($pdf)->toContain('/Type /Metadata /Subtype /XML');
    expect($pdf)->toContain('<dc:title><rdf:Alt><rdf:li xml:lang="x-default">Accessibility Conformance Report:');
    expect($pdf)->toContain('/DisplayDocTitle true');
    expect(substr_count($pdf, '%%EOF'))->toBe(2);

    // Structure element types Chrome wrote, in the plain and the deflated parts.
    $types = [];
    preg_match_all('/\/S\s*\/([A-Za-z0-9]+)/', $pdf, $m);
    $types = array_merge($types, $m[1]);
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
    foreach ($streams[1] as $stream) {
        $inflated = @gzuncompress($stream);
        if ($inflated !== false) {
            preg_match_all('/\/S\s*\/([A-Za-z0-9]+)/', $inflated, $m);
            $types = array_merge($types, $m[1]);
        }
    }
    $types = array_unique($types);

    foreach (['Document', 'H1', 'H2', 'Table', 'TH', 'TD', 'Caption', 'P'] as $needed) {
        expect(in_array($needed, $types, true))->toBeTrue("the structure tree has a {$needed} element");
    }
})->group('pdf');

it('is PDF/UA-1 compliant according to veraPDF, when veraPDF is installed', function () {
    [, $pdf, $path] = pdfBytes();

    expect(str_contains($pdf, '<pdfuaid:part>1</pdfuaid:part>'))->toBeTrue('the file declares PDF/UA-1, which the rest of this test is what makes true');

    $verapdf = trim((string) shell_exec('command -v verapdf 2>/dev/null'));

    if ($verapdf === '') {
        test()->markTestSkipped('veraPDF is not installed here; the CI workflow runs it.');
    }

    $xml = (string) shell_exec(escapeshellarg($verapdf).' --flavour ua1 --format xml '.escapeshellarg($path).' 2>/dev/null');
    expect(str_contains($xml, '<validationReport'))->toBeTrue('veraPDF produced a report');

    preg_match_all('/<rule\s[^>]*clause="([^"]+)"[^>]*status="failed"[^>]*>(.*?)<\/rule>/s', $xml, $failed, PREG_SET_ORDER);
    $failures = array_map(function ($f) {
        preg_match('/<description>(.*?)<\/description>/s', $f[2], $d);

        return $f[1].': '.trim($d[1] ?? '');
    }, $failed);

    // Full compliance, not a chosen subset. The identifier above is a claim,
    // and this assertion is what keeps it true: a template change that
    // breaks any PDF/UA rule fails here before a file that still declares
    // conformance can be generated.
    expect($failures)->toBe([], 'veraPDF failed: '.implode(' | ', $failures));
    expect(preg_match('/isCompliant="true"/', $xml))->toBe(1);
})->group('pdf');

it('says plainly when there is no Chrome, and still writes the document', function () {
    $writer = new ReportWriter(app(), app(\Bpmore\A11yReport\Document\ReportBuilder::class), new ChromePrinter('/nonexistent/chrome'));

    page('one', '<p>Fine.</p>');
    $scan = runScan();

    expect($writer->pdfAvailable())->toBeFalse();
    expect(fn () => $writer->write($scan, 'x', ['pdf']))->toThrow(ChromeUnavailable::class, 'No Chrome or Chromium was found');

    $report = $writer->write($scan, 'x');
    expect($report->html_path)->not->toBeNull();
    expect($report->pdf_path)->toBeNull();
});

it('finds Chrome from config before searching, and reports absence rather than a wrong path', function () {
    expect((new ChromePrinter('/nonexistent/chrome'))->binary())->toBeNull();
    expect((new ChromePrinter('definitely-not-a-command'))->binary())->toBeNull();

    $found = (new ChromePrinter)->binary();
    if ($found !== null) {
        expect(is_executable($found))->toBeTrue();
    }
});

it('describes every link in the PDF for a reader who cannot see where it points', function () {
    [, $pdf] = pdfBytes();

    expect(substr_count($pdf, '/Subtype /Link'))->toBeGreaterThan(0);
    expect(str_contains($pdf, '/Contents (Understanding success criterion 1.1.1 Non-text Content, at w3.org)'))->toBeTrue('the criterion link is described');
    expect(str_contains($pdf, '/Contents (The WCAG 2.2 Recommendation at w3.org)'))->toBeTrue('the standard link is described');
})->group('pdf');
