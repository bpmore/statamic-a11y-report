<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Document;

use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;

/**
 * The customer's own mark on the cover of a report, and nothing else.
 *
 * Three things: a logo, the words that stand in for it, and one accent
 * colour for the headings. Everything else about the document is fixed. A
 * conformance report is evidence, and evidence that is laid out differently
 * for every customer is harder to read and easier to argue with.
 *
 * What a brand cannot reach, on purpose: the scope and limits statement, the
 * self-assessment notice, the footer, and the status colours in the
 * conformance table, which carry meaning rather than decoration. There is no
 * customer-supplied template, because a template is the one setting that
 * would let somebody delete the limits.
 *
 * Nothing here may stop a report being generated. Every failure leaves out
 * the logo or the colour and says why in `dropped`, which the builder logs
 * and the JSON keeps.
 */
final class Brand
{
    /**
     * Before base64, which adds a third again. A cover mark is a logo, and a
     * photograph embedded in every report would be most of the file.
     */
    public const MAX_BYTES = 393216;

    /**
     * The accent is heading text on white paper. 1.4.3 asks this much of
     * text below 24px, which the smaller headings are, so it is asked of
     * every accent: an accessibility report whose own headings fail a
     * criterion it reports on is the story about the product.
     */
    public const MIN_CONTRAST = 4.5;

    /** What a logo may be. Decided by reading the bytes, never the name. */
    public const TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'];

    /**
     * The brand in force for one report.
     *
     * @param  array<string, mixed>  $config  the `report.brand` config block
     * @return array{logo: ?array<string, mixed>, logo_from: ?string, accent: ?string, accent_from: ?string, dropped: array<int, string>}
     */
    public static function resolve(array $config, ?string $site): array
    {
        $global = $config;
        unset($global['sites'], $global['container']);

        // A report about more than one site wears no site's mark: it speaks
        // for all of them, and one site's logo on the cover would say
        // otherwise. The caller passes a site only when the report is about
        // exactly one, which is decided by what was reported on and not by
        // how the scan happened to be narrowed.
        $override = $site === null ? [] : (array) (($config['sites'] ?? [])[$site] ?? []);

        $brand = ['logo' => null, 'logo_from' => null, 'accent' => null, 'accent_from' => null, 'dropped' => []];

        // A logo and the words that stand in for it travel together, always
        // from the same level. A site that sets its own mark and no words
        // would otherwise inherit another organisation's name for its logo,
        // which is a wrong caption rather than a fallback.
        [$source, $alt, $from] = match (true) {
            self::filled($override['logo'] ?? null) => [(string) $override['logo'], $override['logo_alt'] ?? null, 'site:'.$site],
            self::filled($global['logo'] ?? null) => [(string) $global['logo'], $global['logo_alt'] ?? null, 'global'],
            default => [null, null, null],
        };

        if ($source !== null) {
            $logo = self::logo($source, is_string($alt) ? trim($alt) : '', $config['container'] ?? null);

            if (is_string($logo)) {
                $brand['dropped'][] = $logo;
            } else {
                $brand['logo'] = $logo;
                $brand['logo_from'] = $from;
            }
        }

        // The colour has no such pairing, so a site may take the mark from
        // the install and change only the ink.
        [$accent, $accentFrom] = match (true) {
            self::filled($override['accent'] ?? null) => [(string) $override['accent'], 'site:'.$site],
            self::filled($global['accent'] ?? null) => [(string) $global['accent'], 'global'],
            default => [null, null],
        };

        if ($accent !== null) {
            $colour = self::colour($accent);

            if ($colour === null) {
                $brand['dropped'][] = "The heading colour [{$accent}] is not a colour this can read, so the headings are left black.";
            } elseif (self::contrastOnWhite($colour) < self::MIN_CONTRAST) {
                $brand['dropped'][] = sprintf(
                    'The heading colour %s reaches %.1f to 1 against the white page, under the %.1f to 1 that success criterion 1.4.3 asks of text this size, so the headings are left black.',
                    $colour,
                    self::contrastOnWhite($colour),
                    self::MIN_CONTRAST,
                );
            } else {
                $brand['accent'] = $colour;
                $brand['accent_from'] = $accentFrom;
            }
        }

        return $brand;
    }

