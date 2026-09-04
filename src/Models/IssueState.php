<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * What has been decided about a problem, across every scan that saw it.
 *
 * The only table keyed on the fingerprint alone. A scan writes `first_seen_at`,
 * `last_seen_at`, and the move from open to fixed when a page it read no longer
 * carries the problem. A person writes everything else, and a scan never
 * touches `wont_fix` or `false_positive`: those are decisions, and the
 * scanner's job is to report, not to overrule.
 */
final class IssueState extends ReportModel
{
    public const OPEN = 'open';

    public const IN_PROGRESS = 'in_progress';

    public const FIXED = 'fixed';

    public const WONT_FIX = 'wont_fix';

    public const FALSE_POSITIVE = 'false_positive';

    /**
     * The page the problem was on is no longer served: unpublished, deleted,
     * or moved to another address. Not "fixed", because nothing was fixed,
     * and set only by a scan that enumerated every page the problem's page
     * could have been among and did not meet it. If the page comes back
     * with the problem, the issue reopens.
     */
    public const PAGE_REMOVED = 'page_removed';

    /** The columns an acceptance is recorded in, and which are cleared with it. */
    public const EXCEPTION_COLUMNS = ['exception_reason', 'exception_by', 'exception_at', 'exception_expires_at'];

    protected $table = 'a11y_issue_states';

    protected $primaryKey = 'fingerprint';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
        'exception_at' => 'datetime',
        'exception_expires_at' => 'datetime',
    ];

    /**
     * The states a scan is allowed to move an issue out of when the problem
     * is no longer on the page. Anything else was a person's decision.
     */
    public static function closableByScan(): array
    {
        return [self::OPEN, self::IN_PROGRESS];
    }

    /** The states a scan reopens when it finds the problem again. */
    public static function reopenableByScan(): array
    {
        return [self::FIXED, self::PAGE_REMOVED];
    }

    /**
     * Every problem that counts as outstanding right now: the ones nobody has
     * closed, and the ones whose acceptance has run out.
     *
     * The one definition of open, because there were three, in the report, the
     * queue and the overview, and three copies of this answer disagree with
     * each other the first time one of them changes.
     *
     * An expired acceptance is counted here, and its stored status is left
     * alone. No job flips the row: the decision was "accepted until this
     * date", and past the date it has run out on its own terms. Counting it
     * as open honours what the person wrote rather than overruling it, which
     * is the same reason a scan never reopens a `wont_fix` it still finds.
     *
     * @param  string  $column  qualified with a table name where the query joins
     */
    public static function openNow(Builder $query, string $column = 'status'): Builder
    {
        $prefix = str_contains($column, '.') ? substr($column, 0, strrpos($column, '.') + 1) : '';

        return $query->where(fn (Builder $q) => $q
            ->whereIn($column, [self::OPEN, self::IN_PROGRESS])
            ->orWhere(fn (Builder $q) => $q
                ->where($column, self::WONT_FIX)
                ->whereNotNull($prefix.'exception_expires_at')
                ->where($prefix.'exception_expires_at', '<=', now())));
    }

    /** Accepted, and still within the date it was accepted until. */
    public static function accepted(Builder $query, string $column = 'status'): Builder
    {
        $prefix = str_contains($column, '.') ? substr($column, 0, strrpos($column, '.') + 1) : '';

        return $query->where($column, self::WONT_FIX)
            ->where(fn (Builder $q) => $q
                ->whereNull($prefix.'exception_expires_at')
                ->orWhere($prefix.'exception_expires_at', '>', now()));
    }

    /** Whether this row's acceptance has run out. */
    public function acceptanceHasExpired(): bool
    {
        return $this->status === self::WONT_FIX
            && $this->exception_expires_at !== null
            && $this->exception_expires_at->isPast();
    }
}
