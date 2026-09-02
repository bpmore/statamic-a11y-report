<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One pass over a set of pages.
 *
 * The status is the batch's status as this addon understands it, kept here
 * rather than read off the batch table, because the batch row is the queue's
 * and can be pruned; this row is the record.
 */
final class Scan extends ReportModel
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const COMPLETE = 'complete';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_SCHEDULED = 'scheduled';

    public const TRIGGER_CI = 'ci';

    protected $table = 'a11y_scans';

    protected $casts = [
        'scope' => 'array',
        'issues_by_impact' => 'array',
        'diff' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function pages(): HasMany
    {
        return $this->hasMany(ScanPage::class, 'scan_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class, 'scan_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::COMPLETE, self::FAILED, self::CANCELLED], true);
    }
}
