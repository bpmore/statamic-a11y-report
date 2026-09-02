<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Jobs;

use Bpmore\A11yReport\Jobs\Middleware\LimitsConcurrency;
use Bpmore\A11yReport\Scan\Scans;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Render one page, run the engine, write the rows.
 *
 * Everything that can go wrong on a page is caught inside the service and
 * written onto the page's row, so this job does not fail for a template that
 * throws. The retries are for the other kind of failure: a worker killed
 * mid-render, a database that went away. A retried job finds its page already
 * marked and does nothing, so retrying is always safe.
 */
final class ScanPage implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(
        public readonly int $scanId,
        public readonly int $pageId,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new LimitsConcurrency];
    }

    public function handle(Scans $scans): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $scans->scanPage($this->scanId, $this->pageId);
    }

    public function failed(?Throwable $e): void
    {
        app(Scans::class)->markPageFailed($this->scanId, $this->pageId, $e);
    }
}
