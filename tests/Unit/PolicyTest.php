<?php

declare(strict_types=1);

use Bpmore\A11yReport\Remediation\Policy;
use Illuminate\Support\Carbon;

/**
 * The policy's arithmetic, with no framework under it: what a target means,
 * what "no target" means, and where a due date lands.
 */
function remediationPolicy(array $remediation = []): Policy
{
    return Policy::fromConfig(['remediation' => $remediation]);
}

it('falls back to the shipped targets when the config says nothing', function () {
    $p = remediationPolicy();

    expect($p->targetFor('critical'))->toBe(7);
    expect($p->targetFor('serious'))->toBe(30);
    expect($p->targetFor('moderate'))->toBe(90);
    expect($p->targetFor('minor'))->toBeNull();
    expect($p->exceptionDays)->toBe(Policy::DEFAULT_EXCEPTION_DAYS);
});

it('reads zero, null and nonsense as no target rather than as a target of nothing', function () {
    $p = remediationPolicy(['targets' => ['critical' => 0, 'serious' => null, 'moderate' => 'soon', 'minor' => -3]]);

    foreach (['critical', 'serious', 'moderate', 'minor'] as $impact) {
        expect($p->targetFor($impact))->toBeNull("{$impact} has no target");
    }

    expect($p->hasTargets())->toBeFalse();
    // A target of zero days would make everything overdue the moment it was
    // found, which is not a policy anybody wrote down on purpose.
    expect($p->dueAt('critical', now()))->toBeNull();
});

it('knows an impact with no target has no due date and is never late', function () {
    $p = remediationPolicy(['targets' => ['critical' => 7, 'minor' => 0]]);

    expect($p->dueAt('minor', now()->subYears(3)))->toBeNull();
    expect($p->overdueDays('minor', now()->subYears(3)))->toBeNull();
});

it('counts a due date forward from when the problem was first seen', function () {
    Carbon::setTestNow('2026-03-01 12:00:00');

    $p = remediationPolicy(['targets' => ['critical' => 7]]);

    expect($p->dueAt('critical', Carbon::parse('2026-02-25 09:00:00'))->toDateString())->toBe('2026-03-04');
    expect($p->overdueDays('critical', Carbon::parse('2026-02-21 09:00:00')))->toBe(1);
    // Inside the target is not late, and "not late" is null rather than zero:
    // zero days late and no target at all must not read the same.
    expect($p->overdueDays('critical', Carbon::parse('2026-02-25 09:00:00')))->toBeNull();

    Carbon::setTestNow();
});

it('will not let an acceptance be set further ahead than the review period', function () {
    Carbon::setTestNow('2026-03-01 12:00:00');

    expect(remediationPolicy(['exception_days' => 30])->latestExpiry()->toDateString())->toBe('2026-03-31');
    expect(remediationPolicy()->latestExpiry()->toDateString())->toBe('2026-08-28');

    Carbon::setTestNow();
});

it('carries the whole promise into the copy a report keeps', function () {
    $p = remediationPolicy(['targets' => ['critical' => 3, 'serious' => 14], 'exception_days' => 60]);

    expect($p->toArray())->toBe([
        'targets' => ['critical' => 3, 'serious' => 14, 'moderate' => 90, 'minor' => null],
        'exception_days' => 60,
    ]);
});
