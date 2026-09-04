<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Trends;

use Bpmore\A11yReport\Engine\Finding;
use Bpmore\A11yReport\Models\IssueState;
use Bpmore\A11yReport\Models\Scan;
use Bpmore\A11yReport\Models\ScanPage;
use Bpmore\A11yReport\Remediation\Policy;
use Bpmore\A11yReport\Settings;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The numbers the overview and the dashboard widget draw, for one site or
 * for all of them.
 *
 * Filtering by site is strict: a scan of every site carries counts for every
 * site, and showing it under one site's name would be a number with the wrong
 * denominator. The issue states carry a site of their own, so "open now" is
 * exact either way.
 */
final class Overview
{
    public function __construct(
        private readonly ReportDatabase $database,
        public readonly ?string $site = null,
    ) {}

    public function installed(): bool
    {
        return $this->database->isInstalled();
    }

    public function latest(): ?Scan
    {
        return $this->scans()->orderByDesc('id')->first();
    }

    /**
     * The latest scan, when it is queued or running and nothing has happened
     * to it for longer than the configured wait.
     *
     * A scan on a queue nobody is working looks exactly like a scan that is
     * about to start, and it looked that way for an afternoon on a site
     * whose only worker served a different queue. "Reload to watch it go"
     * was the page's whole advice. Now it says how long it has been, and
     * what to run.
     *
     * @return array{scan: Scan, minutes: int, pages_read: int}|null
     */
    public function stale(int $afterMinutes = 10): ?array
    {
        $scan = $this->latest();

        if ($scan === null || ! in_array($scan->status, [Scan::QUEUED, Scan::RUNNING], true)) {
            return null;
        }

        $lastActivity = ScanPage::where('scan_id', $scan->id)->max('scanned_at');
        $since = $lastActivity !== null ? Carbon::parse($lastActivity) : ($scan->started_at ?? $scan->created_at);

        if ($since === null || $since->greaterThan(now()->subMinutes($afterMinutes))) {
            return null;
        }

        return [
            'scan' => $scan,
            'minutes' => (int) $since->diffInMinutes(now()),
            'pages_read' => (int) ScanPage::where('scan_id', $scan->id)->where('status', ScanPage::SCANNED)->count(),
        ];
    }

    /** @return Collection<int, Scan> newest first */
    public function history(int $limit = 20): Collection
    {
        return $this->scans()->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * Completed scans in the window, oldest first, shaped for the chart.
     *
     * @return array<int, array{at: Carbon, value: int, label: string, scan: Scan}>
     */
    public function trend(int $days = 90): array
    {
        return $this->scans()
            ->where('status', Scan::COMPLETE)
            ->where('finished_at', '>=', now()->subDays($days))
            ->orderBy('finished_at')
            ->get()
            ->map(fn (Scan $scan) => [
                'at' => $scan->finished_at,
                'value' => (int) $scan->issues_total,
                'label' => $scan->finished_at->format('j M'),
                'scan' => $scan,
            ])
            ->all();
    }

    /** @return array<string, int> every impact, zero when none */
    public function openByImpact(): array
    {
        $counts = $this->openStates()
            ->selectRaw('impact, count(*) as n')
            ->groupBy('impact')
            ->pluck('n', 'impact');

        $result = [];

        foreach (Finding::IMPACTS as $impact) {
            $result[$impact] = (int) ($counts[$impact] ?? 0);
        }

        return $result;
    }

    public function openTotal(): int
    {
        return array_sum($this->openByImpact());
    }

    /** How long the longest-standing open problem has been open, in days. */
    public function oldestOpenDays(): ?int
    {
        $oldest = $this->openStates()->min('first_seen_at');

        return $oldest === null ? null : (int) Carbon::parse($oldest)->diffInDays(now());
    }

    private function scans()
    {
        $query = Scan::query();

        if ($this->site !== null) {
            $query->where('site', $this->site);
        }

        return $query;
    }

    /**
     * How many open problems are past the target the policy sets for their
     * impact.
     */
    public function overdueTotal(): int
    {
        return $this->policy()->overdue($this->openStates())->count();
    }

    /** Acceptances that have run out and are counted as open again. */
    public function expiredExceptions(): int
    {
        return $this->states()
            ->where('status', IssueState::WONT_FIX)
            ->whereNotNull('exception_expires_at')
            ->where('exception_expires_at', '<=', now())
            ->count();
    }

    private function policy(): Policy
    {
        return Policy::fromConfig(app(Settings::class)->block('report'));
    }

    /**
     * Open right now, which includes an acceptance that has run out. One
     * definition, on the model, shared with the queue and the report: three
     * copies of this answer used to exist and would have disagreed the first
     * time one of them changed.
     */
    private function openStates()
    {
        return IssueState::openNow($this->states());
    }

    private function states()
    {
        $query = IssueState::query();

        if ($this->site !== null) {
            $query->where('site', $this->site);
        }

        return $query;
    }
}
