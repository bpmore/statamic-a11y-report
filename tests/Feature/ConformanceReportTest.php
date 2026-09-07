<?php

declare(strict_types=1);

use Bpmore\A11yReport\Document\ReportWriter;
use Bpmore\A11yReport\Models\CriterionAssessment;
use Bpmore\A11yReport\Models\IssueState;
use Bpmore\A11yReport\Models\Report;
use Bpmore\A11yReport\Models\Scan;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Statamic\Facades\Collection;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * The conformance document. Most of these are about what it says and what
 * it refuses to say, because the document is the product and its claims are
 * what somebody will be held to.
 */
beforeEach(function () {
    $this->withStandardFakeViews();
    test()->viewShouldReturnRaw('default', PLAIN);
    Collection::make('pages')->routes('/{slug}')->save();
    app(ReportDatabase::class)->install();

    $this->user = User::make()->email('super@example.test')->makeSuper();
    $this->user->save();

    // Every report from a temporary storage path, so nothing lands in the
    // harness's own storage directory.
    $this->storage = sys_get_temp_dir().'/a11y-report-'.uniqid();
    foreach (['framework/cache', 'framework/views', 'framework/sessions'] as $dir) {
        mkdir($this->storage.'/'.$dir, 0755, true);
    }
    app()->useStoragePath($this->storage);
});

afterEach(function () {
    foreach (glob($this->storage.'/a11y-report/reports/*') ?: [] as $f) {
        unlink($f);
    }
});

function scannedSite(): Scan
{
    page('one', '<img src="/a.jpg">');
    page('two', '<h3>Skipped</h3><a href="#">Somewhere</a>');
    page('three', '<p>Fine.</p>');

    return runScan();
}

function document(?Scan $scan = null, ?string $by = 'tester@example.test'): array
{
    $report = app(ReportWriter::class)->write($scan ?? scannedSite(), $by);

    return [$report, file_get_contents(app(ReportWriter::class)->absolutePath($report->html_path))];
}

function flat(string $html): string
{
    return (string) preg_replace('/\s+/', ' ', strip_tags($html));
}

it('records who generated it and from which scan, and writes both files', function () {
    $scan = scannedSite();
    [$report, $html] = document($scan);

    expect($report->generated_by)->toBe('tester@example.test');
    expect($report->scan_id)->toBe($scan->id);
    expect($report->generated_at)->not->toBeNull();
    expect($report->standard)->toBe('wcag22aa');
    expect(is_file(app(ReportWriter::class)->absolutePath($report->html_path)))->toBeTrue();
    expect(is_file(app(ReportWriter::class)->absolutePath($report->json_path)))->toBeTrue();
    expect($report->coverage_note)->toContain('of 55 criteria');

    expect($html)->toContain('tester@example.test');
    expect($html)->toContain($scan->uuid);
    expect($html)->toContain($report->uuid);
});

it('has the shape a reader expects, in that order', function () {
    [, $html] = document();

    $order = ['Accessibility Conformance Report', 'Evaluation methods', 'Scope and limits of this report', 'Conformance table', 'Findings summary', 'Open issues', 'Remediation'];
    $positions = array_map(fn ($h) => strpos($html, $h), $order);

    foreach ($positions as $i => $p) {
        expect($p)->not->toBeFalse("the document has a section called {$order[$i]}");
        if ($i > 0) {
            expect($p)->toBeGreaterThan($positions[$i - 1], "{$order[$i]} comes after {$order[$i - 1]}");
        }
    }
});

it('marks a criterion the scan failed as does not support and everything else as not evaluated', function () {
    [$report, $html] = document();

    expect($html)->toMatch('/1\.1\.1 Non-text Content<\/a><\/th>\s*<td>A<\/td>\s*<td class="status status-does_not_support">Does not support/');
    expect($html)->toMatch('/2\.4\.4 Link Purpose \(In Context\)<\/a><\/th>\s*<td>A<\/td>\s*<td class="status status-not_evaluated">Not evaluated/');
    expect($html)->toMatch('/1\.4\.3 Contrast \(Minimum\)<\/a><\/th>\s*<td>AA<\/td>\s*<td class="status status-not_evaluated">Not evaluated/');
    expect(substr_count($html, 'class="status status-supports"'))->toBe(0);
    expect(substr_count($html, '<th scope="row">'))->toBeGreaterThanOrEqual(55);
});

