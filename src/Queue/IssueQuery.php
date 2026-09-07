<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Queue;

use Bpmore\A11yReport\Engine\Finding;
use Bpmore\A11yReport\Models\IssueState;
use Bpmore\A11yReport\Remediation\Policy;
use Bpmore\A11yReport\Settings;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\User;

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

    /** The three questions the policy adds to the queue, as one control. */
    public const DUE = ['overdue', 'expiring', 'expired'];

    /** How near an acceptance has to be to running out before the queue says so. */
    public const EXPIRING_WITHIN_DAYS = 30;

    /** The issue states table, qualified: `impact` is on the issues table too. */
    private const T = 'a11y_issue_states.';

    /** @var array<string, string> */
    public readonly array $filters;

    public readonly Policy $policy;

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
            // One page, by its site-relative path. Reached from the entry's
            // own panel rather than from a control on the queue.
            'path' => $pick('path'),
            // Past its target, or an acceptance that has run out or is about
            // to. All three are questions about a date rather than about a
            // status, which is why they are one control and not six.
            'due' => $pick('due', self::DUE),
        ];

        $this->policy = Policy::fromConfig(app(Settings::class)->block('report'));
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
                // The entry behind the page, so the queue can offer the screen
                // where the problem is fixed and not only the page where it
                // shows. Already on the joined table; it was simply not asked
                // for.
                'a11y_scan_pages.entry_id',
            ]);

        $f = $this->filters;

        match ($f['status']) {
            'all' => null,
            // An acceptance that has run out is work again, so the default
            // view shows it. Its stored status stays "won't fix": that was a
            // person's decision, and it ran out rather than being overruled.
            'active' => IssueState::openNow($q, self::T.'status'),
            default => $q->where(self::T.'status', $f['status']),
        };

        match ($f['due']) {
            'overdue' => $this->policy->overdue(IssueState::openNow($q, self::T.'status'), self::T),
            'expiring' => IssueState::accepted($q, self::T.'status')
                ->whereNotNull(self::T.'exception_expires_at')
                ->where(self::T.'exception_expires_at', '<=', now()->copy()->addDays(self::EXPIRING_WITHIN_DAYS)),
            'expired' => $q->where(self::T.'status', IssueState::WONT_FIX)
                ->whereNotNull(self::T.'exception_expires_at')
                ->where(self::T.'exception_expires_at', '<=', now()),
            default => null,
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

        if ($f['path'] !== '') {
            $q->where('a11y_issue_states.path', $f['path']);
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
     * What the policy says about the queue as a whole, counted on the states
     * alone so these numbers do not depend on the filter in the address bar.
     *
     * @return array<string, int>
     */
    public function policyCounts(): array
    {
        return [
            'overdue' => $this->policy->overdue(IssueState::openNow(IssueState::query()))->count(),
            'expiring' => IssueState::accepted(IssueState::query())
                ->whereNotNull('exception_expires_at')
                ->where('exception_expires_at', '<=', now()->copy()->addDays(self::EXPIRING_WITHIN_DAYS))
                ->count(),
            'expired' => IssueState::query()
                ->where('status', IssueState::WONT_FIX)
                ->whereNotNull('exception_expires_at')
                ->where('exception_expires_at', '<=', now())
                ->count(),
        ];
    }

    /**
     * Where to edit each of these issues' pages, by entry id.
     *
     * A person in the queue is working through a list of things to fix, and
     * fixing happens in the entry. The public page shows the problem; the
     * entry is where it goes away.
     *
     * Null where there is nothing to offer, and the view leaves the link out
     * rather than printing one that fails:
     *
     * - The entry has been deleted since the scan that found the issue. The
     *   issue is still real and still worth reading; the page it was on is
     *   gone.
     * - The person may read the queue and not edit that collection. A link
     *   that answers 403 is worse than no link, because it looks like the
     *   product is broken rather than like permission is missing.
     *
     * Resolved once per entry for the whole page of results, because a queue
     * page is fifty rows and many of them are the same page.
     *
     * @param  iterable<int, object>  $issues
     * @return array<string, string|null>
     */
    public static function editUrls(iterable $issues): array
    {
        $user = User::current();
        $urls = [];

        foreach ($issues as $issue) {
            $id = (string) ($issue->entry_id ?? '');

            if ($id === '' || array_key_exists($id, $urls)) {
                continue;
            }

            $entry = Entries::find($id);

            $urls[$id] = $entry !== null && $user?->can('edit', $entry)
                ? $entry->editUrl()
                : null;
        }

        return $urls;
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
