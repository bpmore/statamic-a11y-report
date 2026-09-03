<?php

declare(strict_types=1);

use Bpmore\A11yReport\Models\IssueState;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Statamic\Facades\Collection;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * The remediation queue: what it lists, how it filters, and what a bulk
 * change records.
 */
beforeEach(function () {
    $this->withStandardFakeViews();
    test()->viewShouldReturnRaw('default', PLAIN);
    Collection::make('pages')->routes('/{slug}')->save();
    Collection::make('posts')->routes('/blog/{slug}')->save();
    app(ReportDatabase::class)->install();

    $this->user = User::make()->email('super@example.test')->makeSuper();
    $this->user->save();
});

function queuePage(array $query = [], $as = null): string
{
    $as ??= test()->user;

    return pageText(test()->actingAs($as)->get(cp_route('utilities.a11y-report.issues', $query))->assertOk()->getContent());
}

function seedQueue(): void
{
    page('one', '<img src="/a.jpg">');
    page('two', '<a href="#">Somewhere</a><a href="/x">Read more</a>');
    page('post', '<h3>Skipped</h3>', collection: 'posts');
    runScan();
}

it('lists open issues oldest and most serious first, with the page, the wording, and how long open', function () {
    seedQueue();
    IssueState::where('rule_id', 'heading-skipped-level')->update(['first_seen_at' => now()->subDays(12)]);

    $text = queuePage();

    expect($text)->toContain('Issues');
    expect($text)->toContain('4 issues match');
    expect($text)->toContain('This image has no description');
    expect($text)->toContain('12 days');
    expect($text)->toContain('/blog/post');
    // Serious before moderate, whatever the age.
    expect(strpos($text, 'image-missing-alt') ?: strpos($text, 'This image has no description'))->toBeLessThan(strpos($text, 'Somewhere'));
    // The oldest serious one first.
    expect(strpos($text, 'Skipped'))->toBeLessThan(strpos($text, 'This image has no description'));
});

it('filters by status, impact, criterion, collection, and assignee', function () {
    seedQueue();
    IssueState::where('rule_id', 'link-goes-nowhere')->update(['status' => IssueState::WONT_FIX]);
    IssueState::where('rule_id', 'image-missing-alt')->update(['assigned_to' => 'sam@example.test']);

    expect(queuePage())->toContain('3 issues match');
    expect(queuePage(['status' => 'wont_fix']))->toContain('1 issue match');
    expect(queuePage(['status' => 'all']))->toContain('4 issues match');
    expect(queuePage(['impact' => 'moderate']))->toContain('Nothing matches');
    expect(queuePage(['impact' => 'moderate', 'status' => 'all']))->toContain('1 issue match');
    expect(queuePage(['criterion' => 'WCAG 1.1.1']))->toContain('1 issue match');
    expect(queuePage(['criterion' => 'Heading structure']))->toContain('Skipped');
    expect(queuePage(['collection' => 'posts']))->toContain('1 issue match');
    expect(queuePage(['assignee' => 'sam@example.test']))->toContain('1 issue match');
    expect(queuePage(['assignee' => '-']))->toContain('2 issues match');
    expect(queuePage(['impact' => 'bogus']))->toContain('3 issues match');
    expect(queuePage(['status' => 'bogus']))->toContain('3 issues match');
});

it('lists page removed as its own status, out of the default view', function () {
    seedQueue();
    IssueState::where('rule_id', 'image-missing-alt')->update(['status' => IssueState::PAGE_REMOVED]);

    expect(queuePage())->toContain('3 issues match');
    expect(queuePage(['status' => 'page_removed']))->toContain('1 issue match');
    expect(queuePage(['status' => 'page_removed']))->toContain('Page removed');
});

