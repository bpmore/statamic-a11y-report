<?php

declare(strict_types=1);

use Bpmore\A11yReport\Document\ReportWriter;
use Bpmore\A11yReport\Models\Issue;
use Bpmore\A11yReport\Models\IssueState;
use Bpmore\A11yReport\Models\Report;
use Bpmore\A11yReport\Models\Scan;
use Bpmore\A11yReport\Models\ScanPage;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Bpmore\A11yReport\Trends\Overview;
use Illuminate\Support\Facades\DB;
use Statamic\Facades\Collection;
use Statamic\Facades\User;

/**
 * The remediation policy: targets that say when work is late, and the
 * exception register that says what has been accepted instead of fixed, by
 * whom, and until when.
 *
 * The first test in this file is the one the rest of the feature depends on.
 * An exception is a decision about what an organisation will chase. If one
 * could ever soften a row in the conformance table or take an issue out of a
 * report, the register would be a way to make a site look accessible, in the
 * product whose whole value is that its claims are true.
 */
beforeEach(function () {
    $this->withStandardFakeViews();
    test()->viewShouldReturnRaw('default', PLAIN);
    Collection::make('pages')->routes('/{slug}')->save();
    app(ReportDatabase::class)->install();

    $this->user = User::make()->email('super@example.test')->makeSuper();
    $this->user->save();

    $this->storage = tempStorage();
});

afterEach(function () {
    foreach (glob($this->storage.'/a11y-report/reports/*') ?: [] as $f) {
        unlink($f);
    }
});

/** Two pages with three problems between them: one serious, one moderate. */
function policySite(): Scan
{
    page('one', '<img src="/a.jpg">');
    page('two', '<h3>Skipped</h3><a href="#">Somewhere</a>');

    return runScan();
}

/** The fingerprint of the missing alternative text on /one, which cites 1.1.1. */
function missingAlt(): string
{
    return IssueState::where('rule_id', 'image-missing-alt')->firstOrFail()->fingerprint;
}

function accept(array $input = [], $fingerprint = null): \Illuminate\Testing\TestResponse
{
    return test()->actingAs(test()->user)->post(cp_route('utilities.a11y-report.issues.update'), array_merge([
        'fingerprints' => [$fingerprint ?? missingAlt()],
        'status' => 'wont_fix',
        'exception_reason' => 'Supplied by the ticketing vendor, replacement contracted.',
    ], $input));
}

/** The queue's rendered text. Named apart from the queue file's own helper. */
function policyQueue(array $query = []): string
{
    return pageText(test()->actingAs(test()->user)->get(cp_route('utilities.a11y-report.issues', $query))->assertOk()->getContent());
}

function reportHtml(Scan $scan): array
{
    $report = app(ReportWriter::class)->write($scan, 'tester@example.test');

    return [$report, (string) file_get_contents((string) app(ReportWriter::class)->absolutePath($report->html_path))];
}

it('leaves an accepted failure standing as a failure in the conformance table', function () {
    $scan = policySite();
    accept();

    [, $html] = reportHtml($scan);

    // The rule the whole feature rests on. Accepting a problem records a
    // decision not to fix it. It does not change what was found, so 1.1.1 is
    // still "does not support" and the criterion still counts the failure.
    expect($html)->toMatch('/1\.1\.1 Non-text Content<\/a><\/th>\s*<td>A<\/td>\s*<td class="status status-does_not_support">/');
    expect($html)->toContain('Automated checks found 1 issue on 1 page (image-missing-alt).');

    // And it is in the document rather than left out of it.
    expect($html)->toContain('Issues accepted rather than fixed');
    expect($html)->toContain('Supplied by the ticketing vendor, replacement contracted.');
    expect(str_contains($html, 'Accepting an issue does not change what was found.'))->toBeTrue('the document says what an acceptance does not do');
});

it('refuses an acceptance with no reason, and changes nothing', function () {
    policySite();
    $before = IssueState::find(missingAlt());

    accept(['exception_reason' => '   '])->assertSessionHas('error');

    $after = IssueState::find(missingAlt());

    expect($after->status)->toBe(IssueState::OPEN);
    expect($after->exception_reason)->toBeNull();
    expect($after->exception_expires_at)->toBeNull();
    expect($after->updated_at->timestamp)->toBe($before->updated_at->timestamp);
});

