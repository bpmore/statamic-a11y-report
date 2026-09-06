<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Engine;

use Bpmore\A11yReport\Chrome\ChromeProtocolError;
use Bpmore\A11yReport\Chrome\DevTools;
use Bpmore\A11yReport\Chrome\PageNotReadable;
use Bpmore\A11yReport\Engine\Axe\AxeRun;
use Bpmore\A11yReport\Engine\Axe\AxeSource;

/**
 * axe-core, in headless Chrome, reading the page the site actually served.
 *
 * The second engine, and the reason the interface exists. The PHP checker
 * reads markup and can speak to six success criteria; this reads a rendered
 * document with its stylesheets applied and can speak to two dozen, colour
 * contrast among them, which is the failure a real site has most of and the
 * one no amount of reading markup will ever find.
 *
 * **It goes to the page rather than being handed the markup.** The whole
 * point is the things only a browser knows: computed colour, layout, what
 * ended up in the accessibility tree. Feeding it the rendered HTML would
 * throw all of that away and leave a slower copy of the engine we already
 * have. The cost is stated plainly in the report: the site has to be
 * reachable from the machine running the scan, and a page Chrome could not
 * open is a page that could not be read, never a page with nothing wrong.
 *
 * **Level A and AA only, and never AAA.** The report table has no AAA row,
 * for the reason written down when it was built: WCAG advises against
 * requiring AAA site-wide and a table listing it invites a claim nothing here
 * can support. Running the rules anyway would produce findings citing
 * criteria the document has nowhere to put.
 *
 * **axe's best-practice rules cite no success criterion**, so they are house
 * rules by the definition this product already uses: they keep their plain
 * name into the database and into the document's "Findings outside WCAG"
 * section, and they can be turned off.
 */
final class AxeEngine implements ScanEngine
{
    public const KEY = 'axe';

    /** Read once per page, and the session outlives the page. */
    private ?string $scriptIdentifier = null;

