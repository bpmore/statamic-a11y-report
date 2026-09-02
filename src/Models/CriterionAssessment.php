<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Models;

/**
 * A person's, or the engine's, judgement on one WCAG success criterion for one
 * site.
 *
 * The rule that matters is enforced where the merge happens, not here: a row
 * with `locked` true is never written by a scan. The schema's own guard is the
 * default, which is `not_evaluated`. A silent default of "supports" would be
 * the most damaging bug this product could ship.
 */
final class CriterionAssessment extends ReportModel
{
    public const SUPPORTS = 'supports';

    public const PARTIALLY_SUPPORTS = 'partially_supports';

    public const DOES_NOT_SUPPORT = 'does_not_support';

    public const NOT_APPLICABLE = 'not_applicable';

    public const NOT_EVALUATED = 'not_evaluated';

    protected $table = 'a11y_criteria_assessments';

    protected $casts = [
        'locked' => 'boolean',
        'assessed_at' => 'datetime',
    ];
}
