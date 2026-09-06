<?php

declare(strict_types=1);

use Bpmore\A11yReport\Document\ReportWriter;
use Bpmore\A11yReport\Models\Scan;
use Bpmore\A11yReport\Settings;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Statamic\Facades\Addon;
use Statamic\Facades\Collection;
use Statamic\Facades\User;

/**
 * The settings screen, which exists because of who ends up holding a site:
 * the person who writes the statement and signs the report, and cannot edit
 * a PHP file.
 */
beforeEach(function () {
    $this->withStandardFakeViews();
    test()->viewShouldReturnRaw('default', PLAIN);
    Collection::make('pages')->routes('/{slug}')->save();
    Collection::make('posts')->routes('/blog/{slug}')->save();
    app(ReportDatabase::class)->install();
    tempStorage();
});

it('offers a settings screen at all, and links to it from the utility', function () {
    $addon = Addon::get(Settings::PACKAGE);

    expect($addon->hasSettingsBlueprint())->toBeTrue();
    expect($addon->settingsUrl())->toContain('addons');

    $user = User::make()->email('super@example.test')->makeSuper();
    $user->save();
    $text = pageText($this->actingAs($user)->get(cp_route('utilities.index').'/a11y-report')->getContent());
    expect($text)->toContain('text="Settings"');
});

it('asks in plain words, with no handles or file paths', function () {
    $blueprint = Addon::get(Settings::PACKAGE)->settingsBlueprint();

    $text = collect($blueprint->fields()->all())
        ->flatMap(fn ($field) => [$field->display(), $field->instructions()])
        ->filter()
        ->implode(' ');

    expect($text)->toContain('Report against');

    foreach (['config/', 'blueprint', 'handle', '.env', 'yaml', '.php'] as $jargon) {
        expect(str_contains(strtolower($text), strtolower($jargon)))
            ->toBeFalse("the settings screen must not say '{$jargon}' to somebody who was handed a site");
    }

    expect(str_contains(strtolower($text), 'aaa'))->toBeTrue('the screen says why Level AAA is not offered');
});

it('maps every field on the screen to a setting, and every mapped setting to a field', function () {
    $handles = Addon::get(Settings::PACKAGE)->settingsBlueprint()->fields()->all()->keys()->sort()->values()->all();
    $mapped = collect(Settings::MAP)->keys()->sort()->values()->all();

    expect($handles)->toBe($mapped);
});

it('shows every setting as what it actually is before anybody saves', function () {
    // A screen that describes a different state from the one in force is
    // worse than no screen: it is wrong, and saving it makes the addon agree.
    $fields = Addon::get(Settings::PACKAGE)->settingsBlueprint()->fields()->all();

    expect($fields->count())->toBeGreaterThan(0);

    foreach ($fields as $handle => $field) {
        $inForce = config('statamic-a11y-report.'.Settings::MAP[$handle]);

        if ($field->defaultValue() === null) {
            // Empty on screen: the file had better be empty too, where
            // "every site" and "every collection" are spelled ['*'].
            expect(in_array($inForce, [null, [], ['*']], true))->toBeTrue("the screen shows {$handle} as empty while the addon is using ".json_encode($inForce));

            continue;
        }

        expect($field->defaultValue())->toBe($inForce, "the settings screen shows a different {$handle} from the one in force");
    }
});

it('leaves the config file in charge until somebody saves the screen', function () {
    config()->set('statamic-a11y-report.report.standard', 'wcag21aa');
    config()->set('statamic-a11y-report.report.evaluator.name', 'From the file');

    expect(effective()['report']['standard'])->toBe('wcag21aa');
    expect(effective()['report']['evaluator']['name'])->toBe('From the file');
});

