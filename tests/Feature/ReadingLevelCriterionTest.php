<?php

declare(strict_types=1);

use Bpmore\A11yReport\Document\ReportWriter;
use Bpmore\A11yReport\Document\Wcag;
use Bpmore\A11yReport\Models\CriterionAssessment;
use Bpmore\A11yReport\Models\Scan;
use Bpmore\A11yReport\Models\ScanPage;
use Bpmore\A11yReport\Readability\CriterionEvidence;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Statamic\Facades\Collection;
use Statamic\Facades\User;

/**
 * SC 3.1.5 Reading Level: Level AAA, reported apart from the Level AA
 * claim, with the scan's reading levels as evidence toward it and never as
 * a determination. The whole point of the row is what it refuses to say.
 */
const PLAIN_TEXT = '<p>A flu shot keeps you well. It is free. It takes five minutes. Ask us today.</p>';

const GRADUATE_TEXT = '<p>Immunization substantially reduces hospitalization. Epidemiological surveillance corroborates it. Immunocompromised populations benefit disproportionately.</p>';

beforeEach(function () {
    $this->withStandardFakeViews();
    test()->viewShouldReturnRaw('default', '<html lang="en"><body><main><h1>{{ title }}</h1>{{ body }}</main></body></html>');
    Collection::make('pages')->routes('/{slug}')->save();
    app(ReportDatabase::class)->install();
    tempStorage();

    $this->user = User::make()->email('assessor@example.test')->makeSuper();
    $this->user->save();
});

function flattened(string $html): string
{
    return (string) preg_replace('/\s+/', ' ', strip_tags($html));
}

function reportFor(Scan $scan): array
{
    $report = app(ReportWriter::class)->write($scan->fresh(), 'tester@example.test');
    $writer = app(ReportWriter::class);

    return [
        (string) file_get_contents($writer->absolutePath($report->html_path)),
        json_decode((string) file_get_contents($writer->absolutePath($report->json_path)), true),
    ];
}

it('reports 3.1.5 in a section of its own, after the conformance table and outside it', function () {
    page('plain', PLAIN_TEXT);
    page('dense', GRADUATE_TEXT);

    [$html, $json] = reportFor(runScan());

    // Never in the table, never in the claim's counts.
    expect($json['criteria'])->toHaveCount(55);
    expect(array_column($json['criteria'], 'number'))->not->toContain('3.1.5');
    expect($json['methods']['not_evaluated'])->not->toContain('3.1.5');
    expect($json['methods']['automated_criteria'])->not->toContain('3.1.5');

    // Its own key, one row, AAA, not evaluated, linked to the W3C's text.
    expect($json['beyond_claim']['level'])->toBe('AAA');
    expect($json['beyond_claim']['criteria'])->toHaveCount(1);
    $row = $json['beyond_claim']['criteria'][0];
    expect($row['number'])->toBe('3.1.5');
    expect($row['name'])->toBe('Reading Level');
    expect($row['level'])->toBe('AAA');
    expect($row['status'])->toBe(CriterionAssessment::NOT_EVALUATED);
    expect($row['automated'])->toBeFalse();
    expect($row['contradicted'])->toBeFalse();
    expect($row['url'])->toBe('https://www.w3.org/WAI/WCAG22/Understanding/reading-level.html');

    // The evidence: how many pages read above lower secondary level, which
    // ones, and what the criterion actually asks, which no scan can see.
    expect($row['evidence'])->toStartWith('Of the 2 pages graded, 1 reads above lower secondary level (Grade 10 and up, with proper names and titles set aside before counting); the median page reads at Grade');
    expect($row['evidence'])->toContain('Whether a simpler version or supplemental content is offered for those pages is what this criterion asks, and no automated check can see it. This is evidence toward the criterion, not a determination.');
    expect($row['reading']['graded'])->toBe(2);
    expect($row['reading']['above'])->toBe(1);
    expect($row['reading']['pages_above'])->toBe([['path' => '/dense', 'url' => 'http://localhost/dense', 'label' => 'Grade 17+']]);

    // In the document: its own section, after the table and before the
    // findings, and the limits statement says AAA is not claimed.
    $table = strpos($html, 'Conformance table');
    $beyond = strpos($html, 'Level AAA, reported apart from the claim');
    $findings = strpos($html, 'Findings summary');
    expect($beyond)->toBeGreaterThan($table)->toBeLessThan($findings);
    expect(flattened($html))->toContain('Level AAA is not claimed.');
    expect(flattened($html))->toContain('The page above the level: /dense (Grade 17+).');
    expect($html)->toContain('<a href="https://www.w3.org/WAI/WCAG22/Understanding/reading-level.html">3.1.5 Reading Level</a>');

    // Nothing about it is a failure, anywhere.
    expect($json['summary']['issues_total'])->toBe(0);
    expect(str_contains(strtolower(flattened($html)), 'fails 3.1.5'))->toBeFalse();
    expect(flattened($html))->toContain('a page that reads above the level named is not a failure of anything in this report');
});