it('lists a house rule apart from WCAG so it cannot be read as a criterion', function () {
    [, $html] = document();

    expect($html)->toContain('Findings outside WCAG');
    expect($html)->toContain('Heading structure');
    expect(str_contains(flat($html), '1.3.1 Info and Relationships A Does not support'))->toBeFalse('the house rule is not attributed to 1.3.1');
});

it('carries the limits statement, the automated list, and the not evaluated list, and no configuration removes them', function () {
    config()->set('statamic-a11y-report.report', ['limits' => false, 'show_limits' => false, 'scope_and_limits' => null, 'evaluator' => []]);

    [, $html] = document();
    $text = flat($html);

    expect($text)->toContain('This is a self-assessment.');
    expect($text)->toContain('not a certification and not a third-party audit');
    expect($text)->toContain('Automated testing cannot determine conformance for most success criteria.');
    expect($text)->toContain('What was evaluated automatically.');
    expect($text)->toContain('1.1.1, 1.2.1, 1.2.2, 2.4.4, 2.5.8, 4.1.2');
    expect($text)->toContain('What was not evaluated.');
    expect($text)->toContain('"Not evaluated" means exactly that. It is not a pass.');
    expect($text)->toContain('has not been proven accessible');
});

it('never says certified or compliant, and mentions certification only to deny it', function () {
    [, $html] = document();
    $lower = strtolower(flat($html));

    expect(str_contains($lower, 'certified'))->toBeFalse();
    expect(str_contains($lower, 'compliant'))->toBeFalse();
    expect(substr_count($lower, 'certif'))->toBe(substr_count($lower, 'not a certification'));
    expect(substr_count($lower, 'not a certification'))->toBeGreaterThan(0);
});

it('lets a locked manual assessment win and shows the automated evidence beside it', function () {
    $scan = scannedSite();

    CriterionAssessment::create([
        'site' => 'default', 'criterion' => '1.1.1', 'level' => 'A',
        'status' => CriterionAssessment::SUPPORTS, 'method' => 'manual', 'locked' => true,
        'remarks' => 'Every image reviewed by hand on 2 September.', 'assessed_by' => 'Reviewer', 'assessed_at' => now(),
    ]);
    CriterionAssessment::create([
        'site' => null, 'criterion' => '2.4.3', 'level' => 'A',
        'status' => CriterionAssessment::PARTIALLY_SUPPORTS, 'method' => 'manual', 'locked' => true, 'remarks' => 'Global default note.',
    ]);

    // A scan of the default site: its own rows beat the global ones.
    $sited = runScan(sites: ['default']);
    [$report, $html] = document($sited);

    expect($html)->toMatch('/1\.1\.1 Non-text Content<\/a><\/th>\s*<td>A<\/td>\s*<td class="status status-supports">Supports<\/td>\s*<td>Manual, locked/');
    expect($html)->toContain('Every image reviewed by hand on 2 September.');
    expect($html)->toContain('Automated checks found 1 issue on 1 page (image-missing-alt).');
    expect($html)->toContain('Assessed by Reviewer');
    expect($html)->toMatch('/2\.4\.3 Focus Order<\/a><\/th>\s*<td>A<\/td>\s*<td class="status status-partially_supports">Partially supports/');
    expect($report->coverage_note)->toContain("2 carry a person's assessment");

    // And a scan is not allowed to write over either row.
    runScan(sites: ['default']);
    expect(CriterionAssessment::where('criterion', '1.1.1')->first()->status)->toBe(CriterionAssessment::SUPPORTS);
});

