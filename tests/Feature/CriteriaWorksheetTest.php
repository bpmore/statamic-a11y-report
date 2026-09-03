<?php

declare(strict_types=1);

use Bpmore\A11yReport\Document\ReportWriter;
use Bpmore\A11yReport\Models\CriterionAssessment;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Statamic\Facades\Collection;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * The criteria worksheet: the human layer of the report, and the rules that
 * keep a person's work from vanishing.
 */
beforeEach(function () {
    $this->withStandardFakeViews();
    test()->viewShouldReturnRaw('default', PLAIN);
    Collection::make('pages')->routes('/{slug}')->save();
    app(ReportDatabase::class)->install();
    tempStorage();

    $this->user = User::make()->email('assessor@example.test')->makeSuper();
    $this->user->save();
});

function worksheet(array $query = [], $as = null): string
{
    return pageText(test()->actingAs($as ?? test()->user)->get(cp_route('utilities.a11y-report.criteria', $query))->assertOk()->getContent());
}

function saveWorksheet(array $criteria, ?string $site = null, $as = null)
{
    return test()->actingAs($as ?? test()->user)->post(cp_route('utilities.a11y-report.criteria.save'), array_filter(['criteria' => $criteria, 'site' => $site]));
}

it('lists every criterion with the automated evidence and the effective result from the latest scan', function () {
    page('one', '<img src="/a.jpg">');
    runScan();

    $text = worksheet();

    expect(substr_count($text, '<ui-table-row>'))->toBe(55);
    expect($text)->toContain('1.1.1 Non-text Content');
    expect($text)->toContain('Automated checks found 1 issue on 1 page (image-missing-alt).');
    expect($text)->toContain('Does not support');
    expect($text)->toContain('Not covered by automated checks.');
    expect($text)->toContain('found no failures across 1 page');
    expect($text)->toContain('Save the worksheet');
    expect(substr_count($text, 'text="Supports"'))->toBe(0);
});

it('saves only the rows that changed, with the assessor and the date', function () {
    page('one', '<p>Fine.</p>');
    runScan();

    saveWorksheet([
        '2.4.3' => ['status' => 'supports', 'method' => 'manual', 'remarks' => 'Tabbed through every template.', 'locked' => '1'],
        '1.4.3' => ['status' => 'partially_supports', 'method' => 'both', 'remarks' => 'Footer links fail.'],
        '1.1.1' => ['status' => ''],
        '9.9.9' => ['status' => 'supports'],
    ])->assertRedirect()->assertSessionHas('success', '2 criteria saved.');

    expect(CriterionAssessment::count())->toBe(2);

    $focus = CriterionAssessment::where('criterion', '2.4.3')->first();
    expect($focus->status)->toBe('supports');
    expect($focus->locked)->toBeTrue();
    expect($focus->level)->toBe('A');
    expect($focus->site)->toBeNull();
    expect($focus->assessed_by)->toBe('assessor@example.test');
    expect($focus->assessed_at)->not->toBeNull();

    $text = worksheet();
    expect($text)->toContain('Assessed by assessor@example.test');
    expect($text)->toContain('Tabbed through every template.');

    // Saving the same thing again writes nothing.
    $before = $focus->assessed_at;
    $this->travel(1)->days();
    saveWorksheet(['2.4.3' => ['status' => 'supports', 'method' => 'manual', 'remarks' => 'Tabbed through every template.', 'locked' => '1']])
        ->assertSessionHas('success', 'Nothing changed.');
    expect(CriterionAssessment::where('criterion', '2.4.3')->first()->assessed_at->equalTo($before))->toBeTrue();
});

it('clears a row when the status is handed back, and the automated result returns', function () {
    page('one', '<img src="/a.jpg">');
    runScan();

    saveWorksheet(['1.1.1' => ['status' => 'supports', 'locked' => '1', 'remarks' => 'Reviewed.']]);
    expect(worksheet())->toContain('text="Supports"');

    saveWorksheet(['1.1.1' => ['status' => '']])->assertSessionHas('success', '1 assessment cleared.');
    expect(CriterionAssessment::count())->toBe(0);
    expect(worksheet())->toContain('text="Does not support"');
});

it('is what the report prints', function () {
    page('one', '<img src="/a.jpg">');
    $scan = runScan();

    saveWorksheet(['1.1.1' => ['status' => 'supports', 'locked' => '1', 'remarks' => 'Every image checked by hand.']]);
    saveWorksheet(['2.4.3' => ['status' => 'partially_supports', 'remarks' => 'Modal traps focus.']]);

    $report = app(ReportWriter::class)->write($scan, 'x');
    $html = file_get_contents(app(ReportWriter::class)->absolutePath($report->html_path));

    expect($html)->toMatch('/1\.1\.1 Non-text Content<\/a><\/th>\s*<td>A<\/td>\s*<td class="status status-supports">Supports<\/td>\s*<td>Manual, locked/');
    expect($html)->toContain('Every image checked by hand.');
    expect($html)->toMatch('/2\.4\.3 Focus Order<\/a><\/th>\s*<td>A<\/td>\s*<td class="status status-partially_supports">Partially supports/');
    expect($html)->toContain('Assessed by assessor@example.test');
});

