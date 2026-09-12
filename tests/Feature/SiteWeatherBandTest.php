<?php

declare(strict_types=1);

use Bpmore\A11yReport\Storage\ReportDatabase;
use Bpmore\A11yReport\Weather\AccessibilityContributor;
use Bpmore\SiteWeather\Contracts\WeatherContributor;
use Bpmore\SiteWeather\Contributors;
use Bpmore\SiteWeather\Forecast;
use Bpmore\SiteWeather\State;
use Statamic\Facades\Collection;
use Statamic\Facades\Utility;

/**
 * The band through Site Weather's real contract. Site Weather is a suggestion,
 * not a dependency, and this repository keeps no lock file, so it is only
 * present when a developer has installed it locally; without it these tests
 * skip and say so. The decision itself is tested in tests/Unit regardless.
 */
$siteWeatherInstalled = interface_exists(WeatherContributor::class);

beforeEach(function () {
    Collection::make('pages')->routes('/{slug}')->save();
});

it('tags the contributor for Site Weather to find', function () {
    $tagged = collect(app()->tagged('site-weather.contributors'));

    expect($tagged)->toHaveCount(1)
        ->and($tagged->first())->toBeInstanceOf(AccessibilityContributor::class)
        ->and($tagged->first()->key())->toBe('accessibility')
        ->and($tagged->first()->label())->toBe('Accessibility');
})->skip(! $siteWeatherInstalled, 'Site Weather is not installed');

it('reads unknown with the next step before the database is installed', function () {
    $reading = app(AccessibilityContributor::class)->reading();

    expect($reading->state)->toBe(State::Unknown)
        ->and($reading->headline)->toBe('Not set up yet: run php please statamic:a11y:report:install')
        ->and($reading->url)->toBe(Utility::find('a11y-report')?->url());
})->skip(! $siteWeatherInstalled, 'Site Weather is not installed');

it('reads unknown, not clear, after installing but before any scan', function () {
    app(ReportDatabase::class)->install();

    $reading = app(AccessibilityContributor::class)->reading();

    expect($reading->state)->toBe(State::Unknown)
        ->and($reading->headline)->toBe('No scan has run yet')
        ->and($reading->computedAt)->toBeNull();
})->skip(! $siteWeatherInstalled, 'Site Weather is not installed');

it('reaches the tile end to end from a real scan', function () {
    app(ReportDatabase::class)->install();
    page('one', '<img src="/a.jpg">');
    runScan();

    $forecast = Forecast::from(app(Contributors::class));

    expect($forecast->bands)->toHaveCount(1)
        ->and($forecast->bands[0]->key)->toBe('accessibility')
        ->and($forecast->overall())->not->toBe(State::Unknown)
        ->and($forecast->overall())->not->toBe(State::Clear)
        ->and($forecast->bands[0]->reading->headline)->toContain('open issue')
        ->and($forecast->bands[0]->reading->computedAt)->not->toBeNull()
        ->and($forecast->summary())->toStartWith('Overall: ');
})->skip(! $siteWeatherInstalled, 'Site Weather is not installed');
