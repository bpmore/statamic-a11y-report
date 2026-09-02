<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Jobs\Middleware;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * At most N pages rendering at once, across every worker.
 *
 * A site rendering its own pages as fast as its queue allows is a load test
 * nobody asked for, on a Statamic site that renders through PHP, so this is
 * the setting that keeps a scan from taking the site down. Implemented as N
 * cache locks rather than a Redis funnel so it works on the file cache a
 * flat-file site has, and a job that finds every slot taken goes back on the
 * queue for a few seconds rather than waiting in a worker.
 *
 * Skipped on the sync connection, where there is one process and no queue to
 * go back onto, and skipped with a warning on a cache store that cannot lock,
 * because refusing to scan is worse than scanning at whatever the worker
 * count allows.
 */
final class LimitsConcurrency
{
    private static bool $warned = false;

    public function handle(object $job, Closure $next): mixed
    {
        $slots = max(1, (int) config('statamic-a11y-report.scan.concurrency', 3));

        if (($job->job ?? null) instanceof SyncJob) {
            return $next($job);
        }

        $store = Cache::getStore();

        if (! $store instanceof LockProvider) {
            if (! self::$warned) {
                Log::warning('a11y-report: the cache store cannot hold locks, so the scan concurrency limit is not enforced.');
                self::$warned = true;
            }

            return $next($job);
        }

        for ($slot = 0; $slot < $slots; $slot++) {
            $lock = Cache::lock("a11y-report:scan-slot:{$slot}", 300);

            if ($lock->get()) {
                try {
                    return $next($job);
                } finally {
                    $lock->release();
                }
            }
        }

        $job->release(5);

        return null;
    }
}