    /**
     * The logo, embedded, or one sentence saying why there is not one.
     *
     * @return array<string, mixed>|string
     */
    private static function logo(string $source, string $alt, mixed $container): array|string
    {
        if ($alt === '') {
            return "The logo [{$source}] has no words to stand in for it, so it is left out. An image a reader cannot have described to them would fail the standard this document reports on.";
        }

        try {
            $bytes = self::read($source, is_string($container) ? $container : null);
        } catch (\Throwable $e) {
            // Whatever went wrong reading a picture, a report is still owed.
            return "The logo [{$source}] could not be read, so it is left out: ".$e->getMessage();
        }

        if ($bytes === null || $bytes === '') {
            return "The logo [{$source}] was not found, so it is left out.";
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            return sprintf('The logo [%s] is %d bytes, over the %d a cover mark may be, so it is left out.', $source, strlen($bytes), self::MAX_BYTES);
        }

        $type = self::mediaType($bytes);

        if ($type === null) {
            return "The logo [{$source}] is not one of ".implode(', ', self::TYPES).', so it is left out.';
        }

        if ($type === 'image/svg+xml' && ($why = self::unsafeDrawing($bytes)) !== null) {
            return "The logo [{$source}] is left out: {$why}.";
        }

        return [
            'source' => $source,
            'alt' => $alt,
            'media_type' => $type,
            'bytes' => strlen($bytes),
            // Which image this was, kept in the JSON after the bytes are
            // dropped from it. A report is evidence, and "the logo has since
            // been replaced" is a question somebody will eventually ask.
            'sha256' => hash('sha256', $bytes),
            'data_uri' => 'data:'.$type.';base64,'.base64_encode($bytes),
        ];
    }

    /**
     * The bytes behind a logo setting, in the three shapes it may be written
     * in: an asset with its container named, a file on this machine, or the
     * name of an asset in the container the settings screen picks from.
     */
    private static function read(string $source, ?string $container): ?string
    {
        if (str_contains($source, "\0")) {
            return null;
        }

        if (str_contains($source, '::')) {
            $asset = Asset::find($source);

            return $asset?->exists() ? (string) $asset->contents() : null;
        }

        if (is_file($source) && is_readable($source)) {
            return (string) file_get_contents($source);
        }

        // Written the way a person would write it: inside the site. Asked for
        // second and separately, because there is not always a site to be
        // inside of, and a logo somebody gave a full path to is still a logo.
        try {
            $inSite = base_path($source);
        } catch (\Throwable) {
            $inSite = null;
        }

        if ($inSite !== null && is_file($inSite) && is_readable($inSite)) {
            return (string) file_get_contents($inSite);
        }

        $handle = self::container($container);

        if ($handle === null) {
            return null;
        }

        $asset = AssetContainer::find($handle)?->asset($source);

        return $asset?->exists() ? (string) $asset->contents() : null;
    }

