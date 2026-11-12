<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Scan;

use Bpmore\A11yGate\Gate\CouldNotRender;
use Bpmore\A11yGate\Gate\EntryHasNoPage;
use Bpmore\A11yGate\Gate\EntryRenderer;
use Bpmore\A11yReport\Engine\Engines;
use Bpmore\A11yReport\Engine\Finding;
use Bpmore\A11yReport\Engine\Fingerprint;
use Bpmore\A11yReport\Engine\RenderedPage;
use Bpmore\A11yReport\Jobs\FinalizeScan;
use Bpmore\A11yReport\Jobs\ScanPage as ScanPageJob;
use Bpmore\A11yReport\Models\Issue;
use Bpmore\A11yReport\Models\IssueState;
use Bpmore\A11yReport\Models\Scan;
use Bpmore\A11yReport\Models\ScanPage;
use Bpmore\A11yReport\Readability\PageReading;
use Bpmore\A11yReport\Readability\Readability;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Illuminate\Bus\Batch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\Site;
use Throwable;

/**
 * The scan, end to end: enumerate, dispatch, read one page, roll up.
 *
 * Every step is a method here and the jobs are thin wrappers that name the
 * step, so the whole pipeline can be run in one process by a test or by
 * `--sync` and the queued version cannot quietly do something different.
 *
 * Resumable, by construction rather than by a resume routine. Every page gets
 * a row marked `pending` before any job is dispatched, a job that reads a page
 * marks it, and a job that finds its page already marked does nothing. So a
 * worker killed mid-scan loses one page's worth of work, a retried job is
 * harmless, and resuming is "dispatch a batch for whatever is still pending".
 */
final class Scans
{
    /**
     * @param  array<string, mixed>  $config  the `pro` config block
     */
    public function __construct(
        private readonly Engines $engines,
        private readonly EntryRenderer $renderer,
        private readonly array $config,
    ) {}

    public function create(ScanScope $scope, string $trigger = Scan::TRIGGER_MANUAL, ?string $initiatedBy = null): Scan
    {
        // What this machine can actually run, which is where a fall back from
        // axe to the PHP checker is settled: once, here, and recorded, so the
        // row never claims an engine that did not read a single page.
        $engine = $this->engines->configured();

        return Scan::create([
            'uuid' => (string) Str::uuid(),
            'site' => $scope->site(),
            'trigger' => $trigger,
            'status' => Scan::QUEUED,
            'engine' => $engine->key(),
            'engine_version' => $engine->version(),
            'ruleset' => $engine->ruleset(),
            // What this engine could speak to, kept on the row: a report
            // generated later must not have to ask an engine that was not the
            // one that ran.
            'criteria' => $engine->criteria(),
            // The reading-level dimension, settled here for the same reason
            // the engine is: every page of this scan is graded against the
            // target in force now, not the one a worker's config names later.
            'readability' => Readability::fromConfig((array) ($this->config['readability'] ?? []))->toRecord(),
            'scope' => $scope->toArray(),
            'initiated_by' => $initiatedBy,
        ]);
    }

    /**
     * Enumerate the pages, record every one as pending, and hand them to a
     * batch. Returns once the batch is dispatched, which with `$sync` means
     * once the scan has finished.
     */
    public function start(Scan $scan, bool $sync = false): Scan
    {
        if (! $this->durableWork()) {
            return $this->startWork($scan, $sync);
        }
        $this->assertAtomicQueue($sync);

        $result = DB::connection(ReportDatabase::connectionName())->transaction(function () use ($scan, $sync): Scan {
            $this->lockSite($scan->site);
            $current = Scan::lockForUpdate()->findOrFail($scan->id);
            if ($current->status === Scan::COMPLETE || $current->batch_id !== null) {
                return $current;
            }
            if ($current->started_at !== null || ScanPage::where('scan_id', $current->id)->exists()) {
                return $this->dispatchPending($current, $sync);
            }

            return $this->startWork($current, $sync);
        });

        return $sync ? $this->runPendingSynchronously($result) : $result;
    }