it('lets an engine failure beat an unlocked supports', function () {
    CriterionAssessment::create(['site' => null, 'criterion' => '1.1.1', 'level' => 'A', 'status' => CriterionAssessment::SUPPORTS, 'locked' => false, 'remarks' => 'Probably fine.']);

    [, $html] = document();

    expect($html)->toMatch('/1\.1\.1 Non-text Content<\/a><\/th>\s*<td>A<\/td>\s*<td class="status status-does_not_support">Does not support/');
    expect($html)->toContain('Unlocked assessment on file: Probably fine.');
});

it('lists the open issues with page, label, impact, and state, and omits what a person closed', function () {
    $scan = scannedSite();
    $closed = IssueState::where('rule_id', 'heading-skipped-level')->first();
    $closed->update(['status' => IssueState::FALSE_POSITIVE]);

    [, $html] = document($scan);

    expect($html)->toContain('<caption>Open issues from this scan</caption>');
    expect($html)->toContain('<td>/one</td>');
    expect($html)->toContain('<td><a href="https://www.w3.org/WAI/WCAG22/Understanding/non-text-content.html">WCAG 1.1.1</a></td>');
    expect($html)->toContain('<td>Serious</td>');
    // The state, and what the policy says about it: this one is inside its
    // target, so the cell says when it is due rather than that it is late.
    expect($html)->toMatch('/<td>\s*open\s*<span class="evidence">Due /');
    expect(substr_count(flat($html), 'Skipped'))->toBe(0);
    expect($html)->toContain('2 open issues from this scan');
});

it('caps the appendix and says how many were left out', function () {
    config()->set('statamic-a11y-report.report.appendix_limit', 1);

    [, $html] = document();

    expect($html)->toContain('1 open issue from this scan, and 2 more not listed here');
});

it('follows the scan ruleset for the standard and lets config choose 2.1', function () {
    $scan = scannedSite();
    [$a] = document($scan);
    expect($a->standard)->toBe('wcag22aa');

    config()->set('statamic-a11y-report.report.standard', 'wcag21aa');
    [$b, $html] = document($scan);
    expect($b->standard)->toBe('wcag21aa');
    expect($html)->toContain('WCAG 2.1 Level AA');
    expect($html)->toContain('4.1.1 Parsing');
    expect($html)->not->toContain('2.5.7 Dragging');
});

it('is one document with one h1, a language, and captioned tables with scoped headers', function () {
    [, $html] = document();

    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    expect($xpath->query('//html[@lang]')->length)->toBe(1);
    expect($xpath->query('//h1')->length)->toBe(1);
    expect($xpath->query('//title')->length)->toBe(1);
    expect($xpath->query('//main')->length)->toBe(1);
    expect($xpath->query('//table')->length)->toBeGreaterThanOrEqual(4);
    expect($xpath->query('//table[not(caption)]')->length)->toBe(0);
    expect($xpath->query('//table//th[not(@scope)]')->length)->toBe(0);
    expect($xpath->query('//link|//script|//img')->length)->toBe(0);
    expect($xpath->query('//section[not(@aria-labelledby)]')->length)->toBe(0);
});

it('writes the same numbers to JSON as to HTML', function () {
    [$report] = document();
    $json = json_decode(file_get_contents(app(ReportWriter::class)->absolutePath($report->json_path)), true);

    expect($json['uuid'])->toBe($report->uuid);
    expect($json['kind'])->toBe('self-assessment');
    expect($json['criteria'])->toHaveCount(55);
    expect(collect($json['criteria'])->firstWhere('number', '1.1.1')['status'])->toBe('does_not_support');
    expect($json['methods']['automated_criteria'])->toBe(['1.1.1', '1.2.1', '1.2.2', '2.4.4', '2.5.8', '4.1.2']);
    expect($json['summary']['issues_total'])->toBe(3);
    expect(count($json['issues']))->toBe(3);
});

it('refers to the previous report', function () {
    $scan = scannedSite();
    [$first] = document($scan);
    [, $html] = document($scan);

    expect($html)->toContain("The previous report, {$first->uuid}");
});

