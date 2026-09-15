<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Readability;

use Bpmore\ReadabilityCore\Exclusion\Code;
use Bpmore\ReadabilityCore\Exclusion\Pipeline;
use Bpmore\ReadabilityCore\Extraction\PageExtractor;
use Bpmore\ReadabilityCore\Formula\Band;
use Bpmore\ReadabilityCore\Formula\Grades;
use Bpmore\ReadabilityCore\Formula\Tally;
use Bpmore\ReadabilityCore\Formula\Target;
use Bpmore\ReadabilityCore\Locale\LocaleGuard;
use Bpmore\ReadabilityCore\Text\EnglishSegmenter;
use Bpmore\ReadabilityCore\Text\EnglishSyllableCounter;
use Composer\InstalledVersions;
use Throwable;

/**
 * The reading level of a page, as a dimension of a scan.
 *
 * The engine is `bpmore/readability-core`, the one Plain grades a page
 * with on the publish form, so the number an author saw and the number
 * in this report come from one code path. What it reads is the page as
 * served, and of that the main content: the navigation, banner, footer
 * and sidebar are the site's furniture and a reader does not read them as
 * prose. Then the same exclusions Plain applies before grading, names
 * among them, because SC 3.1.5 sets names and titles aside and so does
 * this.
 *
 * Built from the config when a scan is created and written onto the scan
 * row, then rebuilt from that row for every page: the target a page was
 * measured against is the one in force when its scan began, and never the
 * one in the config file when a worker happened to pick the page up. The
 * same rule as the engine on a scan and the mark on a report's cover.
 *
 * English only, and the row says so. Every formula here was calibrated
 * on English, and a grade for a French page is a confident number that
 * means nothing.
 */
final class Readability
{
    public const PACKAGE = 'bpmore/readability-core';

    /** The class a site puts on markup it does not want graded, the same one Plain reads. */
    private const IGNORED_CLASS = 'no-readability';

    public readonly Target $target;

    public readonly LocaleGuard $guard;

    /**
     * @param  list<string>  $locales
     */
    private function __construct(
        public readonly bool $enabled,
        public readonly int $targetGrade,
        public readonly int $targetTolerance,
        public readonly array $locales,
        public readonly string $engineVersion,
    ) {
        $this->target = Target::grade($targetGrade, $targetTolerance);
        $this->guard = new LocaleGuard($locales);
    }

    /**
     * @param  array<string, mixed>  $config  the `readability` block
     */
    public static function fromConfig(array $config): self
    {
        $target = (array) ($config['target'] ?? []);

        return new self(
            (bool) ($config['enabled'] ?? true),
            (int) ($target['grade'] ?? 8),
            (int) ($target['tolerance'] ?? 1),
            self::locales($config),
            self::installedVersion(),
        );
    }

    /**
     * From what a scan row recorded when it was created, so a page is
     * measured the way its scan said it would be.
     *
     * @param  array<string, mixed>|null  $record
     */
    public static function fromRecord(?array $record): self
    {
        $record ??= [];
        $target = (array) ($record['target'] ?? []);

        return new self(
            (bool) ($record['enabled'] ?? false),
            (int) ($target['grade'] ?? 8),
            (int) ($target['tolerance'] ?? 1),
            self::locales($record),
            (string) ($record['engine_version'] ?? 'unknown'),
        );
    }

    /**
     * What the scan row keeps: whether the dimension ran, with what, and
     * against which target. A disabled dimension records that it was
     * disabled, so an old scan with no record and a scan that chose not to
     * measure never look the same.
     *
     * @return array<string, mixed>
     */
    public function toRecord(): array
    {
        if (! $this->enabled) {
            return ['enabled' => false];
        }

        $band = $this->target->band;

        return [
            'enabled' => true,
            'engine' => self::PACKAGE,
            'engine_version' => $this->engineVersion,
            'locales' => $this->locales,
            'target' => [
                'grade' => $this->targetGrade,
                'tolerance' => $this->targetTolerance,
                'low' => $band->low,
                'high' => $band->high,
                'label' => $band->label(),
            ],
        ];
    }

    /**
     * Grade one page as served. Never throws: a reading level that cannot
     * be worked out is a reading that says so, and the page's own scan is
     * not touched by it.
     */
    public function grade(string $html, string $locale): PageReading
    {
        $decision = $this->guard->check($locale);

        if (! $decision->allowed) {
            return PageReading::refused((string) $decision->message());
        }

        try {
            $document = (new PageExtractor(Code::INLINE_TAGS, [self::IGNORED_CLASS]))->extract($html);
            $document = Pipeline::fromConfig(['html_classes' => [self::IGNORED_CLASS]])->apply($document);
            $counts = (new Tally(new EnglishSegmenter, new EnglishSyllableCounter))->of($document);
            $grades = Grades::of($counts);
        } catch (Throwable $e) {
            return PageReading::failed($e::class.': '.$e->getMessage());
        }

        if ($grades === null) {
            return PageReading::nothing();
        }

        return PageReading::graded($grades, $this->target->compare($grades->band()), $counts);
    }

    /**
     * The scan's readings rolled up: how many pages landed where against
     * the target, and the median page as a band.
     *
     * A median and not a mean, for the reason the engine takes the median
     * of its four formulas: one unreadable legal page should not move the
     * site's number. Counted over graded pages only; a page in the wrong
     * language or with nothing to grade is counted as such and says so.
     *
     * @param  iterable<PageReading>  $readings
     * @return array<string, mixed>
     */
    public function summary(iterable $readings): array
    {
        $pages = ['graded' => 0, 'above' => 0, 'on_target' => 0, 'below' => 0, 'refused' => 0, 'nothing' => 0, 'failed' => 0];
        $grades = [];

        foreach ($readings as $reading) {
            if (! $reading->isGraded()) {
                // A status this version does not know is a failure to read,
                // never a silent extra column.
                $pages[array_key_exists($reading->status, $pages) ? $reading->status : PageReading::FAILED]++;

                continue;
            }

            $pages['graded']++;
            $pages[$reading->comparison?->value ?? 'on_target']++;
            $grades[] = (float) $reading->grade;
        }

        $median = null;

        if ($grades !== []) {
            sort($grades);
            $n = count($grades);
            $grade = $n % 2 === 1 ? $grades[intdiv($n, 2)] : ($grades[$n / 2 - 1] + $grades[$n / 2]) / 2;
            $band = Band::of($grade);

            $median = [
                'grade' => round($grade, 1),
                'low' => $band->low,
                'high' => $band->high,
                'label' => $band->label(),
                'comparison' => $this->target->compare($band)->value,
            ];
        }

        return ['pages' => $pages, 'median' => $median];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<string>
     */
    private static function locales(array $source): array
    {
        return array_values(array_filter((array) ($source['locales'] ?? ['en']), 'is_string')) ?: ['en'];
    }

    private static function installedVersion(): string
    {
        try {
            return InstalledVersions::isInstalled(self::PACKAGE)
                ? (string) InstalledVersions::getPrettyVersion(self::PACKAGE)
                : 'unknown';
        } catch (Throwable) {
            return 'unknown';
        }
    }
}
