<?php

declare(strict_types=1);

use Bpmore\A11yReport\Models\Scan;
use Bpmore\A11yReport\Scan\ScanSchedule;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Bpmore\A11yReport\Trends\Overview;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Collection;

/**
 * The recurring scan.
 *
 * `scan.schedule` sat in the config file from the first release with nothing
 * reading it, so every install was told it scanned weekly and none of them
 * did. These tests are mostly about the two ways that can happen again: a
 * value nothing registers, and a registration nothing runs.
 */
beforeEach(function () {
    $this->withStandardFakeViews();
    test()->viewShouldReturnRaw('default', PLAIN);
    Collection::make('pages')->routes('/{slug}')->save();
    app(ReportDatabase::class)->install();

    $this->user = \Statamic\Facades\User::make()->email('super@example.test')->makeSuper();
    $this->user->save();
});

function scheduleConfig(array $overrides = []): array
{
    return array_merge((array) config('statamic-a11y-report.scan'), $overrides);
}

it('turns each named frequency into a cron expression at the configured time', function () {
    expect(ScanSchedule::expression(scheduleConfig(['schedule' => 'daily', 'schedule_at' => '03:30'])))->toBe('30 3 * * *');
    expect(ScanSchedule::expression(scheduleConfig(['schedule' => 'weekly', 'schedule_at' => '02:00'])))->toBe('0 2 * * 0');
    expect(ScanSchedule::expression(scheduleConfig(['schedule' => 'monthly', 'schedule_at' => '23:15'])))->toBe('15 23 1 * *');
    // The case somebody types is not the case they have to type.
    expect(ScanSchedule::expression(scheduleConfig(['schedule' => 'WEEKLY'])))->toBe('0 2 * * 0');
});

it('schedules nothing when the config says not to, without calling that a mistake', function () {
    // Turning the scan off is a choice, so it is silent. Falling through to
    // the "I do not understand this" branch would also schedule nothing, and
    // would warn about it in the log every time the console booted, so the
    // absence of a warning is the assertion that separates the two.
    Log::shouldReceive('warning')->never();

    foreach ([false, null, '', 'off', 'never', 'none', 'FALSE', '  Off  '] as $off) {
        expect(ScanSchedule::expression(scheduleConfig(['schedule' => $off])))
            ->toBeNull('['.var_export($off, true).'] means do not schedule a scan');
    }
});

it('takes a cron expression for anything the names do not cover', function () {
    // Hourly is deliberately not a name. Somebody who wants a scan of every
    // page 24 times a day writes it out, which is a deliberate act rather
    // than a plausible-looking word.
    expect(ScanSchedule::expression(scheduleConfig(['schedule' => '0 * * * *'])))->toBe('0 * * * *');
    expect(ScanSchedule::expression(scheduleConfig(['schedule' => '30 4 * * 1,4'])))->toBe('30 4 * * 1,4');
    expect(ScanSchedule::expression(scheduleConfig(['schedule' => 'hourly'])))->toBeNull('hourly is not one of the names');
});

it('schedules nothing and says so when the frequency is not one it understands', function () {
    Log::shouldReceive('warning')->once()->withArgs(fn ($m) => str_contains($m, 'fortnightly'));

    // Never a guess. A scan nobody asked for, at a time nobody chose, on a
    // machine sized for neither, is worse than no scan.
    expect(ScanSchedule::expression(scheduleConfig(['schedule' => 'fortnightly'])))->toBeNull();
});

it('falls back to two in the morning when the time is malformed, rather than throwing at boot', function () {
    Log::shouldReceive('warning')->once()->withArgs(fn ($m) => str_contains($m, 'schedule_at'));

    // This class is reached from the service provider on every request, so a
    // typo in one config key must not take the site down.
    expect(ScanSchedule::expression(scheduleConfig(['schedule' => 'daily', 'schedule_at' => 'half past two'])))->toBe('0 2 * * *');
});

it('registers the scan once, however many times the provider boots', function () {
    $schedule = new Schedule;
    $config = scheduleConfig(['schedule' => 'weekly']);

    $first = ScanSchedule::register($schedule, $config);
    $second = ScanSchedule::register($schedule, $config);

    expect(count($schedule->events()))->toBe(1, 'a second boot does not add a second scan');
    expect($second)->toBe($first);
});

it('registers the scan on Laravel\'s scheduler, with overlap refused', function () {
    $schedule = new Schedule;

    $event = ScanSchedule::register($schedule, scheduleConfig(['schedule' => 'daily', 'schedule_at' => '04:00']));

    expect($event)->not->toBeNull();
    expect($event->getExpression())->toBe('0 4 * * *');
    expect($event->command)->toContain('a11y:scan');
    expect($event->command)->toContain('--scheduled');
    expect(count($schedule->events()))->toBe(1);

    // Two half-finished scans of one site is worse than a late one.
    expect($event->withoutOverlapping)->toBeTrue();
});

it('registers nothing at all when no scan is scheduled', function () {
    $schedule = new Schedule;

    expect(ScanSchedule::register($schedule, scheduleConfig(['schedule' => false])))->toBeNull();
    expect($schedule->events())->toBe([]);
});

it('is wired to the application scheduler at boot, not merely registrable', function () {
    // The bug this whole change fixes was a config key nothing read. A class
    // that can register itself and a provider that never calls it is the same
    // bug with more code, so this asks the booted application rather than the
    // class.
    $events = app(Schedule::class)->events();
    $ours = array_values(array_filter($events, fn ($e) => str_contains((string) $e->command, 'a11y:scan')));

    expect(count($ours))->toBe(1, 'the addon registers exactly one scheduled scan');
    // The shipped config says weekly, and a test that did not pin this would
    // pass on a schedule of "never".
    expect($ours[0]->getExpression())->toBe('0 2 * * 0');
});

