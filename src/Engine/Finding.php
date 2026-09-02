<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Engine;

/**
 * One problem, in the shape every engine has to produce.
 *
 * `label` is what the engine allows the finding to cite, verbatim, and
 * `criteria` is only ever what can be parsed out of it. A house rule such as
 * "Heading structure" has a label and no criteria, and it stays that way all
 * the way into the report: the gate's rule that a check never cites a success
 * criterion it cannot establish is not suspended because the finding went
 * through a database.
 *
 * `selector` is where an engine that can point at an element does so. `pointer`
 * is what the PHP checker offers instead: the text or the file that tripped
 * the rule. Either one is enough for the fingerprint. Both may be empty for a
 * rule that speaks about the page as a whole.
 */
final class Finding
{
    public const CRITICAL = 'critical';

    public const SERIOUS = 'serious';

    public const MODERATE = 'moderate';

    public const MINOR = 'minor';

    public const IMPACTS = [self::CRITICAL, self::SERIOUS, self::MODERATE, self::MINOR];

    /**
     * @param  array<int, string>  $criteria  success criteria as "1.4.3", parsed from the label, never invented
     */
    public function __construct(
        public readonly string $ruleId,
        public readonly string $label,
        public readonly array $criteria,
        public readonly string $impact,
        public readonly string $message,
        public readonly string $remedy = '',
        public readonly ?string $selector = null,
        public readonly ?string $pointer = null,
        public readonly ?string $snippet = null,
        public readonly ?string $helpUrl = null,
    ) {}

    /**
     * What the fingerprint is built on: the selector when the engine gave one,
     * otherwise the pointer, otherwise nothing but the rule and the page.
     */
    public function target(): string
    {
        return (string) ($this->selector ?: $this->pointer ?: '');
    }
}
