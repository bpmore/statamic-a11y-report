<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Storage;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Where the report keeps its tables, and how they get there.
 *
 * One connection name, read from config, that every model and migration asks
 * this class for rather than reading config themselves. The default is a SQLite
 * file the addon owns outright under storage/, so a flat-file site has nothing
 * to set up. A site with a real database points the setting at one of its own
 * connections and gets the same tables there.
 *
 * The migrations are deliberately NOT registered with `loadMigrationsFrom`.
 * Doing so would make `php artisan migrate` run them against the app's default
 * connection while `a11y:report:install` runs them against this one, and the two
 * would then keep separate records of what has run. One path, one record.
 */
final class ReportDatabase
{
    public const DEFAULT_CONNECTION = 'a11y_sqlite';

    public function __construct(private readonly Application $app) {}

    public static function connectionName(): string
    {
        return (string) (config('statamic-a11y-report.connection') ?: self::DEFAULT_CONNECTION);
    }

    /**
     * Whether the tables are the addon's own file rather than a database the
     * site administers. Only then is it safe to create them without being
     * asked: nobody else's schema is being touched.
     */
    public function ownsConnection(): bool
    {
        return self::connectionName() === self::DEFAULT_CONNECTION;
    }

    /**
     * Define the addon's own SQLite connection unless the site defined one of
     * that name itself. Called at boot, after the config file has been merged.
     */
    public function defineDefaultConnection(): void
    {
        $key = 'database.connections.'.self::DEFAULT_CONNECTION;

        if (config($key) !== null) {
            return;
        }

        config([$key => [
            'driver' => 'sqlite',
            'database' => $this->app->storagePath('a11y-report/report.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
    }

    public function isInstalled(): bool
    {
        try {
            return Schema::connection(self::connectionName())->hasTable('a11y_scans');
        } catch (\Throwable) {
            // A SQLite file that does not exist yet throws rather than answering
            // "no tables", and for this question those are the same answer.
            return false;
        }
    }

    /**
     * Create the tables, and whatever the batch runner needs to keep its own
     * records, on the connections they belong to.
     *
     * @return array<int, string> what was done, one line each, for a command to print
     */
    public function install(): array
    {
        $done = [];
        $connection = self::connectionName();

        if ($this->createSqliteFileIfMissing($connection)) {
            $done[] = "Created the database file for [{$connection}].";
        }

        Artisan::call('migrate', [
            '--database' => $connection,
            '--path' => realpath(__DIR__.'/../../database/migrations'),
            '--realpath' => true,
            '--force' => true,
        ]);

        $done[] = "Report tables are in place on [{$connection}].";

        // Laravel keeps the state of a job batch in a table of its own, on the
        // connection `queue.batching.database` names, and it does not create
        // that table itself. A flat-file Statamic site has usually never run a
        // migration, so the table is usually missing, and a scan that was
        // asked for and then died on the first line with "no such table:
        // job_batches" is not zero setup.
        $batching = (string) (config('queue.batching.database') ?: config('database.default'));
        $table = (string) config('queue.batching.table', 'job_batches');

        if ($this->createSqliteFileIfMissing($batching)) {
            $done[] = "Created the database file for [{$batching}], which the queue keeps batch records in.";
        }

        if (Schema::connection($batching)->hasTable($table)) {
            return $done;
        }

        // The site's own migration first, when it has one and the batching
        // connection is the default one that migration writes to. Creating the
        // table by hand was the first version, and it worked until the site
        // ran `php artisan migrate` to switch on a database queue: Laravel's
        // stock jobs migration then met a `job_batches` it had no record of
        // and stopped with "table already exists". Running the site's own file
        // records it where `migrate` looks, so that command keeps working.
        $migration = $this->appMigrationCreating($table);

        if ($migration !== null && $batching === (string) config('database.default')) {
            Artisan::call('migrate', [
                '--path' => $migration,
                '--realpath' => true,
                '--force' => true,
            ]);

            $done[] = "Ran the site's own migration ".basename($migration)." to create [{$table}] on [{$batching}].";

            return $done;
        }

        // No such migration, or a batching connection the site set up by hand.
        // The schema is Laravel's own stub, copied so this does not depend on
        // a command that prints a file.
        if (! Schema::connection($batching)->hasTable($table)) {
            Schema::connection($batching)->create($table, function ($t) {
                $t->string('id')->primary();
                $t->string('name');
                $t->integer('total_jobs');
                $t->integer('pending_jobs');
                $t->integer('failed_jobs');
                $t->longText('failed_job_ids');
                $t->mediumText('options')->nullable();
                $t->integer('cancelled_at')->nullable();
                $t->integer('created_at');
                $t->integer('finished_at')->nullable();
            });

            $done[] = "Created the [{$table}] table on [{$batching}] for the queue's batch records.";
        }

        return $done;
    }

    /**
     * The site's own migration that creates the given table, if it has one.
     * Laravel's stock `create_jobs_table` creates `job_batches` alongside
     * `jobs` and `failed_jobs`, so the file is found by reading rather than by
     * name.
     */
    private function appMigrationCreating(string $table): ?string
    {
        $directory = $this->app->databasePath('migrations');

        if (! is_dir($directory)) {
            return null;
        }

        foreach (glob($directory.'/*.php') ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), "Schema::create('{$table}'")) {
                return $file;
            }
        }

        return null;
    }

    private function createSqliteFileIfMissing(string $connection): bool
    {
        $config = (array) config("database.connections.{$connection}", []);

        if (($config['driver'] ?? null) !== 'sqlite') {
            return false;
        }

        $path = (string) ($config['database'] ?? '');

        if ($path === '' || $path === ':memory:' || file_exists($path)) {
            return false;
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);

            // A directory this addon made is this addon's to keep out of the
            // site's repository. Without this, `git status` on a site that
            // has run a scan lists `storage/a11y-report/` as untracked, and
            // the next `git add -A` commits the scan database: every issue,
            // every note, every person's name against a decision, pushed to
            // whatever the site's remote is. It is written only when the
            // directory did not exist, because a directory that was already
            // there belongs to the site and its ignore rules are the site's
            // business.
            file_put_contents(dirname($path).'/.gitignore', "*\n!.gitignore\n");
        }

        touch($path);

        return true;
    }
}
