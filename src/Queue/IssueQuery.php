<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Queue;

use Bpmore\A11yReport\Engine\Finding;
use Bpmore\A11yReport\Models\IssueState;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The remediation queue: every problem the scans know about, with what a
 * person has decided about it, filtered and ordered for triage.
 *
 * The queue is the issue states, which survive scans, joined to the issue
 * row from the scan that last saw each one, for its wording. Ordered by
 * impact and then oldest first, because the thing a queue exists to surface
 * is the serious problem nobody has looked at for forty days.
 */
final class IssueQuery
{
    public const STATUSES = [IssueState::OPEN, IssueState::IN_PROGRESS, IssueState::FIXED, IssueState::PAGE_REMOVED, IssueState::WONT_FIX, IssueState::FALSE_POSITIVE];

    public const PER_PAGE = 50;

    /** @var array<string, string> */
    public readonly array $filters;

    /**
     * @param  array<string, mixed>  $input  request query: site, collection, criterion, impact, status, assignee, page
     */
    public function __construct(array $input)
    {
        $pick = fn (string $key, array $allowed = []) => (function () use ($input, $key, $allowed) {
            $v = $input[$key] ?? null;
            $v = is_string($v) ? trim($v) : '';

            return $v === '' || ($allowed !== [] && ! in_array($v, $allowed, true)) ? '' : $v;
        })();

        $this->filters = [
            'site' => $pick('site'),
            'collection' => $pick('collection'),
            'criterion' => $pick('criterion'),
            'impact' => $pick('impact', Finding::IMPACTS),
            // "open" by default means open or in progress: the queue shows
            // work, and finished work only when asked for.
            'status' => $pick('status', array_merge(self::STATUSES, ['all', 'active'])) ?: 'active',
            'assignee' => $pick('assignee'),
        ];
    }

    public function query(): Builder
    {
        $q = IssueState::query()
            ->join('a11y_issues', function ($join) {
                $join->on('a11y_issues.fingerprint', '=', 'a11y_issue_states.fingerprint')
                    ->on('a11y_issues.scan_id', '=', 'a11y_issue_states.last_scan_id');
            })
            ->join('a11y_scan_pages', 'a11y_scan_pages.id', '=', 'a11y_issues.page_id')
            ->select([
                'a11y_issue_states.*',
                'a11y_issues.label', 'a11y_issues.message', 'a11y_issues.remedy', 'a11y_issues.pointer', 'a11y_issues.selector',
                'a11y_issues.occurrences', 'a11y_issues.wcag_criteria', 'a11y_scan_pages.collection',
            ]);

        $f = $this->filters;

        match ($f['status']) {
            'all' => null,
            'active' => $q->whereIn('a11y_issue_states.status', [IssueState::OPEN, IssueState::IN_PROGRESS]),
            default => $q->where('a11y_issue_states.status', $f['status']),
        };

        if ($f['site'] !== '') {
            $q->where('a11y_issue_states.site', $f['site']);
        }

        if ($f['collection'] !== '') {
            $q->where('a11y_scan_pages.collection', $f['collection']);
        }

        if ($f['criterion'] !== '') {
            $q->where('a11y_issues.label', $f['criterion']);
        }

        if ($f['impact'] !== '') {
            $q->where('a11y_issue_states.impact', $f['impact']);
        }

        if ($f['assignee'] !== '') {
            $f['assignee'] === '-' ? $q->whereNull('a11y_issue_states.assigned_to') : $q->where('a11y_issue_states.assigned_to', $f['assignee']);
        }

        return $q
            ->orderByRaw("case a11y_issue_states.impact when 'critical' then 0 when 'serious' then 1 when 'moderate' then 2 else 3 end")
            ->orderBy('a11y_issue_states.first_seen_at')
            ->orderBy('a11y_issue_states.path');
    }

    public function paginate(int $page = 1): LengthAwarePaginator
    {
        return $this->query()->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));
    }

    /** Every fingerprint matching the filter, for "apply to all". */
    public function fingerprints(): Collection
    {
        return $this->query()->pluck('a11y_issue_states.fingerprint');
    }

    /** @return array<string, int> */
    public function countsByStatus(): array
    {
        $counts = IssueState::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        $result = [];

        foreach (self::STATUSES as $status) {
            $result[$status] = (int) ($counts[$status] ?? 0);
        }

        return $result;
    }

    /**
     * The values the filter controls offer, from the data rather than typed,
     * so a filter never offers a choice that matches nothing.
     *
     * @return array<string, array<int, string>>
     */
    public static function options(): array
    {
        $states = IssueState::query();

        return [
            'sites' => (clone $states)->distinct()->orderBy('site')->pluck('site')->filter()->values()->all(),
            'assignees' => (clone $states)->whereNotNull('assigned_to')->distinct()->orderBy('assigned_to')->pluck('assigned_to')->values()->all(),
            'collections' => \Bpmore\A11yReport\Models\ScanPage::query()->distinct()->orderBy('collection')->pluck('collection')->filter()->values()->all(),
            'criteria' => \Bpmore\A11yReport\Models\Issue::query()->distinct()->orderBy('label')->pluck('label')->values()->all(),
        ];
    }

    /** @return array<string, string> only the filters that are set, for links */
    public function queryString(): array
    {
        return array_filter($this->filters, fn ($v, $k) => $v !== '' && ! ($k === 'status' && $v === 'active'), ARRAY_FILTER_USE_BOTH);
    }
}