    /**
     * Which asset container the logo picker offers, or null when there is no
     * obvious one. Null is not a failure: the settings screen shows a plain
     * text field instead, and an asset picker with no container throws while
     * it renders, which would take the whole screen down with it.
     */
    public static function container(?string $configured): ?string
    {
        try {
            if (is_string($configured) && $configured !== '') {
                return AssetContainer::find($configured) !== null ? $configured : null;
            }

            $all = AssetContainer::all();

            return $all->count() === 1 ? (string) $all->first()->handle() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Read from the bytes, because a name proves nothing about a file. */
    private static function mediaType(string $bytes): ?string
    {
        if (stripos(substr($bytes, 0, 1024), '<svg') !== false) {
            return 'image/svg+xml';
        }

        $type = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return in_array($type, self::TYPES, true) ? $type : null;
    }

    /**
     * Why a drawing is not usable as a logo, if it is not.
     *
     * A scalable drawing is a document, and this one is embedded in a
     * document that is filed as evidence and mailed to lawyers. Script,
     * declared entities and anything fetched from elsewhere are all refused:
     * the print step will not fetch the last of them anyway, so a logo that
     * needs one would print as a hole.
     */
    private static function unsafeDrawing(string $bytes): ?string
    {
        if (preg_match('/<\s*(?:script|foreignObject|iframe)\b/i', $bytes)) {
            return 'a drawing that carries script is not a logo';
        }

        if (preg_match('/<!ENTITY/i', $bytes)) {
            return 'it declares entities of its own, which a reader would have to resolve to see it';
        }

        if (preg_match('/(?<![-\w])(?:xlink:href|href|src)\s*=\s*["\']?\s*(?:https?:)?\/\//i', $bytes)) {
            return 'it points at something that is not in the file, which the print step will not fetch';
        }

        return null;
    }

    /** A colour as `#rrggbb`, or null if it is not one this can read. */
    public static function colour(string $value): ?string
    {
        if (! preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($value), $m)) {
            return null;
        }

        $hex = strtolower($m[1]);

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return '#'.$hex;
    }

    /** The WCAG contrast ratio of a colour against the white page. */
    public static function contrastOnWhite(string $hex): float
    {
        $colour = self::colour($hex);

        if ($colour === null) {
            return 0.0;
        }

        $luminance = 0.0;

        foreach ([[1, 0.2126], [3, 0.7152], [5, 0.0722]] as [$offset, $weight]) {
            $channel = hexdec(substr($colour, $offset, 2)) / 255;
            $luminance += $weight * ($channel <= 0.03928 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4);
        }

        return round(1.05 / ($luminance + 0.05), 2);
    }

    /**
     * The brand section of the settings screen, built here rather than in
     * the form's own file because the logo field's shape depends on what the
     * site has: an asset picker needs a container, and there is not always
     * one to name.
     *
     * @param  array<string, mixed>  $blueprint
     * @return array<string, mixed>
     */
    public static function addSettingsSection(array $blueprint, ?string $container): array
    {
        if (! isset($blueprint['tabs']['report']['sections'])) {
            return $blueprint;
        }

        $blueprint['tabs']['report']['sections'][] = [
            'display' => 'Your mark on the cover',
            'instructions' => 'A logo and one colour, on every report generated from now on. Reports already generated keep the look they were filed with. Nothing else about the document changes: it is evidence, and evidence laid out differently for every organisation is harder to read.',
            'fields' => array_merge(self::markFields($container, 'brand_'), [
                [
                    'handle' => 'brand_sites',
                    'field' => [
                        'type' => 'grid',
                        'display' => 'A different mark for one of your sites',
                        'instructions' => 'For a site that belongs to somebody else. A site listed here uses what is set on its row and the settings above for anything its row leaves empty, except the logo: a logo and its words are taken together, so a site with its own logo needs its own words for it. A report covering every site at once uses the settings above, never one site\'s mark.',
                        'mode' => 'stacked',
                        'add_row' => 'Add a site',
                        'fields' => array_merge([[
                            'handle' => 'site',
                            'field' => ['type' => 'sites', 'display' => 'Site', 'mode' => 'select', 'max_items' => 1, 'width' => 50],
                        ]], self::markFields($container, '')),
                    ],
                ],
            ]),
        ];

        return $blueprint;
    }

    /**
     * The three fields of a mark, used for the whole install and again for
     * each site that differs from it.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function markFields(?string $container, string $prefix): array
    {
        $logo = $container === null
            ? [
                'type' => 'text',
                'display' => 'Logo',
                'instructions' => 'Where the logo file sits inside this site\'s own folder, for example public/img/logo.svg. A picture chooser appears here instead when this site keeps its pictures in one place.',
            ]
            : [
                'type' => 'assets',
                'display' => 'Logo',
                'instructions' => 'Printed at the top of the cover, at about the size of a letterhead. PNG, JPEG, WebP or SVG, under 384 KB.',
                'container' => $container,
                'max_files' => 1,
                'mode' => 'list',
            ];

        return [
            ['handle' => $prefix.'logo', 'field' => $logo],
            [
                'handle' => $prefix.'logo_alt',
                'field' => [
                    'type' => 'text',
                    'display' => 'What the logo says',
                    'instructions' => 'The words read out to somebody who cannot see the logo. Usually just the name of the organisation. Without them the logo is left out, because an image nobody can have described to them would fail the standard this document reports on.',
                    'width' => 50,
                ],
            ],
            [
                'handle' => $prefix.'accent',
                'field' => [
                    'type' => 'color',
                    'display' => 'Heading colour',
                    'instructions' => 'The headings, and nothing else. It has to be dark enough to read on white paper; one that is not is ignored, and the headings stay black.',
                    'width' => 50,
                ],
            ],
        ];
    }

    private static function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
