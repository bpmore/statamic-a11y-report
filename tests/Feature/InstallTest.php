<?php

declare(strict_types=1);

use Bpmore\A11yReport\Storage\ReportDatabase;
use Illuminate\Support\Facades\Schema;

it('creates every table on the configured connection, and the queue batch table beside them', function () {
    $database = app(ReportDatabase::class);

    expect($database->isInstalled())->toBeFalse();

    $database->install();

    $schema = Schema::connection('a11y_testing');

    foreach (['a11y_scans', 'a11y_scan_pages', 'a11y_issues', 'a11y_issue_states', 'a11y_criteria_assessments', 'a11y_reports', 'job_batches'] as $table) {
        expect($schema->hasTable($table))->toBeTrue("missing table {$table}");
    }

    expect($database->isInstalled())->toBeTrue();
});

it('can be run twice without complaint', function () {
    app(ReportDatabase::class)->install();
    app(ReportDatabase::class)->install();

    expect(app(ReportDatabase::class)->isInstalled())->toBeTrue();
});

it('is a command', function () {
    $this->artisan('statamic:a11y:report:install')
        ->expectsOutputToContain('Report tables are in place on [a11y_testing]')
        ->assertExitCode(0);
});

it('defaults a criterion to not evaluated, never to supports', function () {
    // The single most damaging default this product could ship, pinned at
    // the schema so no code path can rely on a friendlier one.
    app(ReportDatabase::class)->install();

    \Bpmore\A11yReport\Models\CriterionAssessment::create(['site' => 'default', 'criterion' => '1.3.2', 'level' => 'A']);

    expect(\Bpmore\A11yReport\Models\CriterionAssessment::first()->status)->toBe('not_evaluated');
    expect(\Bpmore\A11yReport\Models\CriterionAssessment::first()->locked)->toBeFalse();
});

it("uses the site's own migration for the batch table when it has one, so migrate keeps working", function () {
    // The first version created `job_batches` by hand and Laravel's stock
    // jobs migration then failed on a site that switched on a database
    // queue: "table already exists". Found by doing exactly that on a
    // scratch site. The site's file is run and recorded instead.
    $tmp = sys_get_temp_dir().'/a11y-gate-'.uniqid();
    mkdir($tmp.'/migrations', 0755, true);
    file_put_contents($tmp.'/migrations/0001_01_01_000002_create_jobs_table.php', <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('jobs', function (Blueprint $t) { $t->id(); $t->string('queue'); });
        Schema::create('job_batches', function (Blueprint $t) { $t->string('id')->primary(); $t->string('name'); $t->integer('total_jobs'); $t->integer('pending_jobs'); $t->integer('failed_jobs'); $t->longText('failed_job_ids'); $t->mediumText('options')->nullable(); $t->integer('cancelled_at')->nullable(); $t->integer('created_at'); $t->integer('finished_at')->nullable(); });
    }
};
PHP);

    app()->useDatabasePath($tmp);
    config()->set('database.default', 'a11y_testing');

    $done = app(ReportDatabase::class)->install();

    $schema = Schema::connection('a11y_testing');
    expect($schema->hasTable('job_batches'))->toBeTrue();
    expect($schema->hasTable('jobs'))->toBeTrue();
    expect(implode("\n", $done))->toContain("Ran the site's own migration 0001_01_01_000002_create_jobs_table.php");

    // Recorded where `php artisan migrate` looks, which is the whole point.
    expect(\Illuminate\Support\Facades\DB::connection('a11y_testing')->table('migrations')->where('migration', '0001_01_01_000002_create_jobs_table')->exists())->toBeTrue();

    // And a second install neither runs it again nor creates anything.
    $again = app(ReportDatabase::class)->install();
    expect(implode("\n", $again))->not->toContain('job_batches');
});

/**
 * The scan database is the site's own record of who decided what about which
 * failure, and it lands in a directory this addon makes inside `storage/`.
 * A directory nothing ignores is a directory `git status` lists, and the next
 * `git add -A` on that site commits the database and pushes it to whatever
 * remote the site has. Found on a live site, where `storage/a11y-report/` sat
 * in the untracked list next to the files somebody was about to commit.
 *
 * Only when this addon made the directory. One that was already there is the
 * site's, and so are its ignore rules.
 */
it('keeps the database directory it creates out of the site repository', function () {
    $dir = sys_get_temp_dir().'/a11y-ignore-'.bin2hex(random_bytes(6));
    $path = $dir.'/report.sqlite';

    config(['database.connections.a11y_ignoring' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '']]);
    config(['statamic-a11y-report.connection' => 'a11y_ignoring']);

    app(ReportDatabase::class)->defineDefaultConnection();
    app(ReportDatabase::class)->install();

    expect(is_file($path))->toBeTrue('the database was not created, so this test checked nothing');
    expect(is_file($dir.'/.gitignore'))->toBeTrue('storage/a11y-report/ is left for the site to commit by accident');
    expect(file_get_contents($dir.'/.gitignore'))->toBe("*\n!.gitignore\n");

    array_map('unlink', glob($dir.'/{,.}[!.,!..]*', GLOB_BRACE) ?: []);
    @rmdir($dir);
});

it('leaves a directory it did not create alone, ignore rules included', function () {
    $dir = sys_get_temp_dir().'/a11y-theirs-'.bin2hex(random_bytes(6));
    mkdir($dir, 0755, true);
    $path = $dir.'/report.sqlite';

    config(['database.connections.a11y_theirs' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '']]);
    config(['statamic-a11y-report.connection' => 'a11y_theirs']);

    app(ReportDatabase::class)->defineDefaultConnection();
    app(ReportDatabase::class)->install();

    expect(is_file($path))->toBeTrue('the database was not created, so this test checked nothing');
    expect(is_file($dir.'/.gitignore'))->toBeFalse('an ignore file was written into a directory belonging to the site');

    array_map('unlink', glob($dir.'/{,.}[!.,!..]*', GLOB_BRACE) ?: []);
    @rmdir($dir);
});