it('changes the ticked issues and records who did it', function () {
    seedQueue();
    $fp = IssueState::where('rule_id', 'image-missing-alt')->first()->fingerprint;

    $this->actingAs($this->user)
        ->post(cp_route('utilities.a11y-report.issues.update'), [
            'fingerprints' => [$fp, 'not-a-fingerprint'],
            'status' => 'in_progress',
            'assigned_to' => 'sam@example.test',
            'note' => 'Alt text being written.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', '1 issue updated.');

    $state = IssueState::find($fp);
    expect($state->status)->toBe(IssueState::IN_PROGRESS);
    expect($state->assigned_to)->toBe('sam@example.test');
    expect($state->note)->toBe('Alt text being written.');
    expect($state->updated_by)->toBe('super@example.test');
    expect($state->resolved_at)->toBeNull();
    expect(IssueState::where('status', IssueState::OPEN)->count())->toBe(3);
});

it('resolves and unresolves with the status, and clears an assignee with a dash', function () {
    seedQueue();
    $fp = IssueState::first()->fingerprint;

    $this->actingAs($this->user)->post(cp_route('utilities.a11y-report.issues.update'), ['fingerprints' => [$fp], 'status' => 'wont_fix', 'assigned_to' => 'sam']);
    expect(IssueState::find($fp)->resolved_at)->not->toBeNull();
    expect(IssueState::find($fp)->assigned_to)->toBe('sam');

    $this->actingAs($this->user)->post(cp_route('utilities.a11y-report.issues.update'), ['fingerprints' => [$fp], 'status' => 'open', 'assigned_to' => '-']);
    expect(IssueState::find($fp)->resolved_at)->toBeNull();
    expect(IssueState::find($fp)->assigned_to)->toBeNull();
});

it('applies to everything the filter matches when asked, and only that', function () {
    seedQueue();

    $this->actingAs($this->user)
        ->post(cp_route('utilities.a11y-report.issues.update'), [
            'all_matching' => '1',
            'filters' => ['collection' => 'pages'],
            'status' => 'fixed',
        ])
        ->assertSessionHas('success', '3 issues updated.');

    expect(IssueState::where('status', IssueState::FIXED)->count())->toBe(3);
    expect(IssueState::where('rule_id', 'heading-skipped-level')->first()->status)->toBe(IssueState::OPEN);
});

it('refuses an empty change and an empty selection', function () {
    seedQueue();

    $this->actingAs($this->user)->post(cp_route('utilities.a11y-report.issues.update'), ['fingerprints' => [IssueState::first()->fingerprint]])
        ->assertSessionHas('error');
    $this->actingAs($this->user)->post(cp_route('utilities.a11y-report.issues.update'), ['status' => 'fixed'])
        ->assertSessionHas('error');
    expect(IssueState::where('status', IssueState::FIXED)->count())->toBe(0);
});

it('keeps a decision through the next scan', function () {
    seedQueue();
    $fp = IssueState::where('rule_id', 'image-missing-alt')->first()->fingerprint;
    $this->actingAs($this->user)->post(cp_route('utilities.a11y-report.issues.update'), ['fingerprints' => [$fp], 'status' => 'false_positive', 'note' => 'Decorative.']);

    runScan();

    expect(IssueState::find($fp)->status)->toBe(IssueState::FALSE_POSITIVE);
    expect(IssueState::find($fp)->note)->toBe('Decorative.');
    expect(queuePage())->not->toContain('This image has no description');
});

it('shows the queue but no controls to somebody who may not manage it, and refuses their changes', function () {
    seedQueue();
    Role::make('reader')->permissions(['access cp', 'access a11y-report utility'])->save();
    $reader = User::make()->email('reader@example.test')->assignRole('reader');
    $reader->save();

    $text = queuePage([], $reader);
    expect($text)->toContain('4 issues match');
    expect($text)->not->toContain('Change the ticked issues');
    expect($text)->not->toContain('fingerprints[]');

    $this->actingAs($reader)->post(cp_route('utilities.a11y-report.issues.update'), ['fingerprints' => [IssueState::first()->fingerprint], 'status' => 'fixed'])->assertForbidden();
});

it('paginates, keeping the filter in the links', function () {
    app(ReportDatabase::class)->install();
    $body = str_repeat('<img src="/x.jpg">', 1);
    for ($i = 1; $i <= 55; $i++) {
        page("p{$i}", '<img src="/img'.$i.'.jpg">');
    }
    runScan();

    $text = queuePage(['impact' => 'serious']);
    expect($text)->toContain('55 issues match');
    expect($text)->toContain('page 1 of 2');
    expect($text)->toContain('impact=serious&amp;page=2');
    expect(queuePage(['impact' => 'serious', 'page' => 2]))->toContain('page 2 of 2');
});

it('renders markup Vue can compile, with a label on every control, and says so before any scan', function () {
    expect(queuePage())->toContain('Nothing matches');

    seedQueue();
    $response = $this->actingAs($this->user)->get(cp_route('utilities.a11y-report.issues'))->getContent();
    preg_match('/data-page="([^"]+)"/', $response, $m);
    $html = (string) json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true)['props']['html'];

    assertVueTemplateIsWellFormed($html);

    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<html><body>'.html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8').'</body></html>');
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    foreach ($xpath->query('//select|//input[@type="text"]') as $control) {
        $id = $control->getAttribute('id');
        expect($xpath->query("//label[@for='{$id}']")->length)->toBe(1, "control {$id} has a label");
    }

    foreach ($xpath->query('//input[@type="checkbox"]') as $box) {
        $labelled = $box->getAttribute('aria-label') !== '' || $box->parentNode->nodeName === 'label';
        expect($labelled)->toBeTrue('every checkbox is labelled');
    }
});

it('links a cited criterion to the W3C text for it, and leaves a house rule as words', function () {
    seedQueue();

    $text = queuePage(['status' => 'all']);

    expect($text)->toContain('<a href="https://www.w3.org/WAI/WCAG22/Understanding/non-text-content.html" target="_blank" rel="noopener" title="What this criterion requires, at w3.org">WCAG 1.1.1 Non-text Content</a>');
    expect($text)->toContain('Heading structure');
    expect(str_contains($text, 'Understanding/heading-structure'))->toBeFalse('a house rule is not linked to a criterion');
});

it('follows the configured standard for the version of WCAG a criterion link opens', function () {
    config()->set('statamic-a11y-report.report.standard', 'wcag21aa');
    seedQueue();

    expect(queuePage())->toContain('https://www.w3.org/WAI/WCAG21/Understanding/non-text-content.html');
});