it('counts a page as above the level only when its whole band is, and never a page that was not graded', function () {
    page('one', PLAIN_TEXT);
    $scan = runScan();

    // Rows written by hand, so the boundary is exact rather than a matter
    // of finding prose that grades to a decimal.
    $pageRow = fn (string $path, array $reading) => ScanPage::create([
        'scan_id' => $scan->id, 'entry_id' => $path, 'collection' => 'pages', 'site' => 'default',
        'url' => 'http://localhost'.$path, 'path' => $path, 'status' => ScanPage::SCANNED, 'readability' => $reading,
    ]);
    $graded = fn (float $grade, int $low, ?int $high) => ['status' => 'graded', 'grade' => $grade, 'low' => $low, 'high' => $high, 'label' => $high === null ? "Grade {$low}+" : "Grade {$low}–{$high}", 'comparison' => 'above', 'words' => 50, 'sentences' => 3, 'reason' => null];

    $pageRow('/straddles', $graded(9.6, 9, 10));   // 9 to 10 is not above
    $pageRow('/above', $graded(10.2, 10, 11));     // 10 to 11 is
    $pageRow('/open', $graded(19.0, 17, null));    // 17+ is
    $pageRow('/french', ['status' => 'refused', 'reason' => 'fr', 'words' => 0, 'sentences' => 0]);
    $scan->update(['readability' => array_merge($scan->readability, ['pages' => ['graded' => 4, 'above' => 3, 'on_target' => 0, 'below' => 1, 'refused' => 1, 'nothing' => 0, 'failed' => 0], 'median' => ['grade' => 9.9, 'low' => 9, 'high' => 10, 'label' => 'Grade 9–10', 'comparison' => 'above']])]);

    $evidence = CriterionEvidence::forScan($scan->fresh());

    expect($evidence['measured'])->toBeTrue();
    expect($evidence['above'])->toBe(2);
    expect(array_column($evidence['pages_above'], 'path'))->toBe(['/above', '/open']);
    expect($evidence['sentence'])->toStartWith('Of the 4 pages graded, 2 read above lower secondary level');
    expect($evidence['sentence'])->toContain('the median page reads at Grade 9–10');
});

it('says when nothing on the site reads above the level, and still calls it evidence', function () {
    page('plain', PLAIN_TEXT);

    [, $json] = reportFor(runScan());
    $row = $json['beyond_claim']['criteria'][0];

    expect($row['evidence'])->toContain('0 read above lower secondary level');
    expect($row['evidence'])->toContain('No page asks more of a reader than the criterion allows');
    expect($row['evidence'])->toContain('evidence toward the criterion, not a determination');
    expect($row['status'])->toBe(CriterionAssessment::NOT_EVALUATED);
    expect($row['reading']['pages_above'])->toBe([]);
});

