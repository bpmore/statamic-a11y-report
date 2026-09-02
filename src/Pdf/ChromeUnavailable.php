<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Pdf;

use RuntimeException;

/**
 * No Chrome to print with, or Chrome did not produce a file. The HTML and
 * JSON still exist; only the PDF does not, and whoever asked is told so.
 */
final class ChromeUnavailable extends RuntimeException {}