it('records who accepted a problem, when, and the date it runs out on', function () {
    config()->set('statamic-a11y-report.report.remediation.exception_days', 90);
    policySite();

    accept()->assertSessionHas('success');

    $state = IssueState::find(missingAlt());

    expect($state->status)->toBe(IssueState::WONT_FIX);
    expect($state->exception_reason)->toBe('Supplied by the ticketing vendor, replacement contracted.');
    expect($state->exception_by)->toBe('super@example.test');
    expect($state->exception_at)->not->toBeNull();
    // Nothing is accepted forever. With no date asked for, the acceptance runs
    // to the furthest the policy allows and not one day past it.
    expect($state->exception_expires_at->toDateString())->toBe(now()->copy()->addDays(90)->toDateString());
});

it('refuses an acceptance set further ahead than the policy allows, and says how far it would go', function () {
    config()->set('statamic-a11y-report.report.remediation.exception_days', 30);
    policySite();

    $response = accept(['exception_expires_at' => now()->copy()->addYears(5)->toDateString()]);

    $response->assertSessionHas('error');
    expect(session('error'))->toContain(now()->copy()->addDays(30)->format('j F Y'));
    // Refused rather than quietly moved: a date nobody chose, recorded under
    // their name, is a promise they did not make.
    expect(IssueState::find(missingAlt())->status)->toBe(IssueState::OPEN);
});

it('refuses a date that has already gone', function () {
    policySite();

    accept(['exception_expires_at' => now()->copy()->subDay()->toDateString()])->assertSessionHas('error');

    expect(IssueState::find(missingAlt())->status)->toBe(IssueState::OPEN);
});

it('counts an acceptance that has run out as open, in the queue, the overview and the report', function () {
    $scan = policySite();
    accept();

    expect(policyQueue())->toContain('2 issues match');
    expect((new Overview(app(ReportDatabase::class)))->openTotal())->toBe(2);

    // The date goes by. Nothing runs, nothing flips the row: the stored status
    // is still what the person wrote.
    IssueState::where('fingerprint', missingAlt())->update(['exception_expires_at' => now()->copy()->subDay()]);

    expect(IssueState::find(missingAlt())->status)->toBe(IssueState::WONT_FIX);
    expect(policyQueue())->toContain('3 issues match');
    expect((new Overview(app(ReportDatabase::class)))->openTotal())->toBe(3);
    expect((new Overview(app(ReportDatabase::class)))->expiredExceptions())->toBe(1);

    [, $html] = reportHtml($scan);

    expect($html)->toContain('3 open issues from this scan');
    expect($html)->toContain('Acceptances that have run out');
    expect($html)->toMatch('/Accepted until [^<]+, now expired/');
    // And it is no longer counted among the acceptances in force.
    expect($html)->toContain('No issue in this report has been accepted.');
});

it('clears the acceptance when the issue moves off wont fix', function () {
    policySite();
    accept();

    $this->actingAs($this->user)->post(cp_route('utilities.a11y-report.issues.update'), [
        'fingerprints' => [missingAlt()],
        'status' => 'in_progress',
    ]);

    $state = IssueState::find(missingAlt());

    expect($state->status)->toBe(IssueState::IN_PROGRESS);

    foreach (IssueState::EXCEPTION_COLUMNS as $column) {
        expect($state->{$column})->toBeNull("{$column} is cleared when the acceptance ends");
    }
});

it('counts an issue past its target as overdue and filters to it', function () {
    config()->set('statamic-a11y-report.report.remediation.targets', ['critical' => 7, 'serious' => 30, 'moderate' => 90, 'minor' => null]);
    policySite();

    expect(policyQueue())->not->toContain('past target');

    IssueState::where('rule_id', 'image-missing-alt')->update(['first_seen_at' => now()->copy()->subDays(45)]);

    expect(policyQueue())->toContain('1 past target');
    expect(policyQueue(['due' => 'overdue']))->toContain('1 issue match');
    // 45 days old against a 30 day target: 15 days past it, not 45.
    expect(policyQueue(['due' => 'overdue']))->toContain('Past target by 15 days');
    // A problem inside its target is not late, and one with no target never is.
    expect(policyQueue(['due' => 'overdue']))->not->toContain('Somewhere');
});

