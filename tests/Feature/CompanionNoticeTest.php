<?php

declare(strict_types=1);

use Bpmore\A11yReport\Storage\ReportDatabase;
use Bpmore\A11yReport\Support\Companion;
use Statamic\Addons\Manifest;
use Statamic\Facades\Collection;
use Statamic\Facades\User;

/**
 * The one sentence for a site that has Copydesk installed too, so that
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

/** Copydesk, as Statamic would list it once Composer has installed it. */
function installCopydesk(): void
{
    app(Manifest::class)->manifest[Companion::COPYDESK] = [
        'id' => Companion::COPYDESK,
        'slug' => 'copydesk',
        'version' => '1.0.0',
        'namespace' => 'Bpmore\\Copydesk',
        'autoload' => 'src/',
        'provider' => 'Bpmore\\Copydesk\\ServiceProvider',
    ];

    // The facade memoises the list it built from the manifest at boot.
    \Statamic\Facades\Addon::clearResolvedInstance();
}

it('says nothing about Copydesk when it is not installed', function () {
    expect(Companion::copydeskInstalled())->toBeFalse();
    expect(Companion::notice())->toBeNull();

    expect(utilityHtml())->not->toContain('Copydesk is installed');
});

it('says once, in one sentence, who owns which surface when Copydesk is installed', function () {
    installCopydesk();
    page('one', '<p>Fine.</p>');
    runScan();

    $html = utilityHtml();

    expect(substr_count($html, 'Copydesk is installed as well.'))->toBe(1);
    expect($html)->toContain('grade what an author writes, field by field, before it is published');
    expect($html)->toContain('This scan grades each page as served');
    expect($html)->toContain('two readings of the same site, not two scans of the same thing');

    assertVueTemplateIsWellFormed($html);
});
