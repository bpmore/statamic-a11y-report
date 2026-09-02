<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Trends;

use Bpmore\A11yReport\Engine\Finding;
use Bpmore\A11yReport\Models\IssueState;
use Bpmore\A11yReport\Models\Scan;
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

    private function openStates()
    {
        $query = IssueState::whereIn('status', [IssueState::OPEN, IssueState::IN_PROGRESS]);

        if ($this->site !== null) {
            $query->where('site', $this->site);
        }

        return $query;
    }
}
