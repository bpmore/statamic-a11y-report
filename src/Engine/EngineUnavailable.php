<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Engine;

/**
 * The engine a scan was started with cannot be built on this machine.
 *
 * Thrown rather than answered with a different engine, because the scan row
 * has already said which one ran and a report is generated from that row. A
 * worker that quietly read the pages with the lesser engine would fill an axe
 * scan with the PHP checker's findings, and the document would say two dozen
 * criteria were evaluated automatically of a scan that evaluated six.
 *
 * The page is marked as one that could not be read, which is the honest
 * answer and the one a reader of the report can act on.
 */
final class EngineUnavailable extends \RuntimeException {}