    /**
     * @param  string  $standard  the report standard this scan targets, as `Wcag::STANDARDS` keys it
     */
    public function __construct(
        private readonly DevTools $chrome,
        private readonly string $standard = 'wcag22aa',
        private readonly bool $bestPractices = true,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function version(): string
    {
        return AxeSource::version();
    }

    public function ruleset(): string
    {
        // The tags that ran, not the standard that was asked for. Two scans
        // that ran different rules must not carry the same ruleset, or the
        // difference between their numbers has no explanation on the row.
        return $this->standard.($this->bestPractices ? '+best-practice' : '');
    }

    /**
     * @return array<int, string>
     */
    public function criteria(): array
    {
        return AxeSource::criteriaFor(AxeSource::tagsForStandard($this->standard));
    }

    /** The axe tags this engine runs with. */
    public function tags(): array
    {
        $tags = AxeSource::tagsForStandard($this->standard);

        return $this->bestPractices ? [...$tags, AxeSource::BEST_PRACTICE_TAG] : $tags;
    }

    public function scan(RenderedPage $page): EngineResult
    {
        $result = $this->run($page->url);

        $findings = [];

        foreach ($result['violations'] ?? [] as $violation) {
            foreach ($violation['nodes'] ?? [] as $node) {
                $findings[] = self::finding($violation, $node);
            }
        }

        $coverage = self::coverage($result);

        return new EngineResult($findings, $coverage, self::summary($coverage));
    }

    /** Let go of the browser. A scan that has finished should not hold one open. */
    public function close(): void
    {
        $this->chrome->close();
        $this->scriptIdentifier = null;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ChromeProtocolError
     */
    private function run(string $url): array
    {
        try {
            return $this->attempt($url);
        } catch (PageNotReadable $e) {
            // The browser is fine and this page is not. A 404, a host that
            // does not resolve and a page that never finishes loading will all
            // do the same thing the second time, and retrying would cost a
            // browser start on every broken route on the site.
            throw $e;
        } catch (ChromeProtocolError $e) {
            // One retry, on a browser that has been thrown away. Chrome dies
            // mid-scan for reasons that have nothing to do with the page: a
            // renderer killed under memory pressure on a long run is the
            // ordinary one. Failing the page for that would put "could not be
            // read" in a conformance document about a page that reads fine.
            $this->close();

            return $this->attempt($url);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ChromeProtocolError
     */
    private function attempt(string $url): array
    {
        if ($this->scriptIdentifier === null || ! $this->chrome->isOpen()) {
            $this->chrome->open();
            $this->scriptIdentifier = $this->chrome->onEveryDocument(AxeSource::source());
        }

        $this->chrome->navigate($url);

        $json = $this->chrome->evaluate(AxeRun::script($this->tags()));

        if (! is_string($json) || $json === '') {
            throw new ChromeProtocolError("axe-core returned nothing for {$url}, so the page cannot be reported on.");
        }

        $result = json_decode($json, true);

        if (! is_array($result)) {
            throw new ChromeProtocolError("axe-core returned something that is not a result for {$url}.");
        }

        $ran = (string) ($result['engine']['version'] ?? '');

        if ($ran !== '' && $ran !== AxeSource::version()) {
            // The page shipped its own axe and won. Everything downstream
            // records the bundled version, so the numbers would be attributed
            // to rules that did not produce them.
            throw new PageNotReadable("axe-core {$ran} ran on {$url} instead of the bundled ".AxeSource::version().'.');
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $violation
     * @param  array<string, mixed>  $node
     */
    public static function finding(array $violation, array $node): Finding
    {
        $tags = (array) ($violation['tags'] ?? []);
        $criteria = AxeSource::criteriaOfTags($tags);

        return new Finding(
            ruleId: (string) ($violation['id'] ?? 'unknown'),
            // A rule that cites a criterion is labelled with it, the way every
            // other finding in this product is. A rule that cites none keeps
            // its own plain name and never borrows one.
            label: $criteria === [] ? self::plainName((string) ($violation['id'] ?? '')) : 'WCAG '.$criteria[0],
            criteria: $criteria,
            impact: self::impact($node['impact'] ?? null, $violation['impact'] ?? null),
            message: (string) ($violation['help'] ?? ''),
            remedy: (string) ($node['summary'] ?? ''),
            selector: AxeRun::selector($node['target'] ?? null) ?: null,
            snippet: ($html = (string) ($node['html'] ?? '')) !== '' ? $html : null,
            helpUrl: ($url = (string) ($violation['helpUrl'] ?? '')) !== '' ? $url : null,
        );
    }

    /**
     * axe grades every finding in the same four words this report uses, so
     * this is a check rather than a translation. A node's own grade wins over
     * the rule's: the same rule is worse on some elements than others.
     */
    private static function impact(mixed $node, mixed $rule): string
    {
        foreach ([$node, $rule] as $candidate) {
            if (is_string($candidate) && in_array($candidate, Finding::IMPACTS, true)) {
                return $candidate;
            }
        }

        // axe leaves impact null on a rule whose checks all passed for some
        // nodes; the finding is still real, and guessing high would inflate
        // the counts a deploy pipeline fails on.
        return Finding::MODERATE;
    }

    /** 'heading-order' reads as 'Heading order' and stays that rule for ever. */
    public static function plainName(string $ruleId): string
    {
        return ucfirst(str_replace('-', ' ', $ruleId));
    }

    /**
     * How much of the page axe could decide, by the categories its own rules
     * are filed under.
     *
     * A rule with nothing to look at counts as full, not as a gap: a page with
     * no images is a complete answer for the image rules. What makes a
     * category partial is axe returning "incomplete", which is its word for a
     * result a person has to settle, and which is exactly the distinction
     * between a check that passed and a check that could not tell.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, string>>
     */
    public static function coverage(array $result): array
    {
        $categories = [];

        foreach (['violations', 'incomplete', 'passes', 'inapplicable'] as $bucket) {
            foreach ((array) ($result[$bucket] ?? []) as $rule) {
                foreach ((array) ($rule['tags'] ?? []) as $tag) {
                    if (! str_starts_with($tag, 'cat.')) {
                        continue;
                    }

                    $categories[$tag] ??= ['rules' => 0, 'undecided' => []];
                    $categories[$tag]['rules']++;

                    if ($bucket === 'incomplete') {
                        $categories[$tag]['undecided'][] = trim((string) ($rule['help'] ?? $rule['id'] ?? ''));
                    }
                }
            }
        }

        ksort($categories);
        $coverage = [];

        foreach ($categories as $tag => $counts) {
            $undecided = array_values(array_unique(array_filter($counts['undecided'])));

            $coverage[] = [
                'check' => $tag,
                'name' => self::categoryName($tag),
                'extent' => $undecided === [] ? 'full' : 'partial',
                'limit' => $undecided === []
                    ? ''
                    : 'axe could not decide: '.implode('; ', $undecided).'.',
                'notice' => '',
            ];
        }

        return $coverage;
    }

    /**
     * The same sentence shape the PHP engine produces, so a report covering
     * pages read by either can group them without two dialects of the same
     * fact.
     *
     * @param  array<int, array<string, string>>  $coverage
     */
    public static function summary(array $coverage): string
    {
        $total = count($coverage);

        if ($total === 0) {
            return 'No check reported on this page.';
        }

        $partly = count(array_filter($coverage, fn (array $c) => $c['extent'] === 'partial'));
        $parts = [($total - $partly).' of '.$total.' checks ran in full'];

        if ($partly > 0) {
            $parts[] = $partly.' ran partly';
        }

        return implode(', ', $parts).'.';
    }

    /** 'cat.text-alternatives' reads as 'Text alternatives'. */
    private static function categoryName(string $tag): string
    {
        $name = str_replace('-', ' ', substr($tag, 4));

        // The one word this would otherwise get wrong, in a product that
        // should not print "Aria" on a screen about accessibility.
        return $name === 'aria' ? 'ARIA' : ucfirst($name);
    }
}
