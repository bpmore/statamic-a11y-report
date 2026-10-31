<?php

declare(strict_types=1);

use Bpmore\A11yReport\Chrome\ChromeProtocolError;
use Bpmore\A11yReport\Engine\AxeEngine;
use Bpmore\A11yReport\Engine\Engines;
use Bpmore\A11yReport\Pdf\ChromePrinter;
use Bpmore\A11yReport\Settings;
use Statamic\Facades\Addon;
use Statamic\Facades\User;

function reportCloudBrowserFixture(): void
{
    config([
        'statamic-a11y-report.chrome.driver' => 'cloudflare',
        'statamic-a11y-report.chrome.account_id' => str_repeat('a', 32),
        'statamic-a11y-report.chrome.api_token' => 'fixture-private-token-123456',
        'statamic-a11y-report.chrome.binary' => '/nowhere/chrome',
        'statamic-a11y-report.engine' => 'axe',
    ]);
}

it('selects the real remote axe engine even without a local Chrome binary', function () {
    reportCloudBrowserFixture();
    expect(app(Engines::class)->configured())->toBeInstanceOf(AxeEngine::class);
    expect(app(ChromePrinter::class)->available())->toBeTrue();
});

it('refuses a half-configured remote browser instead of silently substituting PHP', function () {
    reportCloudBrowserFixture();
    config(['statamic-a11y-report.chrome.api_token' => '']);
    expect(fn () => app(Engines::class)->configured())->toThrow(ChromeProtocolError::class);
    expect(fn () => app(ChromePrinter::class))->toThrow(ChromeProtocolError::class);
});

it('keeps the browser key out of effective settings and the settings response', function () {
    reportCloudBrowserFixture();
    $settings = app(Settings::class)->effective();
    expect(array_key_exists('api_token', $settings['chrome']))->toBeFalse();
    $user = User::make()->email('cloud-settings@example.test')->makeSuper();
    $user->save();
    $url = Addon::get(Settings::PACKAGE)->settingsUrl();
    $this->actingAs($user)->get($url)->assertOk()->assertDontSee('fixture-private-token-123456', false);
});
