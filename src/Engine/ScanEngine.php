<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Engine;

/**
 * Something that reads a rendered page and reports what it found and how much
 * it could see.
 *
 * Two implementations are planned, and the interface is what lets the report
 * not care which ran: the gate's own PHP checks, and axe-core in headless
 * Chrome. Every scan records the engine's key and version, because two engines
 * give two different answers and a report that did not say which one it used
 * would be quoting a number with no source.
 */
interface ScanEngine
{
    /** Stable identifier written onto every scan: 'php', 'axe'. */
    public function key(): string;

    /** The version of whatever produced the findings, written onto every scan. */
    public function version(): string;

    /**
     * Every success criterion this engine's rules can cite, as "1.4.3".
     *
     * The conformance report uses it to say which criteria were evaluated
     * automatically and which were not evaluated at all. Derived from the
     * rules, never typed by hand, so the list cannot drift from what the
     * engine actually does.
     *
     * @return array<int, string>
     */
    public function criteria(): array;

    public function scan(RenderedPage $page): EngineResult;
}
