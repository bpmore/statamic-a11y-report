<?php

declare(strict_types=1);

use Bpmore\A11yReport\Models\Issue;
use Bpmore\A11yReport\Models\Scan;
use Bpmore\A11yReport\Models\ScanPage;
use Bpmore\A11yReport\Scan\Scans;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Bpmore\A11yReport\Trends\Overview;
use Statamic\Facades\Collection;

/**
 * Ending a scan that is never going to finish.
 *
 * `cancelled` was one of the statuses a scan could end at from the day the
 * scan layer was built, and nothing a person could reach ever set it. A scan
 * stuck queued or running stops every scheduled scan, and the only cure was an
 * UPDATE against the addon's own tables.
 *
 * The rule that matters most here is the one about what a cancelled scan may
 * be used for, which is nothing: only a complete scan can be reported on, and
 * a scan somebody ended early has by definition not read the site.
 */
beforeEach(function () {
    $this->withStandardFakeViews();
    test()->viewShouldReturnRaw('default', PLAIN);
    Collection::make('pages')->routes('/{slug}')->save();
    app(ReportDatabase::class)->install();
});

/** A scan wedged half an hour ago with its pages unread. */
function wedgedScan(): Scan
{
    page('one', '<img src="/a.jpg">');
    $scan = runScan();

    $scan->update([
        'status' => Scan::RUNNING,
        'finished_at' => null,
        'created_at' => now()->copy()->subMinutes(30),
        'started_at' => now()->copy()->subMinutes(30),
    ]);

    ScanPage::where('scan_id', $scan->id)->update(['status' => ScanPage::PENDING, 'scanned_at' => null]);

    return $scan->fresh();
}

it('ends a scan that cannot finish, so scheduled scans run again', function () {
    $scan = wedgedScan();

    // Nothing scheduled can start while it is running.
    $this->artisan('statamic:a11y:scan', ['--scheduled' => true, '--sync' => true])->assertExitCode(0);
    expect(Scan::where('trigger', Scan::TRIGGER_SCHEDULED)->count())->toBe(0);

    $this->artisan('statamic:a11y:scan:cancel', ['scan' => $scan->uuid, '--force' => true])
        ->expectsOutputToContain('is now')
        ->assertExitCode(0);

    expect($scan->fresh()->status)->toBe(Scan::CANCELLED);
    expect($scan->fresh()->finished_at)->not->toBeNull();

    // The block is gone, and the warning with it.
    expect((new Overview(app(ReportDatabase::class)))->stale(10))->toBeNull();

    $this->artisan('statamic:a11y:scan', ['--scheduled' => true, '--sync' => true])->assertExitCode(0);
    expect(Scan::where('trigger', Scan::TRIGGER_SCHEDULED)->count())->toBe(1);
});

it('refuses to report on a scan somebody ended', function () {
    $scan = wedgedScan();

    $this->artisan('statamic:a11y:scan:cancel', ['scan' => $scan->uuid, '--force' => true])->assertExitCode(0);

    // A scan that was ended early has not read the site, whatever it read
    // before it stopped. Only a complete scan can be reported on, and that is
    // the check that has always been there.
    expect(fn () => app(\Bpmore\A11yReport\Document\ReportBuilder::class)->build($scan->fresh(), 'tester'))
        ->toThrow(InvalidArgumentException::class, 'cancelled');
});

it('does not let a job still on the queue add to a scan that has ended', function () {
    $scan = wedgedScan();
    $page = ScanPage::where('scan_id', $scan->id)->first();

    $this->artisan('statamic:a11y:scan:cancel', ['scan' => $scan->uuid, '--force' => true])->assertExitCode(0);

    $before = Issue::where('scan_id', $scan->id)->count();

    // The page is still pending, which is what marks the scan as one that
    // stopped early. A worker that had this job in hand when somebody
    // cancelled would otherwise read the page and write findings against a
    // scan whose counts were rolled up before they existed.
    app(Scans::class)->scanPage($scan->id, $page->id);

    expect($page->fresh()->status)->toBe(ScanPage::PENDING);
    expect(Issue::where('scan_id', $scan->id)->count())->toBe($before);
});

