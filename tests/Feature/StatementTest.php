<?php

declare(strict_types=1);

use Bpmore\A11yReport\Document\ReportWriter;
use Bpmore\A11yReport\Document\Wcag;
use Bpmore\A11yReport\Models\CriterionAssessment;
use Bpmore\A11yReport\Statement\StatementBuilder;
use Bpmore\A11yReport\Storage\ReportDatabase;
use Statamic\Facades\Collection;

/**
 * The public accessibility statement: its own page, the tag, and what it is
 * allowed to say.
 */
beforeEach(function () {
    $this->withStandardFakeViews();
    test()->viewShouldReturnRaw('layout', '<html lang="en"><head><title>{{ title }}</title></head><body class="site-layout">{{ template_content }}</body></html>');
    test()->viewShouldReturnRaw('default', PLAIN);
    Collection::make('pages')->routes('/{slug}')->save();
    app(ReportDatabase::class)->install();
    tempStorage();
});

function statement(): string
{
    return (string) preg_replace('/\s+/', ' ', test()->get('/accessibility')->assertOk()->getContent());
}

function reported(string $body = '<p>Fine.</p>'): void
{
    page('one', $body);
    app(ReportWriter::class)->write(runScan(), 'tester');
}

it('renders at /accessibility inside the site layout, and claims nothing before an evaluation exists', function () {
    $html = statement();

    expect($html)->toContain('class="site-layout"');
    expect($html)->toContain('<title>Accessibility statement</title>');
    expect($html)->toContain('<h1>Accessibility statement for');
    expect($html)->toContain('Not yet evaluated.');
    expect($html)->toContain('no conformance status is claimed');
    expect($html)->toContain('No self-assessment has been recorded yet');
});

it('is partially conformant when the latest report has a criterion that failed, and lists it', function () {
    reported('<img src="/a.jpg">');

    $html = statement();

    expect($html)->toContain('<strong>Partially conformant</strong> with WCAG 2.2 Level AA');
    expect($html)->toContain('1 success criterion is not supported');
    expect($html)->toContain('<strong>1.1.1 Non-text Content.</strong>');
    expect($html)->toContain('Automated checks found 1 issue on 1 page (image-missing-alt).');
    expect($html)->toContain('found 1 issue across 1 page: 1 serious');
    expect($html)->toContain('This is a self-assessment.');
    expect($html)->toContain('not a certification and not a third-party audit');
});

it('is not fully evaluated when nothing failed and nobody assessed the rest', function () {
    reported();

    $html = statement();

    expect($html)->toContain('<strong>Not fully evaluated</strong>');
    expect($html)->toContain('55 of the 55 success criteria have not been evaluated, so no conformance status is claimed for them');
    expect($html)->toContain('found no problems on the pages they read');
    expect($html)->toContain('That is not a claim that none exist');
});

it('is fully conformant only when a person assessed every criterion as supported or not applicable', function () {
    foreach (Wcag::criteria('wcag22aa') as $i => $c) {
        CriterionAssessment::create([
            'site' => null, 'criterion' => $c->number, 'level' => $c->level, 'locked' => true, 'method' => 'manual',
            'status' => $i % 7 === 0 ? CriterionAssessment::NOT_APPLICABLE : CriterionAssessment::SUPPORTS,
            'assessed_by' => 'Reviewer',
        ]);
    }

    reported();

    $html = statement();

    expect($html)->toContain('<strong>Fully conformant</strong>');
    expect($html)->toContain('Every one of the 55 success criteria was assessed as supported or not applicable');
    expect($html)->toContain('manual assessment of 55 criteria by a person');

    // One unlocked "supports" less, and the claim is gone.
    CriterionAssessment::where('criterion', '2.4.3')->update(['status' => CriterionAssessment::NOT_EVALUATED]);
    reported();
    expect(statement())->toContain('<strong>Not fully evaluated</strong>');
});

it('says what the organisation configured, with a per-site override winning', function () {
    config()->set('statamic-a11y-report.statement.organization', 'Hada Farm');
    config()->set('statamic-a11y-report.statement.commitment', 'We want everybody to be able to use this site.');
    config()->set('statamic-a11y-report.statement.feedback', 'Tell us what did not work.');
    config()->set('statamic-a11y-report.statement.contact', ['email' => 'global@example.test', 'phone' => '555 0100']);
    config()->set('statamic-a11y-report.statement.escalation', 'If we do not answer within ten working days, write to the director.');
    config()->set('statamic-a11y-report.statement.sites.default', ['contact' => ['email' => 'site@example.test']]);

    $html = statement();

    expect($html)->toContain('We want everybody to be able to use this site.');
    expect($html)->toContain('Tell us what did not work.');
    expect($html)->toContain('mailto:site@example.test');
    expect($html)->not->toContain('global@example.test');
    expect($html)->toContain('Phone: 555 0100');
    expect($html)->toContain('write to the director');
    expect($html)->not->toContain('Enforcement procedure');
});

