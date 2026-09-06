<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Chrome;

/**
 * The browser is fine; this page is not readable.
 *
 * Separate from its parent so a scan can tell the two apart, because they
 * deserve opposite treatment. A renderer killed under memory pressure is
 * worth throwing the browser away and trying once more. An address that
 * answers 404, a host that does not resolve, or a page that never finishes
 * loading will do the same thing the second time, and retrying costs a browser
 * start on every broken route on the site.
 *
 * Both end the same way for the report: a page that could not be read, counted
 * separately and never as clean.
 */
final class PageNotReadable extends ChromeProtocolError {}
