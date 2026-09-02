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
 * The PDF/UA identifier is written only when asked, and the writer asks
 * because the output has been validated against PDF/UA-1 with veraPDF and
 * the suite repeats that validation wherever veraPDF is installed. A file
 * that declared conformance it had not been shown to have would be the
 * failure this product exists to refuse, so the declaration and the
 * validation travel together. Framework-free and pure over bytes, so a test
 * can hand it a small PDF.
 */
final class PdfMetadata
{
    /**
     * Structure types ISO 32000-1 defines. Anything else Chrome writes, such
     * as `Strong` for an HTML strong element, has to be mapped to one of
     * these in the role map or PDF/UA reads it as an unknown element.
     */
    private const STANDARD_TYPES = [
        'Document', 'Part', 'Art', 'Sect', 'Div', 'BlockQuote', 'Caption', 'TOC', 'TOCI', 'Index', 'NonStruct', 'Private',
        'P', 'H', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'L', 'LI', 'Lbl', 'LBody', 'Table', 'TR', 'TH', 'TD', 'THead', 'TBody', 'TFoot',
        'Span', 'Quote', 'Note', 'Reference', 'BibEntry', 'Code', 'Link', 'Annot', 'Ruby', 'RB', 'RT', 'RP', 'Warichu', 'WT', 'WP',
        'Figure', 'Formula', 'Form',
    ];

    /** Where a non-standard type Chrome writes should map. Anything not listed maps to Span. */
    private const ROLE_MAP = [
        'Strong' => 'Span', 'Em' => 'Span', 'B' => 'Span', 'I' => 'Span', 'U' => 'Span', 'S' => 'Span',
        'Sub' => 'Span', 'Sup' => 'Span', 'Abbr' => 'Span', 'Mark' => 'Span', 'Del' => 'Span', 'Ins' => 'Span', 'Time' => 'Span',
        'Article' => 'Art', 'Section' => 'Sect', 'Nav' => 'Div', 'Header' => 'Div', 'Footer' => 'Div', 'Main' => 'Div', 'Aside' => 'Div',
        'Pre' => 'P', 'Dl' => 'L', 'Dt' => 'Lbl', 'Dd' => 'LBody', 'Ul' => 'L', 'Ol' => 'L', 'Li' => 'LI',
    ];

    public static function stamp(string $pdfPath, string $title, string $lang = 'en', ?string $producer = null, bool $declarePdfUa = false): void
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

        $xmp = self::xmp($title, $lang, $producer, $declarePdfUa);
        $metadataObject = "{$size} 0 obj\n<</Type /Metadata /Subtype /XML /Length ".strlen($xmp).">>\nstream\n{$xmp}\nendstream\nendobj\n";
        $catalogObject = "{$catalogId} 0 obj\n{$catalog}\nendobj\n";

        $out = rtrim($pdf, "\r\n")."\n";
        $metadataOffset = strlen($out);
        $out .= $metadataObject;
        $catalogOffset = strlen($out);
        $out .= $catalogObject;

        $entries = [$catalogId => $catalogOffset, $size => $metadataOffset];

        // The role map, when the structure tree uses a type the standard does
        // not define. Re-declared in the same update, for the same reason.
        if (($roleMapped = self::structTreeRootWithRoleMap($pdf, $c[1])) !== null) {
            [$rootId, $rootObject] = $roleMapped;
            $entries[$rootId] = strlen($out);
            $out .= "{$rootId} 0 obj\n{$rootObject}\nendobj\n";
        }

        $xrefOffset = strlen($out);
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

    /**
     * The structure tree root, re-declared with a role map for every
     * non-standard type the document uses, or null when there is none.
     *
     * @return array{0: int, 1: string}|null
     */
    private static function structTreeRootWithRoleMap(string $pdf, string $catalog): ?array
    {
        if (! preg_match('/\/StructTreeRoot\s+(\d+)\s+0\s+R/', $catalog, $m)) {
            return null;
        }

        $rootId = (int) $m[1];

        $types = [];
        preg_match_all('/\/S\s*\/([A-Za-z0-9]+)/', $pdf, $found);
        $types = array_merge($types, $found[1]);

        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
        foreach ($streams[1] as $stream) {
            $inflated = @gzuncompress($stream);
            if ($inflated !== false) {
                preg_match_all('/\/S\s*\/([A-Za-z0-9]+)/', $inflated, $found);
                $types = array_merge($types, $found[1]);
            }
        }

        $nonStandard = array_values(array_unique(array_filter($types, fn ($t) => ! in_array($t, self::STANDARD_TYPES, true))));

        if ($nonStandard === []) {
            return null;
        }

        if (! preg_match('/(?<![0-9])'.$rootId.'\s+0\s+obj\s*(<<.*?>>)\s*endobj/s', $pdf, $r)) {
            return null;
        }

        $body = substr($r[1], 2, -2);
        $existing = [];

        if (preg_match('/\/RoleMap\s*<<(.*?)>>/s', $body, $rm)) {
            preg_match_all('/\/([A-Za-z0-9]+)\s*\/([A-Za-z0-9]+)/', $rm[1], $pairs, PREG_SET_ORDER);
            foreach ($pairs as $pair) {
                $existing[$pair[1]] = $pair[2];
            }
            $body = preg_replace('/\/RoleMap\s*<<.*?>>/s', '', $body, 1);
        }

        foreach ($nonStandard as $type) {
            $existing[$type] ??= self::ROLE_MAP[$type] ?? 'Span';
        }

        $map = implode(' ', array_map(fn ($from, $to) => "/{$from} /{$to}", array_keys($existing), $existing));

        return [$rootId, '<<'.trim((string) $body)."\n/RoleMap <<{$map}>>>>"];
    }

    private static function xmp(string $title, string $lang, ?string $producer, bool $declarePdfUa = false): string
    {
        $e = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $producer = $producer ?? 'Accessibility Report for Statamic';

        return '<?xpacket begin="'."\u{FEFF}".'" id="W5M0MpCehiHzreSzNTczkc9d"?>'."\n"
            .'<x:xmpmeta xmlns:x="adobe:ns:meta/">'."\n"
            .'<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'."\n"
            .'<rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:xmp="http://ns.adobe.com/xap/1.0/" xmlns:pdf="http://ns.adobe.com/pdf/1.3/"'.($declarePdfUa ? ' xmlns:pdfuaid="http://www.aiim.org/pdfua/ns/id/"' : '').'>'."\n"
            .'<dc:title><rdf:Alt><rdf:li xml:lang="x-default">'.$e($title).'</rdf:li><rdf:li xml:lang="'.$e($lang).'">'.$e($title).'</rdf:li></rdf:Alt></dc:title>'."\n"
            .'<dc:language><rdf:Bag><rdf:li>'.$e($lang).'</rdf:li></rdf:Bag></dc:language>'."\n"
            .'<xmp:CreateDate>'.$now.'</xmp:CreateDate><xmp:ModifyDate>'.$now.'</xmp:ModifyDate><xmp:MetadataDate>'.$now.'</xmp:MetadataDate>'."\n"
            .'<pdf:Producer>'.$e($producer).'</pdf:Producer>'."\n"
            .($declarePdfUa ? '<pdfuaid:part>1</pdfuaid:part>'."\n" : '')
            .'</rdf:Description>'."\n"
            .'</rdf:RDF>'."\n"
            .'</x:xmpmeta>'."\n"
            .'<?xpacket end="w"?>';
    }
}
