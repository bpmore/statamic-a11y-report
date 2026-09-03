<?php

declare(strict_types=1);

use Bpmore\A11yReport\Pdf\PdfMetadata;

/**
 * The incremental update that gives a Chrome PDF its XMP title. Tested on a
 * PDF small enough to read, built here by hand in the shape Chrome writes:
 * classic cross-reference table, plain-text catalog, no metadata.
 */
function tinyPdf(string $catalogExtra = ''): string
{
    $objects = [
        1 => "<</Type /Catalog /Pages 2 0 R /MarkInfo <</Marked true>> /Lang (en){$catalogExtra}>>",
        2 => '<</Type /Pages /Kids [3 0 R] /Count 1>>',
        3 => '<</Type /Page /Parent 2 0 R /MediaBox [0 0 200 200]>>',
        4 => '<</Title (Tiny) /Producer (test)>>',
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [];

    foreach ($objects as $id => $body) {
        $offsets[$id] = strlen($pdf);
        $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
    }

    $xref = strlen($pdf);
    $pdf .= "xref\n0 5\n0000000000 65535 f \n";

    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    $pdf .= "trailer\n<</Size 5 /Root 1 0 R /Info 4 0 R>>\nstartxref\n{$xref}\n%%EOF\n";

    return $pdf;
}

function stamped(string $catalogExtra = '', string $title = 'Accessibility Conformance Report: Example, 2 September 2026', bool $declare = false): string
{
    $path = tempnam(sys_get_temp_dir(), 'a11y-pdf');
    file_put_contents($path, tinyPdf($catalogExtra));
    PdfMetadata::stamp($path, $title, 'en', 'Test producer', $declare);
    $out = file_get_contents($path);
    unlink($path);

    return $out;
}

it('appends a metadata stream and a re-declared catalog, and leaves what was there alone', function () {
    $original = tinyPdf();
    $out = stamped();

    expect(str_starts_with($out, $original))->toBeTrue('an incremental update begins with the original bytes');
    expect($out)->toContain('/Type /Metadata /Subtype /XML');
    expect($out)->toContain('<dc:title><rdf:Alt><rdf:li xml:lang="x-default">Accessibility Conformance Report: Example, 2 September 2026</rdf:li>');
    expect($out)->toContain('<pdf:Producer>Test producer</pdf:Producer>');
    expect($out)->toContain('/Metadata 5 0 R');
    expect($out)->toContain('/ViewerPreferences <</DisplayDocTitle true>>');
    expect($out)->toContain('/Prev ');
    expect(substr_count($out, '%%EOF'))->toBe(2);
    expect(preg_match('/trailer\s*<<\/Size 6 \/Root 1 0 R \/Info 4 0 R \/Prev \d+>>/', $out))->toBe(1);
});

it('points the new cross-reference entries at the right bytes', function () {
    $out = stamped();

    preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/', $out, $m);
    $xref = substr($out, (int) $m[1]);
    expect(str_starts_with($xref, "xref\n"))->toBeTrue();

    preg_match_all('/^(\d+) 1\n(\d{10}) 00000 n /m', $xref, $entries, PREG_SET_ORDER);
    expect($entries)->toHaveCount(2);

    foreach ($entries as [$_, $id, $offset]) {
        expect(substr($out, (int) $offset, strlen("{$id} 0 obj")))->toBe("{$id} 0 obj");
    }
});

it('turns an existing viewer preference on rather than adding a second one, and escapes the title', function () {
    $out = stamped(' /ViewerPreferences <</DisplayDocTitle false /HideToolbar true>>', 'A & B <C>');

    expect(substr_count($out, '/ViewerPreferences'))->toBe(2);
    expect($out)->toContain('/ViewerPreferences <</DisplayDocTitle true /HideToolbar true>>');
    expect($out)->toContain('A &amp; B &lt;C&gt;');
});

it('refuses a file it does not understand instead of guessing', function () {
    $path = tempnam(sys_get_temp_dir(), 'a11y-pdf');
    file_put_contents($path, "%PDF-1.5\nnot a classic table\n");

    expect(fn () => PdfMetadata::stamp($path, 'x'))->toThrow(RuntimeException::class);
    unlink($path);
});

it('declares PDF/UA only when told to', function () {
    expect(stamped())->not->toContain('pdfuaid');
    expect(stamped(declare: true))->toContain('<pdfuaid:part>1</pdfuaid:part>');
    expect(stamped(declare: true))->toContain('xmlns:pdfuaid="http://www.aiim.org/pdfua/ns/id/"');
});

it('maps a non-standard structure type in the role map and leaves a standard one alone', function () {
    // A structure tree root with a Strong element, the shape Chrome writes
    // for <strong>, and a P, which is standard.
    $extra = ' /StructTreeRoot 6 0 R';
    $path = tempnam(sys_get_temp_dir(), 'a11y-pdf');
    $pdf = tinyPdf($extra);
    $pdf = str_replace("trailer\n<</Size 5", "6 0 obj\n<</Type /StructTreeRoot /K [7 0 R]>>\nendobj\n7 0 obj\n<</S /Strong /P 6 0 R>>\nendobj\n8 0 obj\n<</S /P /P 6 0 R>>\nendobj\ntrailer\n<</Size 9", $pdf);
    file_put_contents($path, $pdf);

    PdfMetadata::stamp($path, 'T');
    $out = file_get_contents($path);
    unlink($path);

    expect($out)->toContain('/RoleMap <</Strong /Span>>');
    expect(substr_count($out, '/Type /StructTreeRoot'))->toBe(2);
    expect($out)->not->toContain('/P /Span');
});

it('gives every link annotation an alternate description, by destination where it knows one and by URL where it does not', function () {
    // Two link annotations in the shape Chrome writes them, one already
    // described, separated by a content stream to prove the object scan
    // does not run through the stream and swallow what follows it.
    $path = tempnam(sys_get_temp_dir(), 'a11y-pdf');
    $pdf = tinyPdf();
    $pdf = str_replace(
        "trailer\n<</Size 5",
        "5 0 obj\n<</Length 12>>\nstream\nBT >> endobj\nendstream\nendobj\n"
        ."6 0 obj\n<</Type /Annot /Subtype /Link /Rect [0 0 1 1] /A <</Type /Action /S /URI /URI (https://www.w3.org/TR/WCAG22/)>>>>\nendobj\n"
        ."7 0 obj\n<</Type /Annot /Subtype /Link /Rect [0 0 1 1] /A <</S /URI /URI (https://example.test/a\\(b\\))>>>>\nendobj\n"
        ."8 0 obj\n<</Type /Annot /Subtype /Link /Rect [0 0 1 1] /Contents (Already said) /A <</S /URI /URI (https://example.test/)>>>>\nendobj\n"
        ."trailer\n<</Size 9",
        $pdf,
    );
    file_put_contents($path, $pdf);

    PdfMetadata::stamp($path, 'T', 'en', null, false, ['https://www.w3.org/TR/WCAG22/' => 'The WCAG 2.2 Recommendation at w3.org']);
    $out = file_get_contents($path);
    unlink($path);

    expect(str_contains($out, '/URI (https://www.w3.org/TR/WCAG22/)>>'."\n".'/Contents (The WCAG 2.2 Recommendation at w3.org)>>'))->toBeTrue('the known destination gets its description');
    expect(str_contains($out, '/Contents (Link to https://example.test/a\\(b\\))>>'))->toBeTrue('an unknown destination is described by its URL, escaped');
    expect(substr_count($out, 'Already said'))->toBe(1);
    expect(substr_count($out, '/Subtype /Link'))->toBe(5);
    expect(preg_match('/\n6 1\n\d{10} 00000 n \n7 1\n/', $out))->toBe(1);
});
