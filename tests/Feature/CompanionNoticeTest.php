<?php

declare(strict_types=1);

use Bpmore\A11yReport\Storage\ReportDatabase;
use Bpmore\A11yReport\Support\Companion;
use Statamic\Addons\Manifest;
use Statamic\Facades\Collection;
use Statamic\Facades\User;

/**
 * The one sentence for a site that has Plain installed too, so that
 * nobody meeting both readings of the site takes one for a mistake.
 */
beforeEach(function () {
    $this->withStandardFakeViews();
    test()->viewShouldReturnRaw('default', PLAIN);
    Collection::make('pages')->routes('/{slug}')->save();
    app(ReportDatabase::class)->install();

    $this->user = User::make()->email('super@example.test')->makeSuper();
    $this->user->save();
});

/** Plain, as Statamic would list it once Composer has installed it. */
function installPlain(): void
{
    app(Manifest::class)->manifest[Companion::PLAIN] = [
        'id' => Companion::PLAIN,
        'slug' => 'statamic-plain',
        'version' => '1.0.0',
        'namespace' => 'Bpmore\\Plain',
        'autoload' => 'src/',
        'provider' => 'Bpmore\\Plain\\ServiceProvider',
    ];

    // The facade memoises the list it built from the manifest at boot.
    \Statamic\Facades\Addon::clearResolvedInstance();
}

it('says nothing about Plain when it is not installed', function () {
    expect(Companion::plainInstalled())->toBeFalse();
    expect(Companion::notice())->toBeNull();

    expect(utilityHtml())->not->toContain('Plain is installed');
});

it('says once, in one sentence, who owns which surface when Plain is installed', function () {
    installPlain();
    page('one', '<p>Fine.</p>');
    runScan();

    $html = utilityHtml();

    expect(substr_count($html, 'Plain is installed as well.'))->toBe(1);
    expect($html)->toContain('grade what an author writes, field by field, before it is published');
    expect($html)->toContain('This scan grades each page as served');
    expect($html)->toContain('two readings of the same site, not two scans of the same thing');

    assertVueTemplateIsWellFormed($html);
});