it('says nothing needs doing when the scan has already ended', function () {
    page('one', '<p>Fine.</p>');
    $scan = runScan();

    expect($scan->status)->toBe(Scan::COMPLETE);

    $this->artisan('statamic:a11y:scan:cancel', ['scan' => $scan->uuid, '--force' => true])
        ->expectsOutputToContain('already ended as')
        ->assertExitCode(0);

    // Untouched: a complete scan is evidence and this command does not edit it.
    expect($scan->fresh()->status)->toBe(Scan::COMPLETE);
});

it('says so when a scan id is not one it knows', function () {
    $this->artisan('statamic:a11y:scan:cancel', ['scan' => 'not-a-scan', '--force' => true])
        ->expectsOutputToContain('No scan has the id')
        ->assertExitCode(1);
});

it('asks before ending a scan, and leaves it alone when told not to', function () {
    $scan = wedgedScan();

    $this->artisan('statamic:a11y:scan:cancel', ['scan' => $scan->uuid])
        ->expectsConfirmation('End this scan? It can never be reported on afterwards.', 'no')
        ->expectsOutputToContain('Left alone')
        ->assertExitCode(0);

    expect($scan->fresh()->status)->toBe(Scan::RUNNING);
});

it('says when other scans are still holding the schedule up', function () {
    $first = wedgedScan();

    $second = runScan();
    $second->update(['status' => Scan::RUNNING, 'finished_at' => null]);
    ScanPage::where('scan_id', $second->id)->update(['status' => ScanPage::PENDING, 'scanned_at' => null]);

    // Clearing the oldest is the first step and is not always the last, and a
    // person finds that out now rather than on the next Sunday that passes
    // without a scan.
    $this->artisan('statamic:a11y:scan:cancel', ['scan' => $first->uuid, '--force' => true])
        ->expectsOutputToContain('scheduled scans are still skipped')
        ->assertExitCode(0);
});

/**
 * A uuid is thirty-six characters and nobody types one. It is read off a
 * screen and copied, or read off a screen and typed as far as the first dash.
 * Refusing the short form told somebody holding the right id that they had the
 * wrong one, and it cost a person clearing a blocked schedule a round trip on
 * a live site while the schedule stayed blocked.
 */
it('takes the front of a scan id, which is what a person reads off a screen', function () {
    $scan = wedgedScan();

    $this->artisan('statamic:a11y:scan:cancel', ['scan' => substr($scan->uuid, 0, 8), '--force' => true])
        ->expectsOutputToContain('is now')
        ->assertExitCode(0);

    expect($scan->fresh()->status)->toBe(Scan::CANCELLED);
});

/**
 * Ending the wrong scan is not undoable, so an ambiguous prefix is refused
 * with what it matched rather than resolved to the newest and hoped for.
 */
it('refuses a prefix that matches more than one scan, and says which', function () {
    $one = wedgedScan();
    $two = wedgedScan();

    $shared = 'aaaaaaaa';
    $one->update(['uuid' => $shared.'-1111-1111-1111-111111111111']);
    $two->update(['uuid' => $shared.'-2222-2222-2222-222222222222']);

    $this->artisan('statamic:a11y:scan:cancel', ['scan' => $shared, '--force' => true])
        ->expectsOutputToContain('More than one scan starts with')
        ->expectsOutputToContain($one->fresh()->uuid)
        ->expectsOutputToContain($two->fresh()->uuid)
        ->assertExitCode(1);

    expect($one->fresh()->status)->toBe(Scan::RUNNING);
    expect($two->fresh()->status)->toBe(Scan::RUNNING);
});

/**
 * Short enough to be a typo rather than an id. Without the floor, a stray
 * character would match whatever happened to start with it.
 */
it('will not take one or two characters as a scan id', function () {
    $scan = wedgedScan();

    $this->artisan('statamic:a11y:scan:cancel', ['scan' => substr($scan->uuid, 0, 2), '--force' => true])
        ->expectsOutputToContain('at least the first four characters')
        ->assertExitCode(1);

    expect($scan->fresh()->status)->toBe(Scan::RUNNING);
});