it('refuses a scan that did not complete', function () {
    $scan = scannedSite();
    $scan->update(['status' => Scan::FAILED]);

    expect(fn () => app(ReportWriter::class)->write($scan, 'x'))->toThrow(InvalidArgumentException::class);
    expect(Report::count())->toBe(0);
});

it('runs as a command and copies the files where asked', function () {
    scannedSite();
    $out = $this->storage.'/out';

    $this->artisan('statamic:a11y:report', ['--out' => $out, '--format' => 'html'])
        ->expectsOutputToContain('generated from scan')
        ->expectsOutputToContain('Automated checks speak to')
        ->expectsOutputToContain('A self-assessment, not a certification')
        ->assertExitCode(0);

    expect(Report::count())->toBe(1);
    expect(Report::first()->generated_by)->toBe('console');
    expect(glob($out.'/*.html'))->toHaveCount(1);
    expect(glob($out.'/*.json'))->toHaveCount(0);

    $this->artisan('statamic:a11y:report', ['--format' => 'docx'])->expectsOutputToContain('There is no [docx] format')->assertExitCode(1);
    $this->artisan('statamic:a11y:report', ['--scan' => 'nope'])->expectsOutputToContain('no complete scan')->assertExitCode(1);
});

it('is generated from the control panel by somebody allowed to, and downloaded by anybody who may see the report', function () {
    scannedSite();

    $this->actingAs($this->user)
        ->post(cp_route('utilities.a11y-report.reports.generate'))
        ->assertRedirect()
        ->assertSessionHas('success');

    $report = Report::first();
    expect($report->generated_by)->toBe('super@example.test');

    $this->actingAs($this->user)
        ->get(cp_route('utilities.a11y-report.reports.download', ['uuid' => $report->uuid, 'format' => 'html']))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=utf-8');

    $this->actingAs($this->user)
        ->get(cp_route('utilities.a11y-report.reports.download', ['uuid' => $report->uuid, 'format' => 'json']))
        ->assertOk();

    $this->actingAs($this->user)
        ->get(cp_route('utilities.a11y-report.reports.download', ['uuid' => 'nope', 'format' => 'html']))
        ->assertNotFound();

    Role::make('reader')->permissions(['access cp', 'access a11y-report utility'])->save();
    $reader = User::make()->email('reader@example.test')->assignRole('reader');
    $reader->save();

    $this->actingAs($reader)->post(cp_route('utilities.a11y-report.reports.generate'))->assertForbidden();
    $this->actingAs($reader)->get(cp_route('utilities.a11y-report.reports.download', ['uuid' => $report->uuid, 'format' => 'html']))->assertOk();

    $text = pageText($this->actingAs($reader)->get(cp_route('utilities.index').'/a11y-report')->getContent());
    expect($text)->toContain('Conformance reports');
    expect($text)->not->toContain('Generate a report');
});

it('says so on the control panel when there is no complete scan to report on', function () {
    $this->actingAs($this->user)
        ->post(cp_route('utilities.a11y-report.reports.generate'))
        ->assertSessionHas('error');

    expect(Report::count())->toBe(0);
});

it('links every criterion, and the standard, to the W3C text for the report\'s WCAG version', function () {
    [, $html] = document();

    expect($html)->toContain('<a href="https://www.w3.org/TR/WCAG22/">WCAG 2.2 Level AA</a>');
    expect($html)->toContain('<th scope="row"><a href="https://www.w3.org/WAI/WCAG22/Understanding/non-text-content.html">1.1.1 Non-text Content</a></th>');
    expect($html)->toContain('<a href="https://www.w3.org/WAI/WCAG22/Understanding/accessible-authentication-minimum.html">3.3.8 Accessible Authentication (Minimum)</a>');
    // The two lists in the limits statement, number by number.
    expect($html)->toContain('<a href="https://www.w3.org/WAI/WCAG22/Understanding/link-purpose-in-context.html" title="Link Purpose (In Context)">2.4.4</a>');
    expect(substr_count($html, 'https://www.w3.org/WAI/WCAG22/Understanding/'))->toBeGreaterThanOrEqual(55 + 6);
    expect(substr_count($html, 'WCAG21/Understanding'))->toBe(0);
    // A house rule cites no criterion and gets no link.
    expect(str_contains($html, 'Understanding/heading-structure'))->toBeFalse('a house rule is not linked to a criterion');
    expect($html)->toContain('<td>Heading structure</td>');
});