it('counts nothing as overdue when no impact has a target', function () {
    config()->set('statamic-a11y-report.report.remediation.targets', ['critical' => 0, 'serious' => 0, 'moderate' => 0, 'minor' => 0]);
    $scan = policySite();
    IssueState::query()->update(['first_seen_at' => now()->copy()->subYears(4)]);

    // A policy with no targets must report nothing overdue. An empty set of
    // conditions in SQL matches every row, which would have said an entire
    // site was late the moment somebody switched the targets off.
    expect((new Overview(app(ReportDatabase::class)))->overdueTotal())->toBe(0);
    expect(policyQueue())->not->toContain('past target');

    [, $html] = reportHtml($scan);

    expect($html)->toContain('No remediation target has been recorded');
});

it('stamps the policy into the report and does not move it when the settings change', function () {
    config()->set('statamic-a11y-report.report.remediation.targets.critical', 3);
    config()->set('statamic-a11y-report.report.remediation.exception_days', 45);

    [$report, $html] = reportHtml(policySite());

    expect($report->remediation_policy['targets']['critical'])->toBe(3);
    expect($report->remediation_policy['exception_days'])->toBe(45);
    expect($html)->toContain('<td>3 days</td>');

    // The promise changes. The document that was filed does not.
    config()->set('statamic-a11y-report.report.remediation.targets.critical', 60);

    expect(Report::find($report->id)->remediation_policy['targets']['critical'])->toBe(3);

    $json = json_decode((string) file_get_contents((string) app(ReportWriter::class)->absolutePath($report->json_path)), true);

    expect($json['remediation']['policy']['targets']['critical'])->toBe(3);
});

it('prints the register with the reason, who accepted it and when it runs out', function () {
    $scan = policySite();
    accept(['exception_expires_at' => now()->copy()->addDays(20)->toDateString()]);

    [, $html] = reportHtml($scan);

    expect($html)->toContain('<caption>Issues accepted rather than fixed</caption>');
    expect($html)->toContain('Supplied by the ticketing vendor, replacement contracted.');
    expect($html)->toContain('super@example.test');
    expect($html)->toContain(now()->copy()->addDays(20)->format('j F Y'));
    // An accepted issue is not counted among the open ones while it stands.
    expect($html)->toContain('2 open issues from this scan');
});

it('gives an acceptance made before the register existed a date to be reviewed by', function () {
    config()->set('statamic-a11y-report.report.remediation.exception_days', 120);
    policySite();

    // The state of an install that accepted issues before this feature: the
    // decision and its note, and none of the four columns that record it.
    $connection = ReportDatabase::connectionName();
    $previous = config('database.default');
    config()->set('database.default', $connection);

    $migration = require __DIR__.'/../../database/migrations/2026_09_04_000007_add_exceptions_to_a11y_issue_states_table.php';
    $migration->down();

    DB::connection($connection)->table('a11y_issue_states')->where('fingerprint', missingAlt())->update([
        'status' => IssueState::WONT_FIX,
        'note' => 'Agreed with the author in 2025.',
        'updated_by' => 'sam@example.test',
    ]);

    $migration->up();
    config()->set('database.default', $previous);

    $state = IssueState::find(missingAlt());

    // Neither of the two quiet answers: not grandfathered forever, and not
    // reopened on the morning somebody upgrades.
    expect($state->status)->toBe(IssueState::WONT_FIX);
    expect($state->exception_reason)->toBe('Agreed with the author in 2025.');
    expect($state->exception_by)->toBe('sam@example.test');
    expect($state->exception_expires_at->toDateString())->toBe(now()->copy()->addDays(120)->toDateString());
    expect($state->acceptanceHasExpired())->toBeFalse();
});

