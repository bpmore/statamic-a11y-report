<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Readability;

use Bpmore\ReadabilityCore\Formula\Band;
use Bpmore\ReadabilityCore\Formula\Comparison;
use Bpmore\ReadabilityCore\Formula\Counts;
use Bpmore\ReadabilityCore\Formula\Grades;

/**
 * What one page reads at, as the scan row keeps it.
 *
 * Four answers, and the last three are different answers rather than one
 * answer with three names, for the reason a page's own status has four.
 * `refused` is a page in a language the formulas were not calibrated on,
 * which is not the page's fault and not a grade. `nothing` is a page with
 * no prose left once names, quotations, code and the site's furniture
 * are set aside: a gallery, a form, a listing. `failed` is the engine
 * throwing, which is this addon's problem and is written down rather than
 * counted as a page that read fine.
 *
 * A band, never a decimal, is what a person is shown. The decimal is kept
 * for the median across pages and for ordering, and for nothing else.
 */
final class PageReading
{
    public const GRADED = 'graded';

    public const REFUSED = 'refused';

    public const NOTHING = 'nothing';

    public const FAILED = 'failed';

    private function __construct(
        public readonly string $status,
        public readonly ?float $grade,
        public readonly ?Band $band,
        public readonly ?Comparison $comparison,
        public readonly int $words,
        public readonly int $sentences,
        public readonly ?string $reason,
    ) {}

    public static function graded(Grades $grades, Comparison $comparison, Counts $counts): self
    {
        return new self(self::GRADED, round($grades->consensus(), 1), $grades->band(), $comparison, $counts->words, $counts->sentences, null);
    }

    public static function refused(string $reason): self
    {
        return new self(self::REFUSED, null, null, null, 0, 0, $reason);
    }

    public static function nothing(): self
    {
        return new self(self::NOTHING, null, null, null, 0, 0, 'Nothing on the page was left to grade once names, quotations, code, references and the site\'s own furniture were set aside.');
    }

    public static function failed(string $reason): self
    {
        return new self(self::FAILED, null, null, null, 0, 0, $reason);
    }

    public function isGraded(): bool
    {
        return $this->status === self::GRADED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'grade' => $this->grade,
            'low' => $this->band?->low,
            'high' => $this->band?->high,
            'label' => $this->band?->label(),
            'comparison' => $this->comparison?->value,
            'words' => $this->words,
            'sentences' => $this->sentences,
            'reason' => $this->reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $status = (string) ($row['status'] ?? self::FAILED);
        $low = $row['low'] ?? null;

        return new self(
            $status,
            isset($row['grade']) ? (float) $row['grade'] : null,
            $low === null ? null : new Band((int) $low, isset($row['high']) ? (int) $row['high'] : null),
            isset($row['comparison']) ? Comparison::tryFrom((string) $row['comparison']) : null,
            (int) ($row['words'] ?? 0),
            (int) ($row['sentences'] ?? 0),
            isset($row['reason']) ? (string) $row['reason'] : null,
        );
    }
}
