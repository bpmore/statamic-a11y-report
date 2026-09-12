<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Weather;

use Bpmore\A11yReport\Engine\Finding;

/**
 * What the open issues say about the site, as weather.
 *
 * Framework-free and independent of Site Weather on purpose: the decision is
 * this addon's, it is the part worth testing hardest, and it must be testable
 * on a CI runner that has never heard of Site Weather. The contributor maps
 * the result onto Site Weather's types.
 *
 * The scale, decided here because this addon knows its own data:
 *
 *   clear     nothing open
 *   fair      open issues, none serious or critical
 *   overcast  a serious barrier somewhere, nothing critical
 *   rain      something critical is open - a page somebody cannot use
 *   storm     critical problems on the scale of a tenth of the pages scanned,
 *             or critical problems and the total is rising
 *
 * "Rising" is the spec's trend: worse than at the start of the window is a
 * storm because the site is not merely broken but getting more broken.
 */
final class AccessibilityWeather
{
    /** Critical open issues per page scanned at which rain becomes a storm. */
    public const STORM_CRITICAL_PER_PAGE = 0.10;

    /**
     * @param  array<string, int>  $openByImpact  impact => open issues, every impact present
     * @param  int  $pagesScanned  in the scan the counts rest on
     * @param  list<int>  $trendTotals  issue totals of completed scans in the window, oldest first
     * @return array{state: string, headline: string}
     */
    public static function decide(array $openByImpact, int $pagesScanned, array $trendTotals): array
    {
        $total = array_sum($openByImpact);
        $critical = $openByImpact[Finding::CRITICAL] ?? 0;
        $serious = $openByImpact[Finding::SERIOUS] ?? 0;
        $rising = self::isRising($trendTotals);

        if ($total === 0) {
            return ['state' => 'clear', 'headline' => sprintf('No open issues across %s', self::pages($pagesScanned))];
        }

        $state = match (true) {
            $critical > 0 && $pagesScanned > 0 && $critical / $pagesScanned >= self::STORM_CRITICAL_PER_PAGE => 'storm',
            $critical > 0 && $rising => 'storm',
            $critical > 0 => 'rain',
            $serious > 0 => 'overcast',
            default => 'fair',
        };

        $headline = sprintf('%s open %s, %s', number_format($total), $total === 1 ? 'issue' : 'issues',
            $critical === 0 ? 'none critical' : number_format($critical).' critical');

        if ($rising) {
            $headline .= ', and rising';
        }

        return ['state' => $state, 'headline' => $headline];
    }

    /** More open at the end of the window than at its start. One scan is no trend. */
    private static function isRising(array $trendTotals): bool
    {
        return count($trendTotals) >= 2 && end($trendTotals) > reset($trendTotals);
    }

    private static function pages(int $count): string
    {
        return number_format($count).' '.($count === 1 ? 'page' : 'pages');
    }
}
