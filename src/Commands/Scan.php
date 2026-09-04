<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Commands;

use Bpmore\A11yReport\Engine\Finding;
use Bpmore\A11yReport\Engine\PhpDomEngine;
use Bpmore\A11yReport\Models\Scan as ScanModel;
use Bpmore\A11yReport\Scan\Scans;
use Bpmore\A11yReport\Scan\ScanScope;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;

/**
 * Scan the site and keep the results.
 *
 * Unlike `a11y:check`, which reads every page in this process and prints, this
 * writes rows and normally hands the reading to the queue. `--sync` keeps it in
 * this process and exits non-zero above the configured thresholds, which is
 * the shape a deploy pipeline wants.
 *
 * `--scheduled` is the scheduler's, and differs in two ways: the scan row says
 * it was started on schedule, and it does not start at all while another scan
 * is queued or running. A person pressing the button during a scan is
 * deliberate; a cron doing it is a pile-up nobody is watching.
 */
class Scan extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:a11y:scan
        {--site=* : Only these sites. Default is what the config says, which is all of them.}
        {--collection=* : Only these collections. Default is what the config says, which is all of them.}
        {--since= : Only entries changed since a date, or since something like "7 days ago".}
        {--sync : Run in this process instead of on the queue, and exit non-zero above the configured thresholds.}
        {--engine= : Which engine to read pages with. Only "php" exists so far.}
        {--resume= : The id of a scan to pick up where it stopped.}
        {--scheduled : Record this as a scan started on schedule, and skip it if one is already running. For the scheduler.}';

    /** What the schedule registers, and what recognises it there again. */
    public const SCHEDULED_SIGNATURE = 'a11y:scan --scheduled';

    protected $description = 'Scan every page, keep what was found, and track each problem across scans.';

    public function handle(Scans $scans, ReportDatabase $database, \Bpmore\A11yReport\Settings $settings): int
    {
        if (! $this->ensureInstalled($database)) {
            return 1;
        }

        $engine = $this->option('engine');

        if ($engine !== null && $engine !== PhpDomEngine::KEY) {
            $this->error("There is no [{$engine}] engine yet. Only [php] is available.");

            return 1;
        }

        $sync = (bool) $this->option('sync');
        $scheduled = (bool) $this->option('scheduled');

        // A scan that outlasts its own interval must not meet the next one.
        // Two half-finished scans of the same site is worse than a late one,
        // and the overview already tells a person when a scan has stopped
        // moving. Skipping is the right outcome and not a failure, so this
        // exits zero: a scheduler that mails a failure every week for working
        // correctly gets its mail filtered, and then the real one is missed.
        if ($scheduled && ($running = ScanModel::whereIn('status', [ScanModel::QUEUED, ScanModel::RUNNING])->orderByDesc('id')->first()) !== null) {
            $this->line('');
            $this->line("  Scan <options=bold>{$running->uuid}</> is still {$running->status}, so this scheduled scan was skipped.");
            $this->line('');

            return 0;
        }

        if ($resume = $this->option('resume')) {
            $scan = ScanModel::where('uuid', $resume)->first();

            if ($scan === null) {
                $this->error("No scan has the id [{$resume}].");

                return 1;
            }

            $this->line('');
            $this->line("  Resuming scan <options=bold>{$scan->uuid}</>.");
            $scan = $scans->resume($scan, $sync);
        } else {
            $scope = ScanScope::fromConfig(
                $settings->block('scan'),
                (array) $this->option('site'),
                (array) $this->option('collection'),
                $this->option('since'),
            );

            $trigger = match (true) {
                $scheduled => ScanModel::TRIGGER_SCHEDULED,
                $sync => ScanModel::TRIGGER_CI,
                default => ScanModel::TRIGGER_MANUAL,
            };

            $scan = $scans->create($scope, $trigger, $scheduled ? 'schedule' : 'console');

            $this->line('');
            $this->line("  Scan <options=bold>{$scan->uuid}</> created.");
            $scan = $scans->start($scan, $sync);
        }

        if (! $scan->isFinished()) {
            $this->line("  {$scan->pages_total} pages queued in batch {$scan->batch_id}. Queue workers will read them.");
            $this->line("  If it stalls: php please a11y:scan --resume={$scan->uuid}");
            $this->line('');

            return 0;
        }

        $this->summarise($scan);

        return $this->exitCode($scan, $scheduled);
    }

    private function ensureInstalled(ReportDatabase $database): bool
    {
        if ($database->isInstalled()) {
            return true;
        }

        if (! $database->ownsConnection()) {
            $this->error('The report tables are not on ['.ReportDatabase::connectionName().'] yet. Run: php please a11y:report:install');

            return false;
        }

        foreach ($database->install() as $line) {
            $this->line('  '.$line);
        }

        return true;
    }

    private function summarise(ScanModel $scan): void
    {
        $this->line("  Status: <options=bold>{$scan->status}</>");
        $this->line("  Pages: {$scan->pages_scanned} read, {$scan->pages_errored} could not be read, {$scan->pages_skipped} had no page.");

        $parts = [];

        foreach (Finding::IMPACTS as $impact) {
            $parts[] = (($scan->issues_by_impact[$impact] ?? 0)).' '.$impact;
        }

        $this->line("  Issues: {$scan->issues_total} (".implode(', ', $parts).')');

        if (is_array($scan->diff) && $scan->diff['previous_scan_id'] !== null) {
            $this->line("  Against the last scan: {$scan->diff['new']} new, {$scan->diff['fixed']} fixed, {$scan->diff['unchanged']} unchanged.");
        }

        $this->line('');

        if ($scan->engine === PhpDomEngine::KEY) {
            // The same sentence `a11y:check` ends on, because the same checker
            // ran. A green run is not a clean bill.
            $this->line('<fg=gray>This reads the finished pages. It cannot see anything your stylesheet</>');
            $this->line('<fg=gray>decides, colour contrast included, and a page it finds nothing wrong</>');
            $this->line('<fg=gray>with has not been proven accessible.</>');
            $this->line('');
        }
    }

    /**
     * Non-zero above a threshold, and non-zero on any page that could not be
     * read. The second half is not configurable, for the reason the gate's
     * is not: a build that went green because four pages failed to render is
     * worse than no check at all.
     *
     * None of that applies to the scheduler, which gates nothing. A weekly
     * scan of a site with one page that never renders would fail every week
     * for ever, and a cron that always fails is a cron whose mail is filtered,
     * which is how the one that matters gets missed. The counts are still
     * printed, and the overview and the report are where they are read: a
     * scheduled run reports a failure only when the scan itself did not
     * finish, which is the thing a person running cron can act on.
     */
    private function exitCode(ScanModel $scan, bool $scheduled = false): int
    {
        if ($scan->status !== ScanModel::COMPLETE) {
            $this->error("  The scan did not complete: {$scan->status}.");

            return 1;
        }

        if ($scheduled) {
            return 0;
        }

        if ($scan->pages_errored > 0) {
            $this->error("  {$scan->pages_errored} pages could not be read, so this run cannot pass.");

            return 1;
        }

        $failed = false;

        foreach ((array) config('statamic-a11y-report.ci.fail_above', []) as $impact => $limit) {
            $count = (int) ($scan->issues_by_impact[$impact] ?? 0);

            if ($count > (int) $limit) {
                $this->error("  {$count} {$impact} issues, above the limit of {$limit}.");
                $failed = true;
            }
        }

        return $failed ? 1 : 0;
    }
}
