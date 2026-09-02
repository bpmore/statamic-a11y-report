<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Commands;

use Bpmore\A11yReport\Storage\ReportDatabase;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;

/**
 * Create the report's tables where the config says they go.
 *
 * `a11y:scan` runs this itself when the tables are the addon's own SQLite
 * file, because that file is nobody else's to worry about. On any other
 * connection the tables land in a database somebody administers, and that
 * person runs this on purpose.
 */
class Install extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:a11y:report:install';

    protected $description = 'Create the tables the accessibility report keeps its scans in.';

    public function handle(ReportDatabase $database): int
    {
        foreach ($database->install() as $line) {
            $this->line('  '.$line);
        }

        return 0;
    }
}
