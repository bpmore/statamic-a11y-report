<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Document;

/**
 * One WCAG success criterion, as the conformance table names it.
 */
final class Criterion
{
    public function __construct(
        public readonly string $number,
        public readonly string $name,
        public readonly string $level,
        /** The WCAG version that introduced it: '2.0', '2.1', '2.2'. */
        public readonly string $since,
        /** The WCAG version that removed it, or null. */
        public readonly ?string $removedIn = null,
    ) {}

    public function isIn(string $version): bool
    {
        return version_compare($this->since, $version, '<=')
            && ($this->removedIn === null || version_compare($version, $this->removedIn, '<'));
    }
}
