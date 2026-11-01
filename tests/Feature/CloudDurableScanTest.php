<?php

declare(strict_types=1);

use Bpmore\A11yReport\Jobs\FinalizeScan;
use Bpmore\A11yReport\Jobs\StartScan;
use Bpmore\A11yReport\Models\Issue;
use Bpmore\A11yReport\Models\IssueState;
use Bpmore\A11yReport\Models\Scan;
use Bpmore\A11yReport\Models\ScanPage;
use Bpmore\A11yReport\Scan\Scans;
use Bpmore\A11yReport\Scan\ScanScope;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Statamic\Facades\Collection;

beforeEach(function () {
    $this->withStandardFakeViews();
    test()->viewShouldReturnRaw('default', PLAIN);
    Collection::make('pages')->routes('/{slug}')->save();
    config(['statamic-a11y-report.scan.cloud_durable_work' => true,
        'database.default' => 'a11y_testing', 'queue.default' => 'database',
        'queue.connections.database' => ['driver' => 'database', 'connection' => 'a11y_testing',
            'table' => 'jobs', 'queue' => 'default', 'retry_after' => 300, 'after_commit' => false],
        'queue.batching.database' => 'a11y_testing']);
    app(ReportDatabase::class)->install();
    Schema::connection('a11y_testing')->create('jobs', function ($table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
});

function queuedCloudScan(): Scan
{
    return app(Scans::class)->create(ScanScope::fromConfig((array) config('statamic-a11y-report.scan')), Scan::TRIGGER_MANUAL, 'fixture');
}

it('rolls back an interrupted enumeration and retries without partial pages or duplicate jobs', function () {
    page('one', 'Fine');
    page('two', 'Fine');
    $scan = queuedCloudScan();
    $abort = true;
    DB::listen(function (QueryExecuted $query) use (&$abort): void {
        if ($abort && str_starts_with(strtolower($query->sql), 'insert into "a11y_scan_pages"')) {
            $abort = false;
            throw new RuntimeException('Interrupted after page insertion');
        }
    });
    expect(fn () => app(Scans::class)->start($scan))->toThrow(RuntimeException::class, 'Interrupted');
    expect(ScanPage::count())->toBe(0)->and($scan->fresh()->status)->toBe(Scan::QUEUED)
        ->and($scan->fresh()->started_at)->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(0)->and(DB::table('job_batches')->count())->toBe(0);
    app(Scans::class)->start($scan->fresh());
    $firstBatch = $scan->fresh()->batch_id;
    app(Scans::class)->start($scan->fresh());
    expect(ScanPage::count())->toBe(2)->and(DB::table('jobs')->count())->toBe(2)
        ->and(DB::table('job_batches')->count())->toBe(1)->and($scan->fresh()->batch_id)->toBe($firstBatch);
});

it('rejects split queue and report connections before creating partial work', function () {
    page('one', 'Fine');
    $scan = queuedCloudScan();
    config(['queue.connections.database.connection' => 'another_database']);
    expect(fn () => app(Scans::class)->start($scan))->toThrow(RuntimeException::class, 'one database connection');
    expect(ScanPage::count())->toBe(0)->and($scan->fresh()->status)->toBe(Scan::QUEUED);
});

it('rolls back interrupted page writes then commits one result on retry', function () {
    page('one', '<img src="/fixture.jpg">');
    $scan = app(Scans::class)->start(queuedCloudScan());
    $page = ScanPage::firstOrFail();
    $abort = true;
    Issue::created(function () use (&$abort): void {
        if ($abort) {
            $abort = false;
            throw new RuntimeException('Interrupted after issue write');
        }
    });
    expect(fn () => app(Scans::class)->scanPage($scan->id, $page->id))->toThrow(RuntimeException::class, 'Interrupted');
    expect(Issue::count())->toBe(0)->and($page->fresh()->status)->toBe(ScanPage::PENDING);
    app(Scans::class)->scanPage($scan->id, $page->id);
    $count = Issue::count();
    expect($count)->toBeGreaterThan(0)->and($page->fresh()->status)->toBe(ScanPage::SCANNED);
    app(Scans::class)->scanPage($scan->id, $page->id);
    expect(Issue::count())->toBe($count);
});

it('keeps issue-state updates and scan completion in the same rollback boundary', function () {
    page('one', '<img src="/fixture.jpg">');
    $scan = app(Scans::class)->start(queuedCloudScan());
    $page = ScanPage::firstOrFail();
    app(Scans::class)->scanPage($scan->id, $page->id);
    $abort = true;
    IssueState::created(function () use (&$abort): void {
        if ($abort) {
            $abort = false;
            throw new RuntimeException('Interrupted after state write');
        }
    });
    expect(fn () => app(Scans::class)->finalize($scan->id))->toThrow(RuntimeException::class, 'Interrupted');
    expect(IssueState::count())->toBe(0)->and($scan->fresh()->status)->toBe(Scan::RUNNING);
    app(Scans::class)->finalize($scan->id);
    expect($scan->fresh()->status)->toBe(Scan::COMPLETE)->and(IssueState::count())->toBeGreaterThan(0);
    $before = IssueState::all()->toArray();
    $this->travel(1)->minutes();
    app(Scans::class)->finalize($scan->id);
    expect(IssueState::all()->toArray())->toBe($before);
});

it('does not overwrite a newer completed scan with an older resumed observation', function () {
    page('one', '<img src="/old-broken.jpg">');
    $older = app(Scans::class)->start(queuedCloudScan());
    app(Scans::class)->scanPage($older->id, ScanPage::where('scan_id', $older->id)->firstOrFail()->id);
    page('one', '<p>Now fine.</p>');
    $newer = runScan();
    expect($newer->status)->toBe(Scan::COMPLETE)->and(IssueState::count())->toBe(0);
    app(Scans::class)->finalize($older->id);
    expect($older->fresh()->status)->toBe(Scan::COMPLETE)->and($older->fresh()->issues_total)->toBeGreaterThan(0)
        ->and(IssueState::count())->toBe(0);
});

it('bounds new Cloud start and finalize jobs while leaving default job budgets alone', function () {
    expect((new StartScan(1))->tries)->toBe(3)->and((new StartScan(1))->timeout)->toBe(180)
        ->and((new FinalizeScan(1))->timeout)->toBe(180);
    config(['statamic-a11y-report.scan.cloud_durable_work' => false]);
    expect((new StartScan(1))->tries)->toBe(1)->and((new StartScan(1))->timeout)->toBeNull()
        ->and((new FinalizeScan(1))->timeout)->toBeNull();
});

it('rejects deferred queue inserts before any report work commits', function () {
    page('one', 'Fine');
    $scan = queuedCloudScan();
    config(['queue.connections.database.after_commit' => true]);
    expect(fn () => app(Scans::class)->start($scan))->toThrow(RuntimeException::class, 'after_commit disabled');
    expect(ScanPage::count())->toBe(0)->and($scan->fresh()->status)->toBe(Scan::QUEUED)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('commits each synchronous page before starting the next and resumes after interruption', function () {
    page('one', 'Fine');
    page('two', 'Fine');
    $scan = queuedCloudScan();
    $seen = 0;
    $abort = true;
    ScanPage::updating(function (ScanPage $page) use (&$seen, &$abort): void {
        if ($page->status === ScanPage::SCANNED && ++$seen === 2 && $abort) {
            $abort = false;
            throw new RuntimeException('Interrupted second page');
        }
    });
    expect(fn () => app(Scans::class)->start($scan, true))->toThrow(RuntimeException::class, 'Interrupted second page');
    expect(ScanPage::where('status', ScanPage::SCANNED)->count())->toBe(1)
        ->and(ScanPage::where('status', ScanPage::PENDING)->count())->toBe(1)
        ->and($scan->fresh()->pages_total)->toBe(2)->and(DB::table('job_batches')->count())->toBe(0);
    app(Scans::class)->resume($scan->fresh(), true);
    expect(ScanPage::where('status', ScanPage::SCANNED)->count())->toBe(2)
        ->and($scan->fresh()->status)->toBe(Scan::COMPLETE);
});

it('rejects a caller transaction that would undo synchronous page checkpoints', function () {
    $scan = queuedCloudScan();
    expect(fn () => DB::connection(ReportDatabase::connectionName())->transaction(
        fn () => app(Scans::class)->start($scan, true)
    ))->toThrow(RuntimeException::class, 'outside an enclosing transaction');
    expect($scan->fresh()->status)->toBe(Scan::QUEUED);
});
