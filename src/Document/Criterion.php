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

    /**
     * The name as the W3C spells it in a URL: "Audio-only and Video-only
     * (Prerecorded)" is audio-only-and-video-only-prerecorded. Derived, not
     * stored, because every one of the 56 follows the rule and a second
     * column would be a second place for a typo. A test checks every
     * derived URL against the W3C, so a rename that breaks the rule is seen.
     */
    public function slug(): string
    {
        return trim((string) preg_replace('/-+/', '-', (string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($this->name))), '-');
    }

    /**
     * The W3C's "Understanding" page for this criterion in the given WCAG
     * version: what it asks, why, and how to meet it, from the people who
     * wrote it. Linked rather than paraphrased, because a summary here
     * would be one more place a criterion could be misdescribed.
     *
     * A criterion newer than the version asked for links to the version
     * that introduced it: 2.5.8 has no page under WCAG 2.1, and an engine
     * running the 2.2 ruleset can cite it while the report is set to 2.1.
     */
    public function understandingUrl(string $version): string
    {
        if (version_compare($version, $this->since, '<')) {
            $version = $this->since;
        }

        return sprintf('https://www.w3.org/WAI/WCAG%s/Understanding/%s.html', str_replace('.', '', $version), $this->slug());
    }
}
