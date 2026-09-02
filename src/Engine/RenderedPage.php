<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Engine;

/**
 * A page as the site served it, and the address it was served at.
 *
 * The URL travels with the markup because the fingerprint needs it and because
 * an engine that fetches for itself (Chrome) needs somewhere to go.
 */
final class RenderedPage
{
    public function __construct(
        public readonly string $url,
        public readonly string $html,
    ) {}
}
