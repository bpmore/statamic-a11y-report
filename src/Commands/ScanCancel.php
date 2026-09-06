<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Commands;

use Bpmore\A11yReport\Models\Scan as ScanModel;
use Bpmore\A11yReport\Models\ScanPage;
use Bpmore\A11yReport\Scan\Scans;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Statamic\Console\RunsInPlease;

/**
 * End a scan that is never going to finish.
 *
 * A scheduled scan is skipped while anything is queued or running, so one
 * scan nobody can finish stops the schedule until somebody clears it. The
 * cure was `--resume --sync`, which finishes the pages in one process, and
 * that is the right answer whenever the pages can be read at all. This is for
 * when they cannot: a page whose template never returns, a site that has gone
 * away, a batch whose jobs are lost.
 *
 * `cancelled` has been one of the statuses a scan can end at since the scan
 * layer was built, and nothing a person could reach ever set it. The only
 * lever was an UPDATE against the addon's own tables, which is not a lever a
 * commercial addon should be asking anybody to pull.
 *
 * **A cancelled scan is never reported on.** Only a complete scan can be, and
 * that check is where it always was. What was read is kept, because the pages
 * that were read really were read and the overview counts them; what is
 * refused is calling any of it a conformance report.
 */
class ScanCancel extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:a11y:scan:cancel
        {scan : The id of the scan to end, as the overview and a11y:scan print it.}
        {--force : Do not ask.}';

    protected $description = 'End a scan that cannot finish, so scheduled scans can run again.';

    public function handle(Scans $scans, ReportDatabase $database): int
    {
        if (! $database->isInstalled()) {
            $this->error('The report tables are not installed, so there is no scan to cancel.');

            return 1;
        }

        $uuid = (string) $this->argument('scan');
        $scan = ScanModel::where('uuid', $uuid)->first();

        if ($scan === null) {
            $this->error("No scan has the id [{$uuid}].");

            return 1;
        }

        if ($scan->isFinished()) {
            // Not an error. Somebody clearing a block wants to know the block
            // is gone, and it is.
            $this->line('');
            $this->line("  Scan <options=bold>{$scan->uuid}</> already ended as <options=bold>{$scan->status}</>. Nothing to do.");
            $this->line('');

            return 0;
        }

        $pending = ScanPage::where('scan_id', $scan->id)->where('status', ScanPage::PENDING)->count();
        $read = ScanPage::where('scan_id', $scan->id)->where('status', ScanPage::SCANNED)->count();

        $this->line('');
        $this->line("  Scan <options=bold>{$scan->uuid}</> is <options=bold>{$scan->status}</>, with {$read} of {$scan->pages_total} pages read and {$pending} still to read.");

        if ($pending > 0) {
            $this->line('  <fg=gray>To finish it instead, which reads the rest and can be reported on:</>');
            $this->line("  <fg=gray>php please a11y:scan --resume={$scan->uuid} --sync</>");
        }

        $this->line('');

        if (! $this->option('force') && ! $this->confirm('End this scan? It can never be reported on afterwards.', false)) {
            $this->line('  Left alone.');
            $this->line('');

            return 0;
        }

        $this->stopTheBatch($scan);

        // Through the same roll-up every other scan ends by, rather than an
        // UPDATE of its own. A scan that stopped early has counts to write and
        // a finished time to record, and two places that end a scan are two
        // places to keep in step.
        $scans->finalize($scan->id, cancelled: true);

        $scan->refresh();

        $this->line("  Scan <options=bold>{$scan->uuid}</> is now <options=bold>{$scan->status}</>.");

        if (($blocking = ScanModel::whereIn('status', [ScanModel::QUEUED, ScanModel::RUNNING])->count()) > 0) {
            // Said rather than left to be discovered on the next Sunday that
            // passes without a scan.
            $this->line('');
            $this->warn("  {$blocking} other ".($blocking === 1 ? 'scan has' : 'scans have').' not finished, so scheduled scans are still skipped.');
            $this->line('  <fg=gray>The overview names the oldest of them.</>');
        }

        $this->line('');

        return 0;
    }

    /**
     * Stop the queued jobs, where there are any left to stop.
     *
     * Best effort on purpose. The batch may be long gone, or on a connection
     * that is not there any more, and neither is a reason to leave the scan
     * running for ever: the point of this command is the scan whose queue
     * cannot be trusted.
     */
    private function stopTheBatch(ScanModel $scan): void
    {
        if ($scan->batch_id === null) {
            return;
        }

        try {
            Bus::findBatch($scan->batch_id)?->cancel();
        } catch (\Throwable $e) {
            $this->line('  <fg=gray>The queue batch could not be cancelled ('.$e->getMessage().'), so any jobs still on the queue will find the scan already ended and do nothing.</>');
        }
    }
}
