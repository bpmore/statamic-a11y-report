<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Scan;

use Bpmore\A11yReport\Commands\Scan as ScanCommand;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;

/**
 * The recurring scan.
 *
 * The report's claim is that a site is clean over time, and time passes
 * whether or not anybody remembers to press a button. `scan.schedule` has sat
 * in the config file since the first release with nothing reading it, so every
 * install has been told it scans weekly and none of them did.
 *
 * Three named frequencies and a cron expression for anything else. "Hourly" is
 * deliberately not one of the names: a scan renders every published page, and
 * a word somebody types without thinking should not turn the site into a load
 * test 24 times a day. Somebody who genuinely wants that writes the cron
 * expression, which is a deliberate act rather than a plausible-looking word.
 *
 * A value this does not understand schedules nothing and says so in the log.
 * Guessing a frequency for somebody's site is worse than not running: a scan
 * nobody asked for, at a time nobody chose, on a machine sized for neither.
 */
final class ScanSchedule
{
    /** @var array<int, string> */
    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    /** Values that all mean the same thing: do not schedule anything. */
    private const OFF = ['off', 'never', 'none', 'false', ''];

    public const DEFAULT_TIME = '02:00';

    /**
     * Register the scan, or nothing at all.
     *
     * @param  array<string, mixed>  $config  the `scan` config block
     */
    public static function register(Schedule $schedule, array $config): ?Event
    {
        $expression = self::expression($config);

        if ($expression === null) {
            return null;
        }

        // Once, however many times the provider boots. A provider whose boot
        // runs twice puts two identical scans on the schedule, and two
        // identical entries in `schedule:list` are a support question even
        // when the overlap guards below stop the second one doing anything.
        if (($already = self::registered($schedule)) !== null) {
            return $already;
        }

        return $schedule->command(ScanCommand::class, ['--scheduled'])
            ->cron($expression)
            // A scan that outlasts its own interval must not meet the next
            // one. The command refuses to start a second scan as well, which
            // is the guard that works when the cache does not.
            ->withoutOverlapping()
            ->description('Accessibility Report: scan every published page');
    }

    /** The scan already on this schedule, if it is on it. */
    public static function registered(Schedule $schedule): ?Event
    {
        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, ScanCommand::SCHEDULED_SIGNATURE)) {
                return $event;
            }
        }

        return null;
    }

    /**
     * The cron expression the config asks for, or null for "do not schedule".
     *
     * @param  array<string, mixed>  $config  the `scan` config block
     */
    public static function expression(array $config): ?string
    {
        $wanted = $config['schedule'] ?? null;

        if ($wanted === null || $wanted === false || (is_string($wanted) && in_array(strtolower(trim($wanted)), self::OFF, true))) {
            return null;
        }

        if (! is_string($wanted)) {
            Log::warning('[a11y-report] scan.schedule is not a frequency or a cron expression, so no scan is scheduled.');

            return null;
        }

        $wanted = trim($wanted);
        [$hour, $minute] = self::timeOfDay($config);

        $expression = match (strtolower($wanted)) {
            'daily' => "{$minute} {$hour} * * *",
            // Sunday, which is the quietest day on most sites and the one
            // Laravel's own weekly() picks. A different day is a cron
            // expression away.
            'weekly' => "{$minute} {$hour} * * 0",
            'monthly' => "{$minute} {$hour} 1 * *",
            default => null,
        };

        if ($expression !== null) {
            return $expression;
        }

        if (CronExpression::isValidExpression($wanted)) {
            return $wanted;
        }

        Log::warning("[a11y-report] [{$wanted}] is not one of ".implode(', ', self::FREQUENCIES).' or a valid cron expression, so no scan is scheduled.');

        return null;
    }

    /**
     * The hour and minute a named frequency runs at.
     *
     * A malformed time falls back and says so rather than throwing: a typo in
     * one config key must not take the site down at boot, and this class is
     * reached from the service provider on every request.
     *
     * @param  array<string, mixed>  $config  the `scan` config block
     * @return array{0: string, 1: string}
     */
    private static function timeOfDay(array $config): array
    {
        $at = $config['schedule_at'] ?? self::DEFAULT_TIME;

        if (is_string($at) && preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($at), $m) === 1) {
            return [(string) (int) $m[1], (string) (int) $m[2]];
        }

        Log::warning('[a11y-report] scan.schedule_at is not a time like "02:00", so the scan is scheduled for '.self::DEFAULT_TIME.'.');

        return ['2', '0'];
    }

    /**
     * When an expression was last due to run, counting back `$nth` runs.
     *
     * Given the expression rather than the config, along with the two below,
     * so that reading the config is something a caller does once. Working it
     * out again per question meant a screen that asks four of them said the
     * same "that is not a time" four times over, and a warning repeated on
     * every page load is a warning nobody reads by the second week.
     */
    public static function previousRun(string $expression, int $nth = 0): \DateTimeInterface
    {
        // `now()` rather than the string 'now': `CronExpression` would build
        // its own clock and ignore a frozen one, so a test and the screen it
        // tests would disagree about which runs have been missed.
        return (new CronExpression($expression))->getPreviousRunDate(now(), $nth);
    }

    /** When an expression is next due to run. */
    public static function nextRun(string $expression): \DateTimeInterface
    {
        return (new CronExpression($expression))->getNextRunDate(now());
    }

    /** The frequency as a person wrote it, for a screen to repeat back. */
    public static function label(array $config, string $expression): string
    {
        $wanted = strtolower(trim((string) ($config['schedule'] ?? '')));

        return in_array($wanted, self::FREQUENCIES, true) ? $wanted : $expression;
    }
}
