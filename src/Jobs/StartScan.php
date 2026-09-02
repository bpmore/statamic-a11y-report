<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Jobs;

use Bpmore\A11yReport\Models\Scan;
use Bpmore\A11yReport\Scan\Scans;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Enumerate the pages and dispatch the batch, on a worker.
 *
 * The command calls the service directly, because it is already in a process
 * that can take as long as it likes. This job is for the callers that are
 * not: a control-panel button and the scheduler.
 */
final class StartScan implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $scanId) {}

    public function handle(Scans $scans): void
    {
        $scan = Scan::find($this->scanId);

        if ($scan !== null && $scan->status === Scan::QUEUED) {
            $scans->start($scan);
        }
    }
}
