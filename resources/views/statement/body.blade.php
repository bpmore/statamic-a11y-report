{{--
    The accessibility statement. Front-end HTML rendered by Blade and handed
    back through the Antlers tag, so it wears the site's own layout.

    The order follows the EU model statement for EN 301 549, which a Section
    508 statement also satisfies: status, known problems, how to complain,
    how the statement was made. The two templates differ in wording and in
    whether an enforcement section is always present. Every factual sentence
    is derived from the latest report; the organisation's own words come
    from config and are printed as given.
--}}
@php
    $r = $s['report'];
    $hh = 'h'.$h;
    $sub = 'h'.min(6, $h + 1);
    $org = $s['organization'] ?: 'This organisation';
@endphp
<article class="a11y-statement" lang="{{ $s['site']['lang'] ?? 'en' }}">
    <{{ $hh }}>Accessibility statement for {{ $s['site']['name'] }}</{{ $hh }}>

    <section class="a11y-statement__commitment">
        <p>
            @if ($s['commitment'])
                {{ $s['commitment'] }}
            @else
                {{ $org }} is committed to making this website accessible to as many people as possible, and to being open about how far along that work is.
            @endif
        </p>
        @if ($s['template'] === 'en301549')
            <p>This statement applies to {{ $s['site']['url'] ?: 'this website' }} and is made in the form set out for websites covered by EN 301 549.</p>
        @else
            <p>This statement applies to {{ $s['site']['url'] ?: 'this website' }}. The standard referred to is the Web Content Accessibility Guidelines, which Section 508 of the Rehabilitation Act incorporates.</p>
        @endif
    </section>

    <section class="a11y-statement__status">
        <{{ $sub }}>Conformance status</{{ $sub }}>
        @if ($r === null)
            <p><strong>Not yet evaluated.</strong> No accessibility evaluation of this website has been recorded yet, so no conformance status is claimed.</p>
        @else
            <p>
                <strong>{{ \Bpmore\A11yReport\Statement\StatementBuilder::statusLabel($r['status']) }}</strong> with {{ $r['standard_label'] }}, as of {{ \Bpmore\A11yReport\Statement\StatementBuilder::date($r['evaluated_at']) }}.
                @if ($r['status'] === 'partially_conformant')
                    Some parts of the content do not fully conform: {{ count($r['does_not_support']) }} {{ count($r['does_not_support']) === 1 ? 'success criterion is' : 'success criteria are' }} not supported{{ count($r['partially_supports']) > 0 ? ' and '.count($r['partially_supports']).' '.(count($r['partially_supports']) === 1 ? 'is' : 'are').' partly supported' : '' }}. They are listed below.
                @elseif ($r['status'] === 'not_fully_evaluated')
                    @if ($r['detail_missing'])
                        The detail of the last evaluation is not available, so no conformance status is claimed.
                    @else
                        {{ $r['counts']['not_evaluated'] }} of the {{ $r['criteria_total'] }} success criteria have not been evaluated, so no conformance status is claimed for them. No failures were found under the criteria that were evaluated.
                    @endif
                @else
                    Every one of the {{ $r['criteria_total'] }} success criteria was assessed as supported or not applicable.
                @endif
            </p>
            <p>This is a self-assessment. It combines automated checks of {{ $r['pages_read'] }} {{ $r['pages_read'] === 1 ? 'page' : 'pages' }} with manual assessment of {{ $r['assessed_by_people'] }} {{ $r['assessed_by_people'] === 1 ? 'criterion' : 'criteria' }} by a person. Automated checks cannot decide most success criteria, and a criterion they found nothing against has not been shown to be met.</p>
        @endif
    </section>

    <section class="a11y-statement__known-issues">
        <{{ $sub }}>Non-accessible content and known problems</{{ $sub }}>
        @if ($r === null)
            <p>None recorded, because no evaluation has been recorded.</p>
        @elseif ($r['does_not_support'] === [] && $r['partially_supports'] === [] && $r['issues_total'] === 0 && $r['outside_wcag'] === [])
            <p>The automated checks found no problems on the pages they read, and no criterion has been assessed as unsupported. That is not a claim that none exist: see the conformance status above for what was not evaluated.</p>
        @else
            @if ($r['does_not_support'] !== [])
                <p>The following success criteria are not supported:</p>
                <ul>
                    @foreach ($r['does_not_support'] as $c)
                        <li><strong>{{ $c['number'] }} {{ $c['name'] }}.</strong> {{ $c['remarks'] !== '' ? $c['remarks'] : $c['evidence'] }}</li>
                    @endforeach
                </ul>
            @endif
            @if ($r['partially_supports'] !== [])
                <p>The following success criteria are partly supported:</p>
                <ul>
                    @foreach ($r['partially_supports'] as $c)
                        <li><strong>{{ $c['number'] }} {{ $c['name'] }}.</strong> {{ $c['remarks'] !== '' ? $c['remarks'] : $c['evidence'] }}</li>
                    @endforeach
                </ul>
            @endif
            @if ($r['issues_total'] > 0)
                <p>The last automated scan found {{ $r['issues_total'] }} {{ $r['issues_total'] === 1 ? 'issue' : 'issues' }} across {{ $r['pages_read'] }} {{ $r['pages_read'] === 1 ? 'page' : 'pages' }}: {{ collect($r['by_impact'])->filter()->map(fn ($n, $impact) => $n.' '.$impact)->implode(', ') ?: 'none rated' }}.</p>
            @endif
            @if ($r['outside_wcag'] !== [])
                <p>Checks outside WCAG also reported: {{ collect($r['outside_wcag'])->map(fn ($o) => $o['label'].' ('.$o['issues'].' on '.$o['pages'].' '.($o['pages'] === 1 ? 'page' : 'pages').')')->implode(', ') }}.</p>
            @endif
        @endif
    </section>

    <section class="a11y-statement__feedback">
        <{{ $sub }}>Feedback and contact</{{ $sub }}>
        <p>
            @if ($s['feedback'])
                {{ $s['feedback'] }}
            @else
                If you find something on this website you cannot use, or need content in another format, please tell us. We want to know, and we will respond.
            @endif
        </p>
        @if ($s['contact'] !== [])
            <ul>
                @if (!empty($s['contact']['email']))<li>Email: <a href="mailto:{{ $s['contact']['email'] }}">{{ $s['contact']['email'] }}</a></li>@endif
                @if (!empty($s['contact']['phone']))<li>Phone: {{ $s['contact']['phone'] }}</li>@endif
                @if (!empty($s['contact']['url']))<li>Online: <a href="{{ $s['contact']['url'] }}">{{ $s['contact']['url'] }}</a></li>@endif
                @if (!empty($s['contact']['address']))<li>Post: {{ $s['contact']['address'] }}</li>@endif
            </ul>
        @else
            <p>No contact details have been published for accessibility feedback yet.</p>
        @endif
        @if ($s['escalation'])
            <p>{{ $s['escalation'] }}</p>
        @endif
    </section>

    @if ($s['template'] === 'en301549' || $s['enforcement'] !== [])
        <section class="a11y-statement__enforcement">
            <{{ $sub }}>Enforcement procedure</{{ $sub }}>
            @if ($s['enforcement'] !== [])
                <p>If you are not satisfied with the response to your feedback, you can contact {{ $s['enforcement']['name'] ?? 'the enforcement body' }}@if (!empty($s['enforcement']['url'])): <a href="{{ $s['enforcement']['url'] }}">{{ $s['enforcement']['url'] }}</a>@endif.</p>
            @else
                <p>The enforcement body for this website has not been named in this statement yet.</p>
            @endif
        </section>
    @endif

    <section class="a11y-statement__preparation">
        <{{ $sub }}>Preparation of this statement</{{ $sub }}>
        <p>
            This statement was generated on {{ \Bpmore\A11yReport\Statement\StatementBuilder::date($s['statement_date']) }}.
            @if ($r !== null)
                It is based on a self-assessment recorded on {{ \Bpmore\A11yReport\Statement\StatementBuilder::date($r['generated_at']) }} (report {{ $r['uuid'] }}), which used automated checks ({{ $r['engine'] }}) on {{ $r['pages_read'] }} of {{ $r['pages_total'] }} {{ $r['pages_total'] === 1 ? 'page' : 'pages' }} and manual assessment by a person where noted. It is not a certification and not a third-party audit.
            @else
                No self-assessment has been recorded yet.
            @endif
        </p>
    </section>
</article>