it('keeps a site\'s rows apart from the global default, and shows what a site inherits', function () {
    page('one', '<p>Fine.</p>');
    runScan();

    saveWorksheet(['3.1.1' => ['status' => 'supports', 'remarks' => 'Global: lang set in the layout.', 'locked' => '1']]);
    saveWorksheet(['3.1.1' => ['status' => 'partially_supports', 'remarks' => 'This site mixes languages.']], 'default');

    expect(CriterionAssessment::whereNull('site')->count())->toBe(1);
    expect(CriterionAssessment::where('site', 'default')->count())->toBe(1);

    expect(worksheet())->toContain('Global: lang set in the layout.');
    expect(worksheet(['site' => 'default']))->toContain('This site mixes languages.');
    expect(worksheet(['site' => 'default']))->not->toContain('Global: lang set in the layout.');

    // A criterion the site has no row for shows the inherited global one.
    saveWorksheet(['2.4.2' => ['status' => 'supports', 'locked' => '1']]);
    expect(worksheet(['site' => 'default']))->toContain('Inherited from the global default, assessed by assessor@example.test');
});

it('never lets a scan touch what a person wrote', function () {
    page('one', '<img src="/a.jpg">');
    runScan();
    saveWorksheet(['1.1.1' => ['status' => 'supports', 'locked' => '1', 'remarks' => 'Reviewed.']]);

    runScan();
    runScan();

    $row = CriterionAssessment::where('criterion', '1.1.1')->first();
    expect($row->status)->toBe('supports');
    expect($row->remarks)->toBe('Reviewed.');
    expect($row->assessed_by)->toBe('assessor@example.test');
});

it('shows the sheet but no controls to somebody who may not assess, and refuses their saves', function () {
    page('one', '<p>Fine.</p>');
    runScan();
    saveWorksheet(['2.4.3' => ['status' => 'supports', 'remarks' => 'Fine by keyboard.']]);

    Role::make('reader')->permissions(['access cp', 'access a11y-report utility'])->save();
    $reader = User::make()->email('reader@example.test')->assignRole('reader');
    $reader->save();

    $text = worksheet([], $reader);
    expect($text)->toContain('2.4.3 Focus Order');
    expect($text)->toContain('Fine by keyboard.');
    expect($text)->not->toContain('Save the worksheet');
    expect($text)->not->toContain('criteria[2.4.3][status]');

    saveWorksheet(['2.4.3' => ['status' => 'does_not_support']], null, $reader)->assertForbidden();
    expect(CriterionAssessment::first()->status)->toBe('supports');
});

it('renders markup Vue can compile with a label on every control, before and after a scan', function () {
    $fetch = function () {
        $response = test()->actingAs(test()->user)->get(cp_route('utilities.a11y-report.criteria'))->getContent();
        preg_match('/data-page="([^"]+)"/', $response, $m);

        return (string) json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true)['props']['html'];
    };

    assertVueTemplateIsWellFormed($fetch());

    page('one', '<img src="/a.jpg">');
    runScan();
    saveWorksheet(['1.1.1' => ['status' => 'supports', 'remarks' => 'A remark with {{ braces }} in it.']]);

    $html = $fetch();
    assertVueTemplateIsWellFormed($html);
    expect($html)->toContain('&#123;&#123; braces &#125;&#125;');

    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<html><body>'.html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8').'</body></html>');
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    expect($xpath->query('//select|//textarea')->length)->toBe(165);

    foreach ($xpath->query('//select|//textarea') as $control) {
        $id = $control->getAttribute('id');
        expect($xpath->query("//label[@for='{$id}']")->length)->toBe(1, "control {$id} has a label");
    }
});

it('links every row and the standard to the W3C text', function () {
    page('one', '<img src="/a.jpg">');
    runScan();

    $text = worksheet();

    expect($text)->toContain('<a href="https://www.w3.org/TR/WCAG22/" target="_blank" rel="noopener">WCAG 2.2 Level AA</a>');
    expect($text)->toContain('<a href="https://www.w3.org/WAI/WCAG22/Understanding/non-text-content.html" target="_blank" rel="noopener" title="What this criterion requires, at w3.org">1.1.1 Non-text Content</a>');
    expect(substr_count($text, 'https://www.w3.org/WAI/WCAG22/Understanding/'))->toBe(55);
});