it('records a scheduled run as started on schedule, which is what the report prints', function () {
    page('one', '<img src="/a.jpg">');

    $this->artisan('statamic:a11y:scan', ['--scheduled' => true])->assertExitCode(0);

    $scan = Scan::orderByDesc('id')->first();

    expect($scan->trigger)->toBe(Scan::TRIGGER_SCHEDULED);
    expect($scan->initiated_by)->toBe('schedule');
});

it('skips a scheduled scan while another is running, and does not call that a failure', function () {
    page('one', '<img src="/a.jpg">');
    $running = Scan::create([
        'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        'trigger' => Scan::TRIGGER_MANUAL,
        'status' => Scan::RUNNING,
        'engine' => 'php',
        'engine_version' => 'test',
        'ruleset' => 'wcag21aa',
        'scope' => [],
    ]);

    // Exit zero on purpose: a scheduler that mails a failure every week for
    // behaving correctly gets its mail filtered, and then the real one is
    // missed.
    $this->artisan('statamic:a11y:scan', ['--scheduled' => true])
        ->expectsOutputToContain('was skipped')
        ->assertExitCode(0);

    expect(Scan::count())->toBe(1);
    expect(Scan::first()->uuid)->toBe($running->uuid);

    // The same command without the flag refuses too, and says so rather than
    // skipping quietly: somebody typed it and would otherwise type it again.
    // It used to start a second scan, on the reasoning that a person asking
    // twice means it. Two axe scans thirty-five seconds apart then took a live
    // site off the internet, browsers and all, so the deliberate act is now
    // `--force` rather than the absence of a flag.
    $this->artisan('statamic:a11y:scan', ['--sync' => true])->assertExitCode(1);
    expect(Scan::count())->toBe(1);

    $this->artisan('statamic:a11y:scan', ['--sync' => true, '--force' => true])->assertExitCode(0);
    expect(Scan::count())->toBe(2);
});

it('says on the overview when a schedule is configured and nothing has ever run it', function () {
    // A scan run by hand is not the schedule working. Counting one would let
    // a site where nobody ever added the cron line look healthy for as long
    // as somebody kept pressing the button.
    page('one', '<img src="/a.jpg">');
    $this->artisan('statamic:a11y:scan', ['--sync' => true]);

    $status = (new Overview(app(ReportDatabase::class)))->schedule();

    expect($status['label'])->toBe('weekly');
    expect($status['last'])->toBeNull();
    expect($status['missed'])->toBeFalse('nothing can have been missed when nothing has run');

    $text = utilityHtml();

    expect($text)->toContain('No scan has ever run on schedule');
    expect($text)->toContain('schedule:run');
});

it('says what is wrong with the config once per screen, not once per line on it', function () {
    // The overview asks the schedule four questions. Each one used to read the
    // config for itself, so a typo in one key was four identical lines in the
    // log for one page, every time anybody opened the screen. A warning that
    // repeats like that is a warning nobody is reading by the second week.
    config()->set('statamic-a11y-report.scan.schedule', 'weekly');
    config()->set('statamic-a11y-report.scan.schedule_at', 'half past two');

    $warnings = 0;

    Log::listen(function ($message) use (&$warnings) {
        if ($message->level === 'warning' && str_contains($message->message, 'schedule_at')) {
            $warnings++;
        }
    });

    $status = (new Overview(app(ReportDatabase::class)))->schedule();

    expect($warnings)->toBe(1);

    // And it still falls back to the documented time rather than throwing.
    expect($status['expression'])->toBe('0 2 * * 0');
});

it('says nothing about the schedule when the config asks for no scheduled scan', function () {
    config()->set('statamic-a11y-report.scan.schedule', false);

    expect((new Overview(app(ReportDatabase::class)))->schedule())->toBeNull();
    expect(utilityHtml())->not->toContain('No scan has ever run on schedule');
});

it('notices when scheduled scans were running and have stopped', function () {
    page('one', '<img src="/a.jpg">');
    Carbon::setTestNow('2026-03-01 09:00:00');

    $this->artisan('statamic:a11y:scan', ['--scheduled' => true, '--sync' => true]);

    // One week on, the run happened. Nothing is wrong yet.
    Carbon::setTestNow('2026-03-08 09:00:00');
    expect((new Overview(app(ReportDatabase::class)))->schedule()['missed'])->toBeFalse();

    // Three weeks on, two Sundays have gone by with nothing.
    Carbon::setTestNow('2026-03-22 09:00:00');
    $status = (new Overview(app(ReportDatabase::class)))->schedule();

    expect($status['missed'])->toBeTrue();
    expect(utilityHtml())->toContain('Scheduled scans have stopped');

    Carbon::setTestNow();
});

it('does not report a failure to cron for the things a deploy would fail on', function () {
    // A page that never renders, which `--sync` exits non-zero on and must
    // keep exiting non-zero on. A weekly scan that fails every week for the
    // same known reason is a cron whose mail gets filtered, and then the run
    // that really broke goes unread. The count is still printed, and the
    // overview and the report are where it is read.
    page('one', '<img src="/a.jpg">');
    page('broken', '<p>x</p>', extra: ['template' => 'does-not-exist']);

    $this->artisan('statamic:a11y:scan', ['--sync' => true])
        ->expectsOutputToContain('could not be read')
        ->assertExitCode(1);

    $this->artisan('statamic:a11y:scan', ['--scheduled' => true])
        ->expectsOutputToContain('could not be read')
        ->assertExitCode(0);

    // A scan that did not finish is still a failure whatever started it, and
    // that check runs before this one. It is unchanged behaviour, asserted in
    // ScanTest, and is deliberately not re-asserted here with a scan this
    // test cannot actually make fail.
});
