<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One problem on one page in one scan, as the engine reported it.
 *
 * Immutable once written. The thing that changes over time, whether anybody
 * has looked at it and what they decided, is `IssueState`, keyed on the same
 * fingerprint and on nothing that belongs to this scan.
 */
final class Issue extends ReportModel
{
    protected $table = 'a11y_issues';

    protected $casts = [
        'wcag_criteria' => 'array',
    ];

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class, 'scan_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(ScanPage::class, 'page_id');
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(IssueState::class, 'fingerprint', 'fingerprint');
    }
}
