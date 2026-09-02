<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Commands;

use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Site;
use Statamic\Facades\StaticCache;

/**
 * Drop the statement's page from the static cache on every site, so a
 * statement that reads live from the latest report is also what visitors
 * see after a new report on a site that caches its pages.
 */
class StatementRefresh extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:a11y:statement:refresh';

    protected $description = 'Clear the accessibility statement page from the static cache on every site.';

    public function handle(): int
    {
        $route = '/'.ltrim((string) config('statamic-a11y-report.statement.route', '/accessibility'), '/');

        $urls = Site::all()->map(fn ($site) => rtrim((string) $site->absoluteUrl(), '/').$route)->unique()->values();

        try {
            StaticCache::driver()->invalidateUrls($urls->all());
        } catch (\Throwable $e) {
            $this->error('Could not invalidate the static cache: '.$e->getMessage());

            return 1;
        }

        foreach ($urls as $url) {
            $this->line("  Refreshed {$url}");
        }

        return 0;
    }
}
