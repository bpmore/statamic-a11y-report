<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Engine\Axe;

/**
 * The script axe is run with, and the shape it hands back.
 *
 * Shaped inside the page rather than returned whole. A full axe result for a
 * large page is megabytes of passing nodes that this addon throws away, and
 * every byte of it would cross a socket and be decoded first. What comes back
 * is the violations, the results axe could not decide (which is coverage, and
 * the reason a clean page and an unread page do not look alike here), and the
 * rule ids of everything else so the coverage can be counted by category.
 */
final class AxeRun
{
    /** How much of an element's markup is worth keeping as evidence. */
    public const SNIPPET_LIMIT = 300;

    /**
     * @param  array<int, string>  $tags
     */
    public static function script(array $tags): string
    {
        $options = json_encode([
            'runOnly' => ['type' => 'tag', 'values' => array_values($tags)],
            // Nodes are kept only where they are read. Everything else comes
            // back as a rule id, which is all the coverage count needs.
            'resultTypes' => ['violations', 'incomplete'],
        ], JSON_THROW_ON_ERROR);

        $limit = self::SNIPPET_LIMIT;

        return <<<JS
            axe.run(document, {$options}).then(function (r) {
                var cut = function (s) {
                    return typeof s === 'string' && s.length > {$limit} ? s.slice(0, {$limit}) : (s || '');
                };
                var tagsOf = function (v) { return v.tags || []; };

                return JSON.stringify({
                    engine: r.testEngine,
                    violations: r.violations.map(function (v) {
                        return {
                            id: v.id,
                            impact: v.impact,
                            help: v.help,
                            helpUrl: v.helpUrl,
                            tags: tagsOf(v),
                            nodes: v.nodes.map(function (n) {
                                return {
                                    target: n.target,
                                    html: cut(n.html),
                                    impact: n.impact,
                                    summary: cut(n.failureSummary)
                                };
                            })
                        };
                    }),
                    incomplete: r.incomplete.map(function (v) {
                        return { id: v.id, help: v.help, tags: tagsOf(v), nodes: v.nodes.length };
                    }),
                    passes: r.passes.map(function (v) { return { id: v.id, tags: tagsOf(v) }; }),
                    inapplicable: r.inapplicable.map(function (v) { return { id: v.id, tags: tagsOf(v) }; })
                });
            })
            JS;
    }

    /**
     * A node's target, as one string.
     *
     * axe gives an array, and an array of arrays when the element is inside a
     * frame. Flattened with the frame path kept, because the fingerprint that
     * follows a problem from one scan to the next is built on this and two
     * different elements must not flatten to the same string.
     *
     * @param  mixed  $target
     */
    public static function selector($target): string
    {
        if (is_string($target)) {
            return $target;
        }

        if (! is_array($target)) {
            return '';
        }

        return implode(' >>> ', array_map(fn ($part) => self::selector($part), $target));
    }
}
