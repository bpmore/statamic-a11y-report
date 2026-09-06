<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Chrome;

/**
 * Chrome could not be started, could not be talked to, or did not answer.
 *
 * Always a page that could not be read, and never a page with no findings.
 * The scan marks it errored, which is what stops an unreachable site being
 * reported as a clean one.
 */
class ChromeProtocolError extends \RuntimeException {}
