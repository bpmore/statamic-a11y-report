<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Engine;

use Bpmore\A11yGate\Accessibility\AccessibilityStandard;
use Bpmore\A11yGate\Accessibility\Coverage;
use Bpmore\A11yGate\Accessibility\Remediation;
use Bpmore\A11yGate\Accessibility\StaticAccessibilityChecker;
use Bpmore\A11yGate\Accessibility\Violation;

/**
 * The gate's own checker, run out of band.
 *
 * It is the same `StaticAccessibilityChecker`, with the same standard and the
 * same opt-in list the gate reads, so a page the gate refuses and a page the
 * scan flags are the same page for the same reason. Two ways of checking a
 * page is two sets of answers, and this engine exists so that there is one.
 *
 * Framework-free, like the checker it wraps. The version is handed in because
 * finding it out means asking Statamic, and this class must not.
 *
 * **Impact is a mapping, and it is written down here.** The checker rates a
 * finding as an error (the gate refuses) or a warning (it does not); it has no
 * opinion on how badly a visitor is affected. `serious` for an error and
 * `moderate` for a warning is the only defensible translation into the four
 * levels the report uses: an error is something the gate will not publish, and
 * nothing here is graded `critical` because the checker cannot see the things
 * that usually earn it, colour contrast above all.
 */
final class PhpDomEngine implements ScanEngine
{
    public const KEY = 'php';

    /**
     * @param  array<int, string>  $optedIn
     */
    public function __construct(
        private readonly StaticAccessibilityChecker $checker,
        private readonly string $version,
        private readonly AccessibilityStandard $standard = AccessibilityStandard::Wcag22aa,
        private readonly array $optedIn = [],
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function ruleset(): string
    {
        return $this->standard->value;
    }

    public function criteria(): array
    {
        $criteria = [];

        foreach (Remediation::RULES as $rule) {
            foreach (self::criteria_of($rule['wcag']) as $number) {
                $criteria[$number] = $number;
            }
        }

        $criteria = array_values($criteria);
        usort($criteria, 'version_compare');

        return $criteria;
    }

    public function scan(RenderedPage $page): EngineResult
    {
        $report = $this->checker->report($page->html, $this->standard, $this->optedIn);

        return new EngineResult(
            array_map(fn (Violation $v) => self::finding($v), $report->violations),
            array_map(fn (Coverage $c) => $c->toArray(), $report->coverage),
            $report->summary(),
        );
    }

    public static function finding(Violation $violation): Finding
    {
        return new Finding(
            ruleId: $violation->rule,
            label: $violation->wcag,
            criteria: self::criteria_of($violation->wcag),
            impact: $violation->isError() ? Finding::SERIOUS : Finding::MODERATE,
            message: $violation->message,
            remedy: $violation->cta,
            pointer: $violation->pointer !== '' ? $violation->pointer : null,
        );
    }

    /**
     * "WCAG 2.4.4" gives ['2.4.4']. "Heading structure" gives nothing, and
     * must: the label is a house rule precisely because no criterion covers
     * it, and parsing it into one would be citing what the check cannot
     * establish, one table further downstream than the rule was written for.
     *
     * @return array<int, string>
     */
    public static function criteria_of(string $label): array
    {
        return preg_match('/^WCAG (\d+\.\d+\.\d+)$/', $label, $m) ? [$m[1]] : [];
    }
}
