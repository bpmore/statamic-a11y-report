<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Jobs;

use Bpmore\A11yReport\Scan\Scans;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Roll the scan up once the batch is done. Dispatched from the batch's own
 * `finally`, on the same connection the batch ran on, so a `--sync` scan
 * finishes in the same process and a queued one finishes on a worker.
 */
final class FinalizeScan implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $scanId,
        public readonly bool $cancelled = false,
    ) {}

    public function handle(Scans $scans): void
    {
        $scans->finalize($this->scanId, $this->cancelled);
    }
}