    private function durableWork(): bool
    {
        return (bool) config('statamic-a11y-report.scan.cloud_durable_work', false);
    }

    private function assertAtomicQueue(bool $sync): void
    {
        if ($sync) {
            if (DB::connection(ReportDatabase::connectionName())->transactionLevel() !== 0) {
                throw new \RuntimeException('Durable synchronous reports must run outside an enclosing transaction.');
            }

            return;
        }
        $queue = (string) config('queue.default');
        $driver = config('queue.connections.'.$queue.'.driver');
        $connection = config('queue.connections.'.$queue.'.connection') ?: config('database.default');
        if (! in_array($driver, ['database', 'cloud-database'], true)
            || $connection !== ReportDatabase::connectionName()
            || config('queue.batching.database') !== $connection
            || (bool) config('queue.connections.'.$queue.'.after_commit', false)) {
            throw new \RuntimeException('Durable report work needs one database connection for reports, queue jobs and batches, with after_commit disabled.');
        }
    }

    private function startWork(Scan $scan, bool $sync = false): Scan
    {
        $scope = $this->scopeOf($scan);

        $scan->update(['status' => Scan::RUNNING, 'started_at' => now()]);

        $entries = $this->entries($scope);
        $readInstead = $this->oneEntryPerAddress($entries);

        $rows = $entries->map(function (Entry $entry) use ($scan, $readInstead) {
            $instead = $readInstead[(string) $entry->id()] ?? null;

            return [
                'scan_id' => $scan->id,
                'entry_id' => (string) $entry->id(),
                'collection' => (string) $entry->collection()?->handle(),
                'site' => (string) $entry->locale(),
                'url' => (string) $entry->absoluteUrl(),
                'path' => (string) $entry->url(),
                // Settled now rather than by a job, so it is the same on a
                // resume and is never dispatched: nothing about it is pending.
                'status' => $instead === null ? ScanPage::PENDING : ScanPage::SKIPPED,
                'error' => $instead === null ? null : "another published entry at this address, {$instead}, was read instead, so this one has no page of its own",
                'scanned_at' => $instead === null ? null : now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        });

        foreach ($rows->chunk(500) as $chunk) {
            ScanPage::insert($chunk->values()->all());
        }

        $scan->update(['pages_total' => $rows->count()]);

        return $this->dispatchPending($scan, $sync);
    }

    /**
     * Pick up whatever the last batch never reached. Safe to call on a scan
     * that finished: there is nothing pending, so it is finalised again with
     * the same numbers.
     */
    public function resume(Scan $scan, bool $sync = false): Scan
    {
        if (! $this->durableWork()) {
            return $this->resumeWork($scan, $sync);
        }
        $this->assertAtomicQueue($sync);

        $result = DB::connection(ReportDatabase::connectionName())->transaction(function () use ($scan, $sync): Scan {
            $this->lockSite($scan->site);
            $current = Scan::lockForUpdate()->findOrFail($scan->id);

            return $this->resumeWork($current, $sync);
        });

        return $sync ? $this->runPendingSynchronously($result) : $result;
    }

    private function resumeWork(Scan $scan, bool $sync = false): Scan
    {
        // A scan with no pages of its own was never listed, so there is
        // nothing to pick up: start it instead.
        //
        // `StartScan` is itself a queued job, so on a site whose queue has no
        // worker a scan sits at "queued" having enumerated nothing. Resuming
        // it dispatched the empty set of pending pages, which finished it as
        // a complete scan of nothing, and a complete scan that met no page
        // met none of the pages every open issue was on: the whole queue
        // closed as "page removed" in one go. The overview offers this exact
        // command on that exact scan, so the product's own advice was the
        // shortest path to it.
        //
        // A scan of a scope that genuinely holds no pages is not this: it was
        // listed, found nothing, and finished. It is never resumed, because
        // it is already complete.
        if (ScanPage::where('scan_id', $scan->id)->doesntExist()) {
            return $this->durableWork() ? $this->startWork($scan, $sync) : $this->start($scan, $sync);
        }

        if ($scan->status === Scan::CANCELLED || $scan->status === Scan::QUEUED) {
            $scan->update(['status' => Scan::RUNNING, 'started_at' => $scan->started_at ?? now()]);
        }

        return $this->dispatchPending($scan, $sync);
    }

    private function lockSite(?string $site): void
    {
        Scan::where('site', $site)->orderBy('id')->lockForUpdate()->get(['id']);
    }

    private function runPendingSynchronously(Scan $scan): Scan
    {
        foreach (ScanPage::where('scan_id', $scan->id)->where('status', ScanPage::PENDING)->orderBy('id')->pluck('id') as $pageId) {
            $this->scanPage($scan->id, $pageId);
        }
        $this->finalize($scan->id);

        return $scan->refresh();
    }

    private function dispatchPending(Scan $scan, bool $sync): Scan
    {
        // Commit enumeration before synchronous page turns. A sync queue batch
        // otherwise wraps every page in its own outer batch transaction.
        if ($sync && $this->durableWork()) {
            return $scan->refresh();
        }
        $pending = ScanPage::where('scan_id', $scan->id)->where('status', ScanPage::PENDING)->pluck('id');

        if ($pending->isEmpty()) {
            // Nothing to dispatch and nothing to wait for. An empty batch
            // never fires its callbacks, so a scan of zero pages would sit at
            // "running" forever if this went through the queue.
            $this->finalize($scan->id);

            return $scan->refresh();
        }

        $scanId = $scan->id;

        $batch = Bus::batch($pending->map(fn (int $pageId) => new ScanPageJob($scanId, $pageId))->all())
            ->name("a11y scan {$scan->uuid}")
            // A page that fails outright, past the job's own catch, must not
            // take the other 39,999 with it. It is recorded as an error on its
            // own row and the scan carries on.
            ->allowFailures()
            // `finally`, not `then`: `then` is skipped when any job failed, and
            // a scan with one broken page still has to be rolled up. Static so
            // the closure serialises without dragging this service along.
            ->finally(static function (Batch $batch) use ($scanId) {
                FinalizeScan::dispatch($scanId, $batch->cancelled())
                    ->onConnection($batch->options['connection'] ?? null)
                    ->onQueue($batch->options['queue'] ?? null);
            });

        if ($sync) {
            $batch->onConnection('sync');
        }

        $dispatched = $batch->dispatch();

        // On the sync connection every job, and the finalise, has already run
        // by the time dispatch() returns, so only the batch id is written
        // here: writing the status too would put "running" back on a scan
        // that just finished.
        Scan::whereKey($scanId)->update(['batch_id' => $dispatched->id]);

        return $scan->refresh();
    }

    /**
     * Read one page and write what was found. Idempotent: a page already
     * taken by another worker, or by an earlier attempt of this job, is left
     * alone.
     */
    public function scanPage(int $scanId, int $pageId): void
    {
        if (! $this->durableWork()) {
            $this->scanPageWork($scanId, $pageId);

            return;
        }
        // Browser reads are repeatable. Hold the page's write boundary for the
        // bounded turn so a killed process rolls back everything and a second
        // worker must re-read the committed status before calling the browser.
        DB::connection(ReportDatabase::connectionName())->transaction(function () use ($scanId, $pageId): void {
            $scan = Scan::lockForUpdate()->find($scanId);
            if (! $scan || $scan->isFinished()) {
                return;
            }
            $page = ScanPage::whereKey($pageId)->where('scan_id', $scanId)->lockForUpdate()->first();
            if ($page?->status === ScanPage::PENDING) {
                $this->scanPageWork($scanId, $pageId);
            }
        });
    }

    private function scanPageWork(int $scanId, int $pageId): void
    {
        $page = ScanPage::with('scan')->whereKey($pageId)->where('scan_id', $scanId)->first();

        if ($page === null || $page->status !== ScanPage::PENDING) {
            return;
        }

        // A scan that has ended is not added to. Its pages stay pending, which
        // is what marks it as the scan that stopped early, so a job still on
        // the queue when somebody cancelled would otherwise find its page
        // pending and read it: findings written against a scan whose counts
        // were rolled up before they existed.
        if ($page->scan?->isFinished()) {
            return;
        }

        $entry = Entries::find($page->entry_id);

        if ($entry === null) {
            $this->markPage($page, ScanPage::ERROR, error: 'the entry no longer exists');

            return;
        }

        $started = hrtime(true);

        try {
            $html = $this->renderer->render($entry);
        } catch (EntryHasNoPage $e) {
            // No page of its own: nothing was served, so there is nothing to
            // read. Not an error, and not silently dropped either.
            $this->markPage($page, ScanPage::SKIPPED, error: $e->getMessage());

            return;
        } catch (CouldNotRender $e) {
            $this->markPage($page, ScanPage::ERROR, error: $e->getMessage(), renderMs: $this->elapsed($started));

            return;
        } catch (Throwable $e) {
            $this->markPage($page, ScanPage::ERROR, error: $e::class.': '.$e->getMessage(), renderMs: $this->elapsed($started));

            return;
        }

        $renderMs = $this->elapsed($started);

        try {
            // The engine this scan says read it, and never whichever one the
            // config file names now. They are the same process only when the
            // scan ran with `--sync`: a queued page is read by a worker that
            // has its own copy of the config, and reading it with the other
            // engine would fill this row with findings it does not describe.
            $result = $this->engines->make((string) $page->scan->engine)->scan(new RenderedPage($page->url, $html));
        } catch (Throwable $e) {
            $this->markPage($page, ScanPage::ERROR, error: 'the engine failed: '.$e->getMessage(), renderMs: $renderMs);

            return;
        }

        $issues = $this->groupByFingerprint($result->findings, Fingerprint::page($page->site, $page->path));
        $reading = $this->readingOf($page, $html);

        DB::connection(ReportDatabase::connectionName())->transaction(function () use ($page, $result, $issues, $renderMs, $reading) {
            foreach ($issues as $fingerprint => ['finding' => $finding, 'occurrences' => $occurrences]) {
                Issue::create([
                    'scan_id' => $page->scan_id,
                    'page_id' => $page->id,
                    'fingerprint' => $fingerprint,
                    'rule_id' => $finding->ruleId,
                    'label' => $finding->label,
                    'wcag_criteria' => $finding->criteria,
                    'impact' => $finding->impact,
                    'selector' => $finding->selector,
                    'pointer' => $finding->pointer,
                    'html_snippet' => $finding->snippet,
                    'message' => $finding->message,
                    'remedy' => $finding->remedy,
                    'help_url' => $finding->helpUrl,
                    'occurrences' => $occurrences,
                ]);
            }

            $page->update([
                'status' => ScanPage::SCANNED,
                'status_code' => 200,
                'render_ms' => $renderMs,
                'issues_count' => count($issues),
                'coverage' => $result->coverage,
                'coverage_summary' => $result->coverageSummary,
                'readability' => $reading?->toArray(),
                'scanned_at' => now(),
            ]);
        });
    }

    /**
     * The page's reading level, the way its scan said it would be measured,
     * or null when the scan chose not to measure. Never a reason the page
     * fails: the grader answers with a reading that says what went wrong.
     */
    private function readingOf(ScanPage $page, string $html): ?PageReading
    {
        $readability = Readability::fromRecord($page->scan?->readability);

        if (! $readability->enabled) {
            return null;
        }

        return $readability->grade($html, (string) (Site::get($page->site)?->locale() ?? ''));
    }

    /**
     * The queue gave up on a page's job, past every retry. The page is marked
     * so the scan can say it was not read rather than counting it as clean.
     */
    public function markPageFailed(int $scanId, int $pageId, ?Throwable $e): void
    {
        if (! $this->durableWork()) {
            $this->markPageFailedWork($scanId, $pageId, $e);

            return;
        }
        DB::connection(ReportDatabase::connectionName())->transaction(function () use ($scanId, $pageId, $e): void {
            Scan::whereKey($scanId)->lockForUpdate()->first();
            ScanPage::whereKey($pageId)->where('scan_id', $scanId)->lockForUpdate()->first();
            // Re-read after waiting for a live page's result. A stale failure
            // callback must not replace a committed successful observation.
            $this->markPageFailedWork($scanId, $pageId, $e);
        });
    }

    private function markPageFailedWork(int $scanId, int $pageId, ?Throwable $e): void
    {
        $page = ScanPage::whereKey($pageId)->where('scan_id', $scanId)->first();

        if ($page !== null && $page->status === ScanPage::PENDING) {
            $this->markPage($page, ScanPage::ERROR, error: 'the job failed: '.($e?->getMessage() ?: 'no reason given'));
        }
    }

    /**
     * Roll the pages up onto the scan, compare with the last scan of the same
     * site, and move issue states: seen again, seen for the first time, or no
     * longer on a page that was read.
     */
    public function finalize(int $scanId, bool $cancelled = false): void
    {
        if (! $this->durableWork()) {
            $this->finalizeWork($scanId, $cancelled);

            return;
        }
        DB::connection(ReportDatabase::connectionName())->transaction(function () use ($scanId, $cancelled): void {
            $scan = Scan::find($scanId);
            if (! $scan) {
                return;
            }
            // The retained rows provide a common ordered lock for this site.
            // An older resumed scan cannot race a newer scan's final rollup.
            $this->lockSite($scan->site);
            $this->finalizeWork($scanId, $cancelled);
        });
    }

    private function finalizeWork(int $scanId, bool $cancelled = false): void
    {
        $scan = Scan::find($scanId);

        if ($scan === null || $scan->isFinished()) {
            return;
        }

        $counts = ScanPage::where('scan_id', $scanId)
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $byImpact = Issue::where('scan_id', $scanId)
            ->selectRaw('impact, count(*) as n')
            ->groupBy('impact')
            ->pluck('n', 'impact');

        $issuesByImpact = [];

        foreach (Finding::IMPACTS as $impact) {
            $issuesByImpact[$impact] = (int) ($byImpact[$impact] ?? 0);
        }

        $scanned = (int) ($counts[ScanPage::SCANNED] ?? 0);
        $errored = (int) ($counts[ScanPage::ERROR] ?? 0);
        $skipped = (int) ($counts[ScanPage::SKIPPED] ?? 0);
        $pending = (int) ($counts[ScanPage::PENDING] ?? 0);

        $status = match (true) {
            $cancelled, $pending > 0 => Scan::CANCELLED,
            $scan->pages_total > 0 && $scanned === 0 => Scan::FAILED,
            default => Scan::COMPLETE,
        };

        $diff = null;

        if ($status === Scan::COMPLETE) {
            $diff = $this->diffAgainstPrevious($scan);
            $newerComplete = $this->durableWork() && Scan::where('site', $scan->site)
                ->where('engine', $scan->engine)->where('status', Scan::COMPLETE)->where('id', '>', $scan->id)->exists();
            if (! $newerComplete) {
                $this->updateIssueStates($scan);
            }
        }

        $scan->update([
            'readability' => $this->readabilityRollup($scan),
            'status' => $status,
            'finished_at' => now(),
            'pages_scanned' => $scanned,
            'pages_errored' => $errored,
            'pages_skipped' => $skipped,
            'issues_total' => array_sum($issuesByImpact),
            'issues_by_impact' => $issuesByImpact,
            'diff' => $diff,
            'error' => $status === Scan::FAILED ? 'no page could be read' : null,
        ]);
    }

    /**
     * The scan's readability record with the pages rolled up onto it, or
     * as it was when the dimension did not run. Rolled up whatever the
     * scan's status, because a cancelled scan's graded pages were graded
     * and the record must not claim otherwise.
     *
     * @return array<string, mixed>|null
     */
    private function readabilityRollup(Scan $scan): ?array
    {
        $record = $scan->readability;

        if (! is_array($record) || ! ($record['enabled'] ?? false)) {
            return $record;
        }

        $readings = ScanPage::where('scan_id', $scan->id)
            ->where('status', ScanPage::SCANNED)
            ->whereNotNull('readability')
            ->orderBy('id')
            ->lazy(500)
            ->map(fn (ScanPage $page) => PageReading::fromArray((array) $page->readability));

        return array_merge($record, Readability::fromRecord($record)->summary($readings));
    }

    /**
     * @return array<string, mixed>
     */
    private function diffAgainstPrevious(Scan $scan): array
    {
        $previous = Scan::where('status', Scan::COMPLETE)
            ->where('id', '<', $scan->id)
            ->where('site', $scan->site)
            // Against the last scan by the same engine. Comparing axe to the
            // PHP checker would report every finding of one as new and every
            // finding of the other as fixed, in a sentence the report prints.
            ->where('engine', $scan->engine)
            ->orderByDesc('id')
            ->first();

        $current = Issue::where('scan_id', $scan->id)->pluck('fingerprint')->unique();

        if ($previous === null) {
            return ['previous_scan_id' => null, 'new' => $current->count(), 'fixed' => 0, 'unchanged' => 0];
        }

        $before = Issue::where('a11y_issues.scan_id', $previous->id)
            ->join('a11y_scan_pages', 'a11y_scan_pages.id', '=', 'a11y_issues.page_id')
            ->get(['a11y_issues.fingerprint', 'a11y_scan_pages.site', 'a11y_scan_pages.path'])
            ->mapWithKeys(fn ($row) => [$row->fingerprint => Fingerprint::page($row->site, $row->path)]);

        $read = ScanPage::where('scan_id', $scan->id)
            ->where('status', ScanPage::SCANNED)
            ->get(['site', 'path'])
            ->map(fn (ScanPage $p) => Fingerprint::page($p->site, $p->path))
            ->flip();

        // Fixed means gone from a page this scan actually read. A problem on a
        // page outside this scan's scope, or on a page that errored, is
        // unknown, not fixed, and a scoped scan that "fixed" everything it did
        // not look at would be the silent zero at site scale.
        $fixed = $before->keys()->diff($current)->filter(fn (string $fp) => $read->has($before[$fp]));

        return [
            'previous_scan_id' => $previous->id,
            'new' => $current->diff($before->keys())->count(),
            'fixed' => $fixed->count(),
            'unchanged' => $current->intersect($before->keys())->count(),
        ];
    }

    private function updateIssueStates(Scan $scan): void
    {
        $now = now();

        Issue::where('a11y_issues.scan_id', $scan->id)
            ->join('a11y_scan_pages', 'a11y_scan_pages.id', '=', 'a11y_issues.page_id')
            ->select(['a11y_issues.fingerprint', 'a11y_issues.rule_id', 'a11y_issues.impact', 'a11y_scan_pages.url', 'a11y_scan_pages.site', 'a11y_scan_pages.path'])
            ->orderBy('a11y_issues.id')
            ->chunk(500, function (Collection $issues) use ($scan, $now) {
                $existing = IssueState::whereIn('fingerprint', $issues->pluck('fingerprint'))->get()->keyBy('fingerprint');

                foreach ($issues as $issue) {
                    $state = $existing->get($issue->fingerprint);

                    if ($state === null) {
                        IssueState::create([
                            'fingerprint' => $issue->fingerprint,
                            'status' => IssueState::OPEN,
                            'engine' => $scan->engine,
                            'url' => $issue->url,
                            'site' => $issue->site,
                            'path' => $issue->path,
                            'rule_id' => $issue->rule_id,
                            'impact' => $issue->impact,
                            'first_seen_at' => $now,
                            'last_seen_at' => $now,
                            'last_scan_id' => $scan->id,
                        ]);

                        continue;
                    }

                    $changes = ['last_seen_at' => $now, 'last_scan_id' => $scan->id, 'url' => $issue->url, 'impact' => $issue->impact];

                    // Back after being fixed is a regression, and back after
                    // its page was removed is the page returning; both reopen.
                    // "Won't fix" and "false positive" are left exactly as the
                    // person set them: the scanner reports, it does not
                    // overrule.
                    if (in_array($state->status, IssueState::reopenableByScan(), true)) {
                        $changes['status'] = IssueState::OPEN;
                        $changes['resolved_at'] = null;
                    }

                    $state->update($changes);
                }
            });

        $current = Issue::where('scan_id', $scan->id)->pluck('fingerprint');

        ScanPage::where('scan_id', $scan->id)
            ->where('status', ScanPage::SCANNED)
            ->orderBy('id')
            ->chunk(500, function (Collection $pages) use ($scan, $current, $now) {
                // Per site within the chunk: the same path on two sites is
                // two pages, and closing one must not close the other.
                foreach ($pages->groupBy('site') as $site => $onSite) {
                    IssueState::where('site', $site)
                        ->whereIn('path', $onSite->pluck('path'))
                        ->whereIn('status', IssueState::closableByScan())
                        // Only what an engine of this kind found. Two engines
                        // are two sets of answers, and axe not looking for
                        // something the gate's checker found is not evidence
                        // that anybody fixed it.
                        ->where('engine', $scan->engine)
                        ->whereNotIn('fingerprint', $current)
                        ->update([
                            'status' => IssueState::FIXED,
                            'resolved_at' => $now,
                            'last_scan_id' => $scan->id,
                            'updated_by' => null,
                        ]);
                }
            });

        $this->closeIssuesOnRemovedPages($scan, $now);
    }

    /**
     * An open issue on a page this scan should have met and did not is an
     * issue on a page that is no longer served: unpublished, deleted, or
     * moved. It is marked as such, never as fixed.
     *
     * Only a scan that enumerated everything the page could have been among
     * can say that. A scan narrowed by `--since` never enumerates unchanged
     * pages, so absence means nothing there; a scan narrowed to a site or a
     * collection can only speak for those; and a page matching an excluded
     * URL was left out on purpose. A page that errored has a row and is not
     * absent: it is unknown, and unknown is not removed. Found on a test
     * site where the home page moved from /home to / and its footer issue
     * stayed open with nothing that would ever close it.
     */
    private function closeIssuesOnRemovedPages(Scan $scan, Carbon $now): void
    {
        $scope = $this->scopeOf($scan);

        if ($scope->since !== null) {
            return;
        }

        $met = ScanPage::where('scan_id', $scan->id)
            ->get(['site', 'path'])
            ->map(fn (ScanPage $p) => Fingerprint::page($p->site, $p->path))
            ->flip();

        IssueState::query()
            ->join('a11y_issues', function ($join) {
                $join->on('a11y_issues.fingerprint', '=', 'a11y_issue_states.fingerprint')
                    ->on('a11y_issues.scan_id', '=', 'a11y_issue_states.last_scan_id');
            })
            ->join('a11y_scan_pages', 'a11y_scan_pages.id', '=', 'a11y_issues.page_id')
            ->whereIn('a11y_issue_states.status', IssueState::closableByScan())
            ->where('a11y_issue_states.engine', $scan->engine)
            ->when($scope->sites !== [], fn ($q) => $q->whereIn('a11y_issue_states.site', $scope->sites))
            ->when($scope->collections !== [], fn ($q) => $q->whereIn('a11y_scan_pages.collection', $scope->collections))
            ->select(['a11y_issue_states.fingerprint', 'a11y_issue_states.site', 'a11y_issue_states.path', 'a11y_issue_states.url'])
            ->orderBy('a11y_issue_states.fingerprint')
            ->chunk(500, function (Collection $states) use ($met, $scope, $scan, $now) {
                $gone = $states
                    ->filter(fn ($s) => ! $met->has(Fingerprint::page($s->site, $s->path)))
                    ->filter(fn ($s) => $scope->excludeUrls === [] || ! Str::is($scope->excludeUrls, $s->url))
                    ->pluck('fingerprint');

                if ($gone->isNotEmpty()) {
                    IssueState::whereIn('fingerprint', $gone->all())->update([
                        'status' => IssueState::PAGE_REMOVED,
                        'resolved_at' => $now,
                        'last_scan_id' => $scan->id,
                        'updated_by' => null,
                    ]);
                }
            });
    }

    /**
     * @param  array<int, Finding>  $findings
     * @param  string  $page  the site and path, from `Fingerprint::page()`
     * @return array<string, array{finding: Finding, occurrences: int}>
     */
    private function groupByFingerprint(array $findings, string $page): array
    {
        $grouped = [];

        foreach ($findings as $finding) {
            $fingerprint = Fingerprint::of($finding, $page);

            if (isset($grouped[$fingerprint])) {
                $grouped[$fingerprint]['occurrences']++;
            } else {
                $grouped[$fingerprint] = ['finding' => $finding, 'occurrences' => 1];
            }
        }

        return $grouped;
    }

    /**
     * @return Collection<int, Entry>
     */
    private function entries(ScanScope $scope): Collection
    {
        $query = Entries::query()->where('published', true);

        if ($scope->sites !== []) {
            $query->whereIn('site', $scope->sites);
        }

        if ($scope->collections !== []) {
            $query->whereIn('collection', $scope->collections);
        }

        return $query->get()
            ->filter(function (Entry $entry) use ($scope) {
                $url = $entry->absoluteUrl();

                if (! is_string($url) || $url === '') {
                    // Not a page the site serves. Never in scope, so not
                    // dropped quietly: it was never counted.
                    return false;
                }

                if ($scope->excludeUrls !== [] && Str::is($scope->excludeUrls, $url)) {
                    return false;
                }

                if ($scope->since !== null) {
                    $modified = $entry->lastModified();

                    // An entry with no modification time at all is kept. The
                    // cheap mistake is reading a page that did not change; the
                    // expensive one is skipping a page that did.
                    return $modified === null || $modified->greaterThanOrEqualTo($scope->since);
                }

                return true;
            })
            ->values();
    }

    /**
     * Which entries share an address with another, and which entry each of
     * them gives way to.
     *
     * An issue's fingerprint is the site and path, so two entries at one
     * address wrote the same fingerprints into one scan. The unique index on
     * `(scan_id, fingerprint)` refused the second, the job threw, and a scan
     * run with `--sync` died cancelled, every other page with it. Two
     * published entries at one path come from a script or import that saved a
     * slug twice, or a Stache still holding a deleted file. A visitor gets one
     * page there, so the scan reads one: the entry Statamic serves at that
     * address, or, when it serves one outside this group (a draft, another
     * scope), the lowest id, so a rerun picks the same one.
     *
     * @param  Collection<int, Entry>  $entries
     * @return array<string, string> the id of each entry not read, to the id of the one read instead
     */
    private function oneEntryPerAddress(Collection $entries): array
    {
        $readInstead = [];

        $groups = $entries->groupBy(fn (Entry $entry) => Fingerprint::page((string) $entry->locale(), (string) $entry->url()));

        foreach ($groups as $group) {
            if ($group->count() < 2) {
                continue;
            }

            $ids = $group->map(fn (Entry $entry) => (string) $entry->id())->sort()->values();
            $first = $group->first();
            $served = (string) Entries::findByUri((string) $first->uri(), (string) $first->locale())?->id();
            $kept = $ids->contains($served) ? $served : $ids->first();

            foreach ($ids as $id) {
                if ($id !== $kept) {
                    $readInstead[$id] = $kept;
                }
            }
        }

        return $readInstead;
    }

    private function scopeOf(Scan $scan): ScanScope
    {
        $scope = (array) ($scan->scope ?? []);

        return new ScanScope(
            sites: (array) ($scope['sites'] ?? []),
            collections: (array) ($scope['collections'] ?? []),
            since: isset($scope['since']) ? Carbon::parse($scope['since']) : null,
            excludeUrls: (array) ($scope['exclude_urls'] ?? []),
        );
    }

    private function markPage(ScanPage $page, string $status, ?string $error = null, ?int $renderMs = null): void
    {
        $page->update([
            'status' => $status,
            'error' => $error,
            'render_ms' => $renderMs,
            'scanned_at' => now(),
        ]);
    }

    private function elapsed(int|float $startedNs): int
    {
        return (int) round((hrtime(true) - $startedNs) / 1_000_000);
    }
}
