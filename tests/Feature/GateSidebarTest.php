<?php

declare(strict_types=1);

use Bpmore\A11yReport\Models\IssueState;
use Bpmore\A11yReport\Panel\OpenIssuesForEntry;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Statamic\Fields\Field;

/**
 * What the gate's panel says about a page on this addon's behalf.
 */
beforeEach(function () {
    $this->withStandardFakeViews();
    test()->viewShouldReturnRaw('default', PLAIN);
    Collection::make('pages')->routes('/{slug}')->save();
    app(ReportDatabase::class)->install();
});

function sidebarFor(string $slug): ?array
{
    $entry = Entry::query()->where('collection', 'pages')->where('slug', $slug)->first();

    return (new OpenIssuesForEntry(app(ReportDatabase::class)))($entry);
}

it('says nothing for a page no scan has read, and nothing before the tables exist', function () {
    page('one', '<img src="/a.jpg">');
    expect(sidebarFor('one'))->toBeNull();

    runScan();
    page('two', '<p>New since the scan.</p>');
    expect(sidebarFor('two'))->toBeNull();
});

it('lists the open issues from the last scan with a way into the queue for this page', function () {
    page('one', '<img src="/a.jpg"><img src="/b.jpg"><img src="/c.jpg"><h3>Skipped</h3><a href="#">Somewhere</a>');
    runScan();

    $block = sidebarFor('one');

    expect($block['heading'])->toBe('5 open issues from the last scan');
    expect($block['lines'])->toHaveCount(5);
    // Five issues of the same impact found in the same second: the first
    // three are whichever the queue orders first, so only their shape is
    // pinned, plus the count of the rest.
    expect(implode(' ', $block['lines']))->toContain('This image has no description');
    expect($block['lines'][3])->toBe('And 2 more.');
    expect($block['lines'][4])->toStartWith('From the scan of');
    expect($block['link']['text'])->toBe('Open in the queue');
    expect($block['link']['url'])->toContain('path=%2Fone');
    expect($block['link']['url'])->toContain('site=default');
});

it('says so when the last scan left nothing open, and follows a decision made in the queue', function () {
    page('one', '<img src="/a.jpg">');
    runScan();
    expect(sidebarFor('one')['heading'])->toBe('1 open issue from the last scan');

    IssueState::where('path', '/one')->update(['status' => IssueState::WONT_FIX]);

    $block = sidebarFor('one');
    expect($block['heading'])->toBe('No open issues from the last scan');
    expect($block['lines'][0])->toContain('A scan reads the page as it was published');
    expect($block['link'] ?? null)->toBeNull();
});

it('filters the queue to one page, and lets it go again', function () {
    page('one', '<img src="/a.jpg">');
    page('two', '<img src="/b.jpg">');
    runScan();

    $user = User::make()->email('super@example.test')->makeSuper();
    $user->save();

    $text = pageText($this->actingAs($user)->get(cp_route('utilities.a11y-report.issues', ['path' => '/one']))->assertOk()->getContent());
    expect($text)->toContain('1 issue match');
    expect($text)->toContain('Only the page /one');
    expect($text)->toContain('text="Every page"');
    expect($text)->toContain('name="path" value="/one"');

    expect(pageText($this->actingAs($user)->get(cp_route('utilities.a11y-report.issues'))->getContent()))->toContain('2 issues match');
});

it('reaches the panel through the gate\'s seam', function () {
    page('one', '<img src="/a.jpg">');
    runScan();

    $entry = Entry::query()->where('collection', 'pages')->where('slug', 'one')->first();
    $preload = (new Field('accessibility_panel', ['type' => 'accessibility_panel']))->setParent($entry)->fieldtype()->preload();

    expect($preload['extensions'])->toHaveCount(1);
    expect($preload['extensions'][0]['heading'])->toBe('1 open issue from the last scan');
    expect($preload['extensions'][0]['link']['text'])->toBe('Open in the queue');
});