it('always has an enforcement section for EN 301 549, and only a configured one for Section 508', function () {
    config()->set('statamic-a11y-report.statement.template', 'en301549');
    $html = statement();
    expect($html)->toContain('Enforcement procedure');
    expect($html)->toContain('has not been named in this statement yet');
    expect($html)->toContain('EN 301 549');

    config()->set('statamic-a11y-report.statement.template', 'section508');
    config()->set('statamic-a11y-report.statement.enforcement', ['name' => 'The Ombudsman', 'url' => 'https://ombudsman.example.test']);
    $html = statement();
    expect($html)->toContain('Enforcement procedure');
    expect($html)->toContain('contact The Ombudsman: <a href="https://ombudsman.example.test">');
    expect($html)->toContain('Section 508');
});

it('is embedded in a page by the tag, one heading level down', function () {
    test()->viewShouldReturnRaw('default', '<html lang="en"><body><h1>{{ title }}</h1>{{ a11y:statement }}</body></html>');
    page('contact', '<p>Reach us.</p>');

    $html = (string) preg_replace('/\s+/', ' ', $this->get('/contact')->assertOk()->getContent());

    expect($html)->toContain('<h1>Contact</h1>');
    expect($html)->toContain('<h2>Accessibility statement for');
    expect($html)->toContain('<h3>Conformance status</h3>');
    expect(substr_count($html, '<h1'))->toBe(1);
});

it('falls back to a plain shell of its own when the site has no layout Statamic can use', function () {
    // The first real site this ran on is a Blade site with @yield layouts
    // and no `layout` view. The statement page returned a 500 there.
    $this->withFakeViews();

    $html = statement();

    expect($html)->toContain('<main id="main-content">');
    expect($html)->toContain('<title>Accessibility statement | ');
    expect($html)->toContain('<h1>Accessibility statement for');
    expect($html)->not->toContain('class="site-layout"');
});

it('uses the layout and the view the config names', function () {
    test()->viewShouldReturnRaw('shell', '<html><body class="custom-shell">{{ template_content }}</body></html>');
    config()->set('statamic-a11y-report.statement.layout', 'shell');
    expect(statement())->toContain('class="custom-shell"');

    test()->viewShouldReturnRaw('mine', '<div class="my-statement">{{ a11y:statement heading="1" }}</div>');
    config()->set('statamic-a11y-report.statement.view', 'mine');
    $html = statement();
    expect($html)->toContain('class="my-statement"');
    expect($html)->toContain('class="custom-shell"');
});

it('is called from a Blade template with the component syntax', function () {
    test()->viewShouldReturnRaw('default', '<html lang="en"><body><h1>{{ $title }}</h1><s:a11y:statement heading="2" /></body></html>', 'blade.php');
    page('contact', '<p>Reach us.</p>');

    $html = (string) preg_replace('/\s+/', ' ', $this->get('/contact')->assertOk()->getContent());

    expect($html)->toContain('<h1>Contact</h1>');
    expect($html)->toContain('<h2>Accessibility statement for');
});

it('never says certified or compliant', function () {
    reported('<img src="/a.jpg">');
    $lower = strtolower(strip_tags(statement()));

    expect(str_contains($lower, 'certified'))->toBeFalse();
    expect(str_contains($lower, 'compliant'))->toBeFalse();
    expect(substr_count($lower, 'certif'))->toBe(substr_count($lower, 'not a certification'));
});

it('derives the status and never lets it be configured', function () {
    expect(StatementBuilder::status(['does_not_support' => 1, 'not_evaluated' => 0]))->toBe('partially_conformant');
    expect(StatementBuilder::status(['partially_supports' => 1]))->toBe('partially_conformant');
    expect(StatementBuilder::status(['not_evaluated' => 3]))->toBe('not_fully_evaluated');
    expect(StatementBuilder::status(['supports' => 50, 'not_applicable' => 5]))->toBe('fully_conformant');

    config()->set('statamic-a11y-report.statement.status', 'fully_conformant');
    reported('<img src="/a.jpg">');
    expect(statement())->toContain('<strong>Partially conformant</strong>');
});

it('says what it knows when a report row has lost its file', function () {
    reported();
    unlink(app(ReportWriter::class)->absolutePath(\Bpmore\A11yReport\Models\Report::first()->json_path));

    $html = statement();

    expect($html)->toContain('<strong>Not fully evaluated</strong>');
    expect($html)->toContain('The detail of the last evaluation is not available');
});

it('refreshes the statement page in the static cache from the command line', function () {
    $this->artisan('statamic:a11y:statement:refresh')
        ->expectsOutputToContain('/accessibility')
        ->assertExitCode(0);
});
