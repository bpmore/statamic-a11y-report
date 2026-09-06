<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Remediation;

use Bpmore\A11yReport\Engine\Finding;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The remediation policy: how long a problem of each impact may stay open, and
 * how long an accepted one may stay accepted.
 *
 * This is policy about what an organisation chases. It is never policy about
 * what the report claims: nothing here can move a criterion out of the
 * conformance table, soften a row in it, or take an issue out of the document.
 * A setting that could do any of those would make this addon a way to overstate
 * accessibility, which is the one thing it exists not to be.
 *
 * Due dates are computed and never stored. The policy is the promise in force,
 * so changing it moves every live date, which is the honest behaviour. A
 * report generated under an older policy keeps its own dates because it
 * carries its own copy of the policy, the same way the cover carries the mark
 * it was printed with.
 */
final class Policy
{
    /** Days to fix, by impact. Null, or anything under one day, means no target. */
    public const DEFAULT_TARGETS = ['critical' => 7, 'serious' => 30, 'moderate' => 90, 'minor' => null];

    /** The longest an acceptance may run before somebody looks at it again. */
    public const DEFAULT_EXCEPTION_DAYS = 180;

    /**
     * @param  array<string, int|null>  $targets  every impact, in the order `Finding` lists them
     */
    private function __construct(
        public readonly array $targets,
        public readonly int $exceptionDays,
    ) {}

    /**
     * @param  array<string, mixed>  $report  the addon's `report` config block, as in force
     */
    public static function fromConfig(array $report): self
    {
        $block = (array) ($report['remediation'] ?? []);
        $configured = (array) ($block['targets'] ?? []);

        $targets = [];

        foreach (Finding::IMPACTS as $impact) {
            // Present and null means no target, which is what the shipped file
            // says about minor. Only an absent key falls back to the default:
            // `??` would have read a deliberate null as "use 30 days", so a
            // developer switching a target off would have got it back.
            $targets[$impact] = self::days(array_key_exists($impact, $configured)
                ? $configured[$impact]
                : (self::DEFAULT_TARGETS[$impact] ?? null));
        }

        // The review period has no "none": every acceptance expires, so a
        // missing or nonsensical value falls back rather than switching that
        // off. There is no setting that makes an acceptance permanent.
        $days = self::days($block['exception_days'] ?? null);

        return new self($targets, $days ?? self::DEFAULT_EXCEPTION_DAYS);
    }

    /**
     * A whole number of days, or null for "no target".
     *
     * Zero is how the settings screen says no target: a number field that has
     * been cleared falls back to the config file, so there has to be a value
     * that means none.
     */
    private static function days(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $days = (int) $value;

        return $days >= 1 ? $days : null;
    }

    public function targetFor(string $impact): ?int
    {
        return $this->targets[$impact] ?? null;
    }

    public function hasTargets(): bool
    {
        return array_filter($this->targets, fn ($d) => $d !== null) !== [];
    }

    /** When a problem of this impact, first seen then, is due to be fixed. */
    public function dueAt(string $impact, Carbon|string|null $firstSeen): ?Carbon
    {
        $days = $this->targetFor($impact);

        if ($days === null || $firstSeen === null) {
            return null;
        }

        return Carbon::parse($firstSeen)->copy()->addDays($days);
    }

    /** How many days past its target a problem is, or null when it is not past it. */
    public function overdueDays(string $impact, Carbon|string|null $firstSeen): ?int
    {
        $due = $this->dueAt($impact, $firstSeen);

        return $due !== null && $due->isPast() ? (int) $due->diffInDays(now()) : null;
    }

    /** The furthest ahead an acceptance may be set to expire, from now. */
    public function latestExpiry(): Carbon
    {
        return now()->copy()->addDays($this->exceptionDays)->endOfDay();
    }

    /**
     * Narrow a query of issue states to those past their target.
     *
     * Built as one date per impact rather than as arithmetic in SQL, because
     * date arithmetic is spelled differently on every connection this addon
     * can be pointed at, and the answer must not depend on which one a site
     * chose.
     *
     * `$prefix` is the issue states table with its dot, wherever the query
     * joins: `impact` is on the issues table as well, and unqualified it is
     * ambiguous rather than wrong, which is an error at the database and not
     * a number that is quietly off.
     */
    public function overdue(Builder $query, string $prefix = ''): Builder
    {
        if (! $this->hasTargets()) {
            // No target means nothing can be past one. Without this the empty
            // group below would match every row, which would report a site
            // with no policy as entirely overdue.
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($prefix) {
            foreach ($this->targets as $impact => $days) {
                if ($days === null) {
                    continue;
                }

                $q->orWhere(fn (Builder $q) => $q
                    ->where($prefix.'impact', $impact)
                    ->where($prefix.'first_seen_at', '<', now()->copy()->subDays($days)));
            }
        });
    }

    /**
     * The policy as it is stamped into a report, so a document filed today is
     * still readable when the promise has changed.
     *
     * @return array{targets: array<string, int|null>, exception_days: int}
     */
    public function toArray(): array
    {
        return ['targets' => $this->targets, 'exception_days' => $this->exceptionDays];
    }
}
