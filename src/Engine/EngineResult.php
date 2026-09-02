<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Engine;

/**
 * The findings, and how much of the page the engine could see while finding
 * them. The two travel together for the same reason `CheckReport` keeps them
 * together: a list of findings on its own reads as "nothing else is wrong".
 */
final class EngineResult
{
    /**
     * @param  array<int, Finding>  $findings
     * @param  array<int, array<string, string>>  $coverage  one entry per check family, in the engine's own words
     */
    public function __construct(
        public readonly array $findings,
        public readonly array $coverage,
        public readonly string $coverageSummary,
    ) {}
}
