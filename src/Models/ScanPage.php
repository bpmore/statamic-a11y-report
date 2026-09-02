<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One page in one scan.
 *
 * Four states, and the last two are different answers, not one answer with
 * two names. `error` is a page that exists and could not be read, which the
 * scan has to say so out loud. `skipped` is an entry that turned out to have no
 * page of its own, which is nothing to read rather than a failure to read it.
 */
final class ScanPage extends ReportModel
{
    public const PENDING = 'pending';

    public const SCANNED = 'scanned';

    public const ERROR = 'error';

    public const SKIPPED = 'skipped';

    protected $table = 'a11y_scan_pages';

    protected $casts = [
        'coverage' => 'array',
        'scanned_at' => 'datetime',
    ];

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class, 'scan_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class, 'page_id');
    }
}
