<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Models;

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

    protected $table = 'a11y_issue_states';

    protected $primaryKey = 'fingerprint';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /**
     * The states a scan is allowed to move an issue out of when the problem
     * is no longer on the page. Anything else was a person's decision.
     */
    public static function closableByScan(): array
    {
        return [self::OPEN, self::IN_PROGRESS];
    }
}
