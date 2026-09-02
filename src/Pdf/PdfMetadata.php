<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Pdf;

use RuntimeException;

/**
 * The two things PDF/UA wants that Chrome's print does not write: the
 * document title in XMP metadata, and the viewer preference that shows it
 * in the window title instead of the file name.
 *
 * Added as an incremental update, which is how PDF is designed to be
 * amended: nothing Chrome wrote is touched. A metadata stream is appended,
 * the catalog is re-declared with `/Metadata` and `/ViewerPreferences`, and
 * a new cross-reference section points at both with `/Prev` back to the old
 * one. Works on the classic cross-reference tables Chrome writes, and says
 * so if handed anything else rather than guessing at a stream layout.
 *
 * No PDF/UA identifier is written. Chrome's output has not been shown to
 * pass a full PDF/UA validation, and a file that declares conformance it
 * has not been shown to have is the failure this product exists to refuse.
 * Framework-free and pure over bytes, so a test can hand it a small PDF.
 */
final class PdfMetadata
{
    public static function stamp(string $pdfPath, string $title, string $lang = 'en', ?string $producer = null): void
    {
        $pdf = (string) file_get_contents($pdfPath);

        if (! str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException("{$pdfPath} is not a PDF.");
        }

        if (! preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/', $pdf, $m)) {
            throw new RuntimeException('The PDF has no classic cross-reference table to extend.');
        }

        $previousXref = (int) $m[1];

        if (! preg_match('/trailer\s*<<(.*?)>>\s*startxref/s', $pdf, $t)) {
            throw new RuntimeException('The PDF trailer could not be read.');
        }

        $trailer = $t[1];

        if (! preg_match('/\/Size\s+(\d+)/', $trailer, $s) || ! preg_match('/\/Root\s+(\d+)\s+0\s+R/', $trailer, $r)) {
            throw new RuntimeException('The PDF trailer has no /Size or /Root.');
        }

        $size = (int) $s[1];
        $catalogId = (int) $r[1];

        if (! preg_match('/(?<![0-9])'.$catalogId.'\s+0\s+obj\s*(<<.*?>>)\s*endobj/s', $pdf, $c)) {
            throw new RuntimeException("The catalog object {$catalogId} could not be read.");
        }

        $catalog = self::amendCatalog($c[1], $size);

        $xmp = self::xmp($title, $lang, $producer);
        $metadataObject = "{$size} 0 obj\n<</Type /Metadata /Subtype /XML /Length ".strlen($xmp).">>\nstream\n{$xmp}\nendstream\nendobj\n";
        $catalogObject = "{$catalogId} 0 obj\n{$catalog}\nendobj\n";

        $out = rtrim($pdf, "\r\n")."\n";
        $metadataOffset = strlen($out);
        $out .= $metadataObject;
        $catalogOffset = strlen($out);
        $out .= $catalogObject;
        $xrefOffset = strlen($out);

        $entries = [$catalogId => $catalogOffset, $size => $metadataOffset];
        ksort($entries);

        $out .= "xref\n0 1\n0000000000 65535 f \n";

        foreach ($entries as $id => $offset) {
            $out .= sprintf("%d 1\n%010d 00000 n \n", $id, $offset);
        }

        $info = preg_match('/\/Info\s+\d+\s+0\s+R/', $trailer, $i) ? ' '.$i[0] : '';
        $id = preg_match('/\/ID\s*\[.*?\]/s', $trailer, $d) ? ' '.$d[0] : '';

        $out .= 'trailer'."\n".'<</Size '.($size + 1).' /Root '.$catalogId.' 0 R'.$info.$id.' /Prev '.$previousXref.'>>'."\n";
        $out .= "startxref\n{$xrefOffset}\n%%EOF\n";

        file_put_contents($pdfPath, $out);
    }

    /** Whether a PDF already carries an XMP metadata stream reference. */
    public static function hasMetadata(string $pdfPath): bool
    {
        return str_contains((string) file_get_contents($pdfPath), '/Metadata ');
    }

    private static function amendCatalog(string $catalog, int $metadataId): string
    {
        $body = substr($catalog, 2, -2);

        if (str_contains($body, '/ViewerPreferences')) {
            if (! str_contains($body, '/DisplayDocTitle')) {
                $body = preg_replace('/\/ViewerPreferences\s*<</', '/ViewerPreferences <</DisplayDocTitle true ', $body, 1);
            } else {
                $body = preg_replace('/\/DisplayDocTitle\s+false/', '/DisplayDocTitle true', $body);
            }
        } else {
            $body .= "\n/ViewerPreferences <</DisplayDocTitle true>>";
        }

        $body = preg_replace('/\/Metadata\s+\d+\s+0\s+R/', '', (string) $body);
        $body .= "\n/Metadata {$metadataId} 0 R";

        return '<<'.$body.'>>';
    }

    private static function xmp(string $title, string $lang, ?string $producer): string
    {
        $e = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $producer = $producer ?? 'Accessibility Report for Statamic';

        return '<?xpacket begin="'."\u{FEFF}".'" id="W5M0MpCehiHzreSzNTczkc9d"?>'."\n"
            .'<x:xmpmeta xmlns:x="adobe:ns:meta/">'."\n"
            .'<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'."\n"
            .'<rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:xmp="http://ns.adobe.com/xap/1.0/" xmlns:pdf="http://ns.adobe.com/pdf/1.3/">'."\n"
            .'<dc:title><rdf:Alt><rdf:li xml:lang="x-default">'.$e($title).'</rdf:li><rdf:li xml:lang="'.$e($lang).'">'.$e($title).'</rdf:li></rdf:Alt></dc:title>'."\n"
            .'<dc:language><rdf:Bag><rdf:li>'.$e($lang).'</rdf:li></rdf:Bag></dc:language>'."\n"
            .'<xmp:CreateDate>'.$now.'</xmp:CreateDate><xmp:ModifyDate>'.$now.'</xmp:ModifyDate><xmp:MetadataDate>'.$now.'</xmp:MetadataDate>'."\n"
            .'<pdf:Producer>'.$e($producer).'</pdf:Producer>'."\n"
            .'</rdf:Description>'."\n"
            .'</rdf:RDF>'."\n"
            .'</x:xmpmeta>'."\n"
            .'<?xpacket end="w"?>';
    }
}
