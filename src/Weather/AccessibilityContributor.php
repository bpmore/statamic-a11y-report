<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Weather;

use Bpmore\A11yReport\Storage\ReportDatabase;
use Bpmore\A11yReport\Trends\Overview;
use Bpmore\SiteWeather\Contracts\WeatherContributor;
use Bpmore\SiteWeather\Reading;
use Bpmore\SiteWeather\State;
use Statamic\Facades\Utility;

/**
 * The Accessibility band on Site Weather's tile.
 *
 * Site Weather is an optional companion, not a dependency: this class is
 * tagged by name in the service provider and only loaded when Site Weather
 * resolves the tag, so the interface it implements need not exist on a site
 * without it - nor on a CI runner. The decision lives in AccessibilityWeather,
 * which is tested everywhere; this is the mapping onto Site Weather's types.
 *
 * It reads. The same stored numbers the dashboard widget draws, every site
 * together, and never runs a scan. Not installed, or never scanned, reads as
 * unknown - never as clear.
 */
final class AccessibilityContributor implements WeatherContributor
{
    public function __construct(private readonly ReportDatabase $database) {}

    public function key(): string
    {
        return 'accessibility';
    }

    public function label(): string
    {
        return 'Accessibility';
    }

    public function reading(): Reading
    {
        $url = Utility::find('a11y-report')?->url();

        if (! $this->database->isInstalled()) {
            return Reading::unknown('Not set up yet: run php please statamic:a11y:report:install', $url);
        }

        $overview = new Overview($this->database);
        $latest = $overview->latestComplete();

        if ($latest === null) {
            return Reading::unknown(
                $overview->latest() === null ? 'No scan has run yet' : 'No scan has completed yet',
                $url,
            );
        }

        $decision = AccessibilityWeather::decide(
            $overview->openByImpact(),
            (int) $latest->pages_scanned,
            array_column($overview->trend(30), 'value'),
        );

        return new Reading(State::from($decision['state']), $decision['headline'], $url, $latest->finished_at);
    }
}