it('renders markup Vue can compile once acceptances are on the screen, with a label on every control', function () {
    policySite();
    accept();
    // One standing and one run out, so both branches of the status cell and
    // the policy badges are in the markup. The queue file's own compile check
    // renders none of them: a malformed branch nobody reaches in a test is a
    // blank control panel screen and never an exception on the server.
    IssueState::where('rule_id', 'link-goes-nowhere')->update([
        'status' => IssueState::WONT_FIX,
        'exception_reason' => 'Agreed last year.',
        'exception_by' => 'sam@example.test',
        'exception_at' => now()->copy()->subYear(),
        'exception_expires_at' => now()->copy()->subDay(),
    ]);

    $response = $this->actingAs($this->user)->get(cp_route('utilities.a11y-report.issues'))->getContent();
    preg_match('/data-page="([^"]+)"/', $response, $m);
    $html = (string) json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true)['props']['html'];

    assertVueTemplateIsWellFormed($html);

    $text = pageText($response);
    expect($text)->toContain('Accepted until');
    expect($text)->toContain('which has gone');
    expect($text)->toContain('1 acceptance run out');

    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<html><body>'.html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8').'</body></html>');
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $controls = $xpath->query('//select|//input[@type="text"]|//input[@type="date"]');

    expect($controls->length)->toBeGreaterThan(0, 'the screen has controls to check');

    foreach ($controls as $control) {
        $id = $control->getAttribute('id');
        expect($id)->not->toBe('', 'every control has an id to be labelled by');
        expect($xpath->query('//label[@for="'.$id.'"]')->length)->toBe(1, "the control {$id} has exactly one label");
    }
});

it('caps the exception register the way the appendix is capped, and says what it left out', function () {
    $scan = policySite();
    $page = ScanPage::first();

    // The queue accepts everything a filter matches in one press, so a long
    // register is one click away. Uncapped, every row of it went into the
    // HTML and into the PDF beside an appendix that has had a limit all
    // along.
    config()->set('statamic-a11y-report.report.appendix_limit', 5);

    $rows = [];
    $states = [];

    for ($i = 0; $i < 12; $i++) {
        $fingerprint = sha1('accepted-'.$i);

        $rows[] = [
            'scan_id' => $scan->id, 'page_id' => $page->id, 'fingerprint' => $fingerprint,
            'rule_id' => 'made-up', 'label' => 'WCAG 1.1.1', 'wcag_criteria' => json_encode(['1.1.1']),
            'impact' => 'serious', 'message' => 'A problem', 'occurrences' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ];

        $states[] = [
            'fingerprint' => $fingerprint, 'status' => IssueState::WONT_FIX, 'engine' => 'php',
            'url' => $page->url, 'site' => $page->site, 'path' => $page->path,
            'rule_id' => 'made-up', 'impact' => 'serious',
            'first_seen_at' => now(), 'last_seen_at' => now(), 'last_scan_id' => $scan->id,
            'exception_reason' => 'Accepted for the test',
            'exception_by' => 'someone@example.test',
            'exception_at' => now(),
            // Ascending, so the ones kept are the ones that run out soonest.
            'exception_expires_at' => now()->copy()->addDays($i + 1),
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    Issue::insert($rows);
    IssueState::insert($states);

    $data = app(\Bpmore\A11yReport\Document\ReportBuilder::class)->build($scan->fresh(), 'tester');

    expect($data['remediation']['exceptions'])->toHaveCount(5);
    expect($data['remediation']['exceptions_omitted'])->toBe(7);

    // The ones a person has to look at next, not an arbitrary five.
    $kept = array_map(fn ($e) => (string) $e['expires_at'], $data['remediation']['exceptions']);
    $sorted = $kept;
    sort($sorted);

    $soonest = IssueState::whereNotNull('exception_expires_at')
        ->orderBy('exception_expires_at')
        ->limit(5)
        ->pluck('exception_expires_at')
        ->map(fn ($d) => (string) $d)
        ->all();

    expect($kept)->toBe($sorted);
    expect($kept)->toBe($soonest);

    // Cut, and never quietly: a compliance document that dropped accepted
    // failures without saying so would be the worse of the two answers.
    $html = ReportWriter::html($data);

    expect($html)->toContain('5 of 12 accepted issues are listed here');
    expect($html)->toContain('still counted under their success criteria');
});
