<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A generated conformance document: who made it, when, from which scan, and
 * where the files are.
 *
 * `remediation_policy` is the copy of the targets that were in force when the
 * document was filed, for the same reason the cover carries the mark it was
 * printed with: a report kept as evidence has to stay readable after the
 * settings have moved on.
 */
final class Report extends ReportModel
{
    protected $table = 'a11y_reports';

    protected $casts = [
        'generated_at' => 'datetime',
        'signed_at' => 'datetime',
        'remediation_policy' => 'array',
    ];

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class, 'scan_id');
    }
}
