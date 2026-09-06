{{--
    The scope and limits statement. Fixed text, and there is deliberately no
    setting that removes it: a customer may add remarks anywhere else in the
    document, but a report that let an organisation drop this section would
    be a tool for overstating accessibility with this addon's name on it.
    The two lists are computed from the report; the prose is not.
--}}
<section id="scope-and-limits" aria-labelledby="scope-and-limits-heading">
    <h2 id="scope-and-limits-heading">Scope and limits of this report</h2>

    <p><strong>This is a self-assessment.</strong> It was produced by the organisation named on the cover using automated checks and, where marked, its own manual review. It is not a certification and not a third-party audit, and nothing in it should be read as either.</p>

    <p><strong>Automated testing cannot determine conformance for most success criteria.</strong> Published research on automated accessibility testing puts the share of issues it can detect at roughly half by volume, and the share of success criteria it can decide is lower still. Much of WCAG concerns meaning, sequence, and behaviour, which only a person can judge. A criterion that automated checks found nothing against has not been shown to be met.</p>

    <p><strong>What was evaluated automatically.</strong>
        @if ($r['methods']['automated_criteria'] === [])
            No success criterion was evaluated automatically: the engine that read the pages could not be identified, so none of its results are counted here.
        @else
            The automated checks in this report can cite {{ count($r['methods']['automated_criteria']) }} of the {{ count($r['criteria']) }} success criteria, and only parts of each:
            @include('a11y-report::report.criteria-list', ['numbers' => $r['methods']['automated_criteria'], 'standard' => $r['standard']]).
            Where they found failures, the criterion is marked "Does not support". Where they found none, the criterion is left "Not evaluated" with the evidence noted, because the checks do not cover the whole criterion.
        @endif
    </p>

    <p><strong>What was not evaluated.</strong>
        @if ($r['methods']['not_evaluated'] === [])
            Every criterion in the table below carries a determination made by a person.
        @else
            {{ count($r['methods']['not_evaluated']) }} of the {{ count($r['criteria']) }} success criteria are marked "Not evaluated": no automated check covers them, or the checks that do found nothing and no person has assessed them. They are:
            @include('a11y-report::report.criteria-list', ['numbers' => $r['methods']['not_evaluated'], 'standard' => $r['standard']]).
            "Not evaluated" means exactly that. It is not a pass.
        @endif
    </p>

    <p><strong>What was read.</strong>
        @if ($r['scan']['engine'] === \Bpmore\A11yReport\Engine\AxeEngine::KEY)
            The automated checks opened each page in a browser and read the document the site served, with its stylesheets applied, on the pages listed under Evaluation methods. Where a check could not reach a determination it is recorded as such rather than as a pass, and how much of each page was decided is listed under Evaluation methods. A page the browser could not open, or that answered with an error rather than the page, is counted separately and never as clean.
        @else
            The automated checks read the finished pages as the site served them at the time of the scan, on the pages listed under Evaluation methods. They read the markup and cannot see anything a stylesheet decides, colour contrast included. A page the scan could not read is counted separately and never as clean.
        @endif
    </p>
</section>
