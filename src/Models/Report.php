<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A generated conformance document: who made it, when, from which scan, and
 * where the files are. The table exists now so the scan layer is designed
 * around it; nothing writes to it yet.
 */
final class Report extends ReportModel
{
    protected $table = 'a11y_reports';

    protected $casts = [
        'generated_at' => 'datetime',
        'signed_at' => 'datetime',
    ];

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class, 'scan_id');
    }
}
