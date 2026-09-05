<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Engine\Axe;

/**
 * The copy of axe-core this addon ships, and what can be read out of it
 * without starting a browser.
 *
 * Bundled rather than fetched or installed. A conformance report has to be
 * reproducible: the same install scanning the same site next year must run the
 * same rules, and a version resolved at run time from a CDN or from whatever
 * `npm install` last wrote is a number the document cannot stand behind. It
 * also means a site with no outbound network still scans. axe-core is
 * MPL-2.0 and travels with its licence header intact; nothing here modifies it.
 *
 * The version and the rule tags are read out of that same file, never typed
 * here. The interface asks for the criteria an engine can cite and says why:
 * a hand-kept list drifts from what the engine does, and the drift shows up
 * as a conformance table claiming an evaluation nobody ran. A test compares
 * what this parses against what a real axe reports from `axe.getRules()`, so
 * an upgrade that changes the bundle's shape fails loudly instead of quietly
 * returning nothing.
 */
final class AxeSource
{
    /**
     * Tags for the rules that carry a Level A or AA success criterion, by the
     * WCAG version the report is written against.
     *
     * A report whose table is 2.1 must not be handed a 2.2 finding: the
     * criterion it cites has no row to sit in, and a number in a conformance
     * document with nowhere to be placed is worse than one that was never
     * counted.
     */
    public const AA_TAGS = [
        'wcag21aa' => ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'],
        'wcag22aa' => ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22a', 'wcag22aa'],
    ];

    /**
     * axe's own rules that cite no success criterion. They are house rules by
     * exactly the definition this product already uses, and they keep their
     * plain name all the way into the document.
     */
    public const BEST_PRACTICE_TAG = 'best-practice';

    private static ?string $source = null;

    /** @var array<string, array<int, string>>|null rule id => tags */
    private static ?array $rules = null;

    public static function path(): string
    {
        return dirname(__DIR__, 3).'/resources/js/axe.min.js';
    }

    /** @throws \RuntimeException when the bundle is missing */
    public static function source(): string
    {
        if (self::$source !== null) {
            return self::$source;
        }

        $path = self::path();

        if (! is_file($path)) {
            throw new \RuntimeException("axe-core is missing from this install: there is no file at {$path}.");
        }

        return self::$source = (string) file_get_contents($path);
    }

    /** The version the bundle declares, as axe itself reports it. */
    public static function version(): string
    {
        if (preg_match('/axe\.version\s*=\s*"([0-9][0-9A-Za-z.\-]*)"/', self::source(), $m) !== 1) {
            throw new \RuntimeException('The bundled axe-core does not declare a version, so a scan could not record which rules it ran.');
        }

        return $m[1];
    }

    /**
     * Every rule in the bundle, with its tags.
     *
     * Read out of the minified source. Rules are the only objects in it that
     * carry a `tags` array, and each rule's `id` is the nearest one before its
     * tags, which is checked rather than assumed: every rule axe ships carries
     * a `cat.` tag, so anything parsed without one means the shape has moved
     * and the result cannot be trusted.
     *
     * @return array<string, array<int, string>>
     *
     * @throws \RuntimeException
     */
    public static function rules(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }

        $source = self::source();
        $rules = [];

        if (preg_match_all('/,tags:\[([^\]]*)\]/', $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === 0) {
            throw new \RuntimeException('No rules could be read out of the bundled axe-core.');
        }

        foreach ($matches as $match) {
            $before = substr($source, 0, $match[0][1]);
            $at = strrpos($before, 'id:"');

            if ($at === false) {
                continue;
            }

            $id = substr($before, $at + 4, (int) strpos($before, '"', $at + 4) - $at - 4);
            preg_match_all('/"([^"]+)"/', $match[1][0], $tags);

            $rules[$id] = $tags[1];
        }

        foreach ($rules as $id => $tags) {
            if (array_filter($tags, fn (string $t) => str_starts_with($t, 'cat.')) === []) {
                throw new \RuntimeException("The bundled axe-core parsed [{$id}] as a rule with no category, so its shape is not what this expects.");
            }
        }

        if ($rules === []) {
            throw new \RuntimeException('No rules could be read out of the bundled axe-core.');
        }

        return self::$rules = $rules;
    }

    /**
     * The success criteria the rules under these tags can cite.
     *
     * @param  array<int, string>  $tags
     * @return array<int, string>
     */
    /**
     * The tags to run for a report standard, always Level A and AA and never
     * AAA. The report table has no AAA row and there will not be one, so
     * running the rules would produce findings citing criteria the document
     * has nowhere to put.
     *
     * @return array<int, string>
     */
    public static function tagsForStandard(string $standard): array
    {
        return self::AA_TAGS[$standard] ?? self::AA_TAGS['wcag22aa'];
    }

    public static function criteriaFor(array $tags): array
    {
        $criteria = [];

        foreach (self::rules() as $rule) {
            if (array_intersect($rule, $tags) === []) {
                continue;
            }

            foreach (self::criteriaOfTags($rule) as $number) {
                $criteria[$number] = $number;
            }
        }

        $criteria = array_values($criteria);
        usort($criteria, 'version_compare');

        return $criteria;
    }

    /**
     * "wcag111" gives 1.1.1 and "wcag1410" gives 1.4.10.
     *
     * The principle and the guideline are always one digit each and the
     * criterion is what is left, which is why this is a split and not three
     * greedy groups: WCAG has criteria numbered past nine and 1.4.10 read as
     * 1.4.1 followed by a stray zero would cite the wrong criterion in a
     * conformance table.
     *
     * @param  array<int, string>  $tags
     * @return array<int, string>
     */
    public static function criteriaOfTags(array $tags): array
    {
        $criteria = [];

        foreach ($tags as $tag) {
            if (preg_match('/^wcag(\d)(\d)(\d+)$/', $tag, $m) === 1) {
                $criteria[] = "{$m[1]}.{$m[2]}.{$m[3]}";
            }
        }

        usort($criteria, 'version_compare');

        return array_values(array_unique($criteria));
    }

    /** Only for tests that need to re-read the file after changing it. */
    public static function forget(): void
    {
        self::$source = null;
        self::$rules = null;
    }
}