it('lets the screen win once it has been saved, all the way into the documents', function () {
    config()->set('statamic-a11y-report.report.standard', 'wcag22aa');
    saveSettings([
        'standard' => 'wcag21aa',
        'evaluator_name' => 'Screen Person',
        'evaluator_organization' => 'Screen Org',
        'statement_email' => 'screen@example.test',
        'statement_commitment' => 'We said this on the screen.',
        'statement_template' => 'en301549',
        'remediation_plan' => 'Fix the footer first.',
    ]);

    page('one', '<img src="/a.jpg">');
    $report = app(ReportWriter::class)->write(runScan(), 'x');
    $html = file_get_contents(app(ReportWriter::class)->absolutePath($report->html_path));

    expect($report->standard)->toBe('wcag21aa');
    expect($html)->toContain('WCAG 2.1 Level AA');
    expect($html)->toContain('4.1.1 Parsing');
    expect($html)->toContain('Screen Person, Screen Org');
    expect($html)->toContain('Fix the footer first.');

    $statement = (string) preg_replace('/\s+/', ' ', $this->get('/accessibility')->assertOk()->getContent());
    expect($statement)->toContain('mailto:screen@example.test');
    expect($statement)->toContain('We said this on the screen.');
    expect($statement)->toContain('Enforcement procedure');
});

it('does not let an untouched field on the screen overrule the config file', function () {
    config()->set('statamic-a11y-report.statement.contact.email', 'file@example.test');
    config()->set('statamic-a11y-report.report.evaluator.organization', 'File Org');

    // Only the name is saved. The form's other fields exist too, empty.
    saveSettings(['evaluator_name' => 'Screen Person', 'evaluator_organization' => '', 'statement_email' => null]);

    $e = effective();
    expect($e['report']['evaluator']['name'])->toBe('Screen Person');
    expect($e['report']['evaluator']['organization'])->toBe('File Org');
    expect($e['statement']['contact']['email'])->toBe('file@example.test');
});

it('reads a chosen list of collections as that list, and an empty one as the file\'s answer', function () {
    page('one', '<p>Fine.</p>');
    page('post', '<p>Fine.</p>', collection: 'posts');

    // The file ships "every collection", so an empty screen scans everything.
    saveSettings(['scan_collections' => []]);
    expect(effective()['scan']['collections'])->toBe(['*']);
    expect(runScan()->pages_total)->toBe(2);

    // A developer narrowed the file: an empty screen is not saved at all by
    // Statamic's settings store, so the file's narrower set stands, which is
    // what the screen's instruction says.
    config()->set('statamic-a11y-report.scan.collections', ['pages']);
    saveSettings(['scan_collections' => []]);
    expect(effective()['scan']['collections'])->toBe(['pages']);
    expect(runScan()->pages_total)->toBe(1);

    // A chosen list wins over the file.
    saveSettings(['scan_collections' => ['posts'], 'scan_exclude_urls' => ['*/nothing']]);
    expect(effective()['scan']['collections'])->toBe(['posts']);
    expect(effective()['scan']['exclude_urls'])->toBe(['*/nothing']);
    expect(runScan()->pages_total)->toBe(1);

    $user = User::make()->email('super@example.test')->makeSuper();
    $user->save();
    $this->actingAs($user)->post(cp_route('utilities.a11y-report.run'));
    expect(Scan::latest('id')->first()->scope['collections'])->toBe(['posts']);
});

it('says out loud which settings the screen cannot reach', function () {
    // Developer's settings, kept off the screen on purpose: a wrong value
    // for any of them stops scans rather than changing a sentence. Pinned so
    // that a setting added to the file has to be placed, here or there.
    // `report.brand.container` is here because it decides which picker the
    // screen shows, and a field that changes the screen it sits on is a
    // puzzle rather than a setting.
    $reachable = array_values(Settings::MAP);

    $flatten = function (array $config, string $prefix = '') use (&$flatten): array {
        $keys = [];
        foreach ($config as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix.'.'.$k;
            if (is_array($v) && $v !== [] && ! array_is_list($v)) {
                $keys = array_merge($keys, $flatten($v, $key));
            } else {
                $keys[] = $key;
            }
        }

        return $keys;
    };

    $unreachable = array_values(array_diff($flatten((array) config('statamic-a11y-report')), $reachable));

    expect($unreachable)->toBe([
        'connection', 'engine', 'chrome.binary', 'chrome.timeout',
        'axe.best_practices', 'axe.settle_ms',
        'scan.concurrency', 'scan.schedule', 'scan.schedule_at', 'scan.stale_after_minutes',
        'retention.scans', 'report.appendix_limit', 'report.brand.container',
        'statement.route', 'statement.view', 'statement.layout', 'statement.sites',
        'ci.fail_above.critical', 'ci.fail_above.serious',
    ], 'a setting gained or lost a field on the screen: '.implode(', ', $unreachable));
});