it('says when the reading level was not measured, and when no page could be graded', function () {
    config()->set('statamic-a11y-report.readability.enabled', false);
    page('plain', PLAIN_TEXT);
    [, $json] = reportFor(runScan());
    expect($json['beyond_claim']['criteria'][0]['evidence'])->toBe('The reading level was not measured in this scan, so there is no evidence toward this criterion.');
    expect($json['beyond_claim']['criteria'][0]['reading']['measured'])->toBeFalse();

    config()->set('statamic-a11y-report.readability.enabled', true);
    page('plain', '<img src="/a.jpg" alt="Only a photo">');
    [, $json] = reportFor(runScan());
    expect($json['beyond_claim']['criteria'][0]['evidence'])->toStartWith('No page was graded for reading level in this scan, so there is no evidence toward this criterion.');
});

it('lets a person assess it from the worksheet, and prints what they wrote in its own section and nowhere else', function () {
    page('dense', GRADUATE_TEXT);
    $scan = runScan();

    $text = pageText($this->actingAs($this->user)->get(cp_route('utilities.a11y-report.criteria'))->assertOk()->getContent());
    expect($text)->toContain('Level AAA, reported apart from the claim');
    expect($text)->toContain('3.1.5 Reading Level');
    expect($text)->toContain('Level AAA, outside the claim');
    expect($text)->toContain('1 reads above lower secondary level');
    expect($text)->toContain('/dense');
    // The criterion column keeps a width of its own beside the evidence, so
    // the name is not wrapped onto three lines.
    expect($text)->toContain('<ui-table-column style="min-width: 12rem">Criterion</ui-table-column>');

    $this->actingAs($this->user)->post(cp_route('utilities.a11y-report.criteria.save'), [
        'criteria' => ['3.1.5' => ['status' => 'supports', 'method' => 'manual', 'remarks' => 'A plain-language summary tops every clinical page.', 'locked' => '1']],
    ])->assertRedirect();

    $stored = CriterionAssessment::where('criterion', '3.1.5')->first();
    expect($stored)->not->toBeNull();
    expect($stored->level)->toBe('AAA');
    expect($stored->locked)->toBeTrue();

    [$html, $json] = reportFor($scan);
    $row = $json['beyond_claim']['criteria'][0];
    expect($row['status'])->toBe(CriterionAssessment::SUPPORTS);
    expect($row['locked'])->toBeTrue();
    expect($row['remarks'])->toBe('A plain-language summary tops every clinical page.');
    expect($row['assessed_by'])->toBe('assessor@example.test');
    // The evidence still travels with the person's answer.
    expect($row['evidence'])->toContain('1 reads above lower secondary level');

    // And the claim is untouched: 55 rows, none of them 3.1.5, none of them
    // counted as assessed by a person because of this.
    expect($json['criteria'])->toHaveCount(55);
    expect($json['methods']['assessed_by_people'])->toBe([]);
    // One row for it in the whole document: the limits statement names it in
    // a sentence, and nothing else does.
    expect(substr_count($html, '>3.1.5 Reading Level</a>'))->toBe(1);
});

it('is the only AAA criterion the catalogue knows, and it is never one the table lists', function () {
    expect(array_map(fn ($c) => $c->number, Wcag::beyondClaim()))->toBe(['3.1.5']);
    expect(Wcag::find('3.1.5'))->toBeNull();
    expect(Wcag::urlFor('3.1.5', 'wcag22aa'))->toBeNull();
    expect(Wcag::assessable('3.1.5')?->level)->toBe('AAA');
    expect(Wcag::findBeyondClaim('1.1.1'))->toBeNull();

    foreach (['wcag21aa', 'wcag22aa'] as $standard) {
        expect(array_column(array_map(fn ($c) => (array) $c, Wcag::criteria($standard)), 'number'))->not->toContain('3.1.5');
    }

    // The PDF describes every link it may carry, this one included.
    expect(Wcag::linkDescriptions('wcag22aa')['https://www.w3.org/WAI/WCAG22/Understanding/reading-level.html'])
        ->toBe('Understanding success criterion 3.1.5 Reading Level, at w3.org');
});