it('links to the WCAG 2.1 text when the report is set to 2.1', function () {
    config()->set('statamic-a11y-report.report.standard', 'wcag21aa');
    [, $html] = document();

    expect($html)->toContain('<a href="https://www.w3.org/TR/WCAG21/">WCAG 2.1 Level AA</a>');
    expect($html)->toContain('https://www.w3.org/WAI/WCAG21/Understanding/parsing.html');

    // Nothing 2.2 is mentioned, because a report set to 2.1 must not offer a
    // criterion its own table has no row for. The engine can cite 2.5.8, and
    // the limits statement used to list it here, which counted an evaluation
    // of something this document does not report on.
    expect(substr_count($html, 'WCAG22/Understanding'))->toBe(0);
    expect(str_contains($html, '2.5.8'))->toBeFalse('a 2.1 report says nothing about a 2.2 criterion');
});

it('reports on the newest scan when the scope names a site', function () {
    $user = User::make()->email('super@example.test')->makeSuper();
    $user->save();

    page('one', '<img src="/a.jpg">');

    // An early scan of every site, as an install has before anybody narrows
    // the scope. Its `site` is null.
    $all = runScan();
    expect($all->site)->toBeNull();

    // Then the scope names a site, which is what the settings screen writes
    // the moment somebody saves it. Every scan from here carries that name.
    page('two', '<a href="#">Somewhere</a>');
    $newest = runScan(sites: ['default']);
    expect($newest->site)->toBe('default');
    expect($newest->id)->toBeGreaterThan($all->id);

    $this->actingAs($user)->post(cp_route('utilities.a11y-report.reports.generate'))->assertRedirect();

    // `where('site', null)` reads as `site is null`, so it saw only the first
    // scan and reported on it: a conformance document hours out of date, with
    // nothing on the screen to say which scan it came from.
    expect(Report::latest('id')->first()->scan_id)->toBe($newest->id);
});

it('counts the same table three ways without pretending they add up', function () {
    $user = User::make()->email('super@example.test')->makeSuper();
    $user->save();

    page('one', '<img src="/a.jpg">');
    $scan = runScan();

    CriterionAssessment::create([
        'site' => null, 'criterion' => '1.4.3', 'level' => 'AA',
        'status' => CriterionAssessment::SUPPORTS, 'method' => 'manual', 'locked' => true,
        'assessed_by' => 'reviewer@example.test', 'assessed_at' => now(),
    ]);

    $report = app(ReportWriter::class)->write($scan->fresh(), 'tester@example.test');
    $data = json_decode((string) file_get_contents(app(ReportWriter::class)->absolutePath($report->json_path)), true);

    $total = count($data['criteria']);
    $automated = count($data['methods']['automated_criteria']);
    $unevaluated = count($data['methods']['not_evaluated']);
    $people = count($data['methods']['assessed_by_people']);

    // The three overlap on purpose: a criterion the checks can speak to is
    // still "not evaluated" where they found nothing. The old sentence joined
    // them with semicolons, which reads as parts of one whole, and they added
    // to more than the table has rows.
    expect($automated + $unevaluated + $people)->toBeGreaterThan($total);

    expect($report->coverage_note)->toBe(
        "Automated checks speak to {$automated} of {$total} criteria. {$unevaluated} are marked not evaluated, and 1 carries a person's assessment."
    );

    // Singular and plural, because "1 were assessed by a person" was printed
    // to somebody who then wrote it down.
    expect($report->coverage_note)->not->toContain('1 carry');
    expect($report->coverage_note)->not->toContain('were assessed');
});

it('says which zone the clock times in a document are in', function () {
    $user = User::make()->email('super@example.test')->makeSuper();
    $user->save();

    page('one', '<img src="/a.jpg">');
    $scan = runScan();

    [$report, $html] = document($scan);

    // The footer has always carried the zone. The evaluation period did not,
    // and it is the line that says when the site was actually looked at: a
    // clock time with nothing to place it against is half a date, in a
    // document filed as evidence and read by people in other countries.
    // Pulled out of its own <dd> and asserted on alone. A dot-all `.*` across
    // the whole document happily reached the footer's zone and passed while
    // this line had none, which is the test proving the wrong thing.
    preg_match('/Evaluation period<\/dt>\s*<dd>(.*?)<\/dd>/s', $html, $m);
    expect($m[1] ?? '')->toMatch('/\d\d:\d\d to .*\d\d:\d\d [A-Z]{2,5}/');

    // And the machine-readable copy was never ambiguous: ISO 8601 carries the
    // offset, which is why this is a fix to what a person reads and not to
    // what the JSON says.
    $data = json_decode((string) file_get_contents(app(ReportWriter::class)->absolutePath($report->json_path)), true);
    expect($data['generated_at'])->toMatch('/[+-]\d\d:\d\d$|Z$/');
    expect($data['scan']['finished_at'])->toMatch('/[+-]\d\d:\d\d$|Z$/');
});

it('says when a person\'s assessment is kept over what the checks found', function () {
    $user = User::make()->email('super@example.test')->makeSuper();
    $user->save();

    page('one', '<img src="/a.jpg">');
    $scan = runScan();

    // A person locks "Supports" on the one criterion the scan has failures
    // under. Rule 1 says their answer stands, and it does. Nothing said the
    // two disagreed.
    CriterionAssessment::create([
        'site' => null, 'criterion' => '1.1.1', 'level' => 'A',
        'status' => CriterionAssessment::SUPPORTS, 'method' => 'manual', 'locked' => true,
        'remarks' => 'Reviewed by hand.', 'assessed_by' => 'Reviewer', 'assessed_at' => now(),
    ]);

    [$report, $html] = document($scan->fresh());

    preg_match('/1\.1\.1 Non-text Content.*?<\/tr>/s', $html, $m);
    $row = $m[0] ?? '';

    // The determination is unchanged: it is the assessor's and the report
    // states it.
    expect($row)->toContain('Supports');
    expect($row)->toContain('Reviewed by hand.');

    // And the disagreement is said out loud, before the remarks, rather than
    // left for a reader to infer from the evidence sentence underneath.
    expect($row)->toContain("A person's assessment, kept over the automated result.");

    $data = json_decode((string) file_get_contents(app(ReportWriter::class)->absolutePath($report->json_path)), true);
    $flagged = array_values(array_filter($data['criteria'], fn ($c) => $c['contradicted']));
    expect($flagged)->toHaveCount(1);
    expect($flagged[0]['number'])->toBe('1.1.1');
});

it('does not call a locked assessment contradicted when it agrees, or when nothing failed', function () {
    $user = User::make()->email('super@example.test')->makeSuper();
    $user->save();

    page('one', '<img src="/a.jpg">');
    $scan = runScan();

    // Agreeing with the failures is not a disagreement.
    CriterionAssessment::create([
        'site' => null, 'criterion' => '1.1.1', 'level' => 'A',
        'status' => CriterionAssessment::DOES_NOT_SUPPORT, 'method' => 'manual', 'locked' => true,
    ]);

    // And a criterion nobody has failed has nothing to contradict.
    CriterionAssessment::create([
        'site' => null, 'criterion' => '1.4.3', 'level' => 'AA',
        'status' => CriterionAssessment::SUPPORTS, 'method' => 'manual', 'locked' => true,
    ]);

    [$report] = document($scan->fresh());

    $data = json_decode((string) file_get_contents(app(ReportWriter::class)->absolutePath($report->json_path)), true);

    expect(array_filter($data['criteria'], fn ($c) => $c['contradicted']))->toBe([]);
});
