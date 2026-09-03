{{--
    The conformance document, as a standalone HTML page.

    Semantic on purpose and not for tidiness: this file is also the source of
    the tagged PDF, and Chrome's tag quality is entirely a function of the
    HTML. Real tables with captions and scoped headers, one h1, sections with
    labelled headings, a language on the root. No external resources at all,
    because headless Chrome will not fetch them for print.
--}}
<!DOCTYPE html>
<html lang="{{ $r['lang'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $r['title'] }}: {{ $r['subject']['name'] }}, {{ \Illuminate\Support\Carbon::parse($r['generated_at'])->format('j F Y') }}</title>
    <style>
        :root { color-scheme: light; }
        html { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; line-height: 1.5; color: #1a1a1a; background: #fff; }
        body { margin: 0 auto; max-width: 60em; padding: 2em 1.5em; }
        h1 { font-size: 1.9em; margin: 0 0 0.25em; }
        h2 { font-size: 1.35em; margin: 2em 0 0.5em; border-bottom: 1px solid #ccc; padding-bottom: 0.2em; }
        h3 { font-size: 1.05em; margin: 1.5em 0 0.4em; }
        p { margin: 0.6em 0; }
        dl.cover { display: grid; grid-template-columns: max-content 1fr; gap: 0.25em 1em; margin: 1em 0; }
        dl.cover dt { font-weight: 600; }
        dl.cover dd { margin: 0; }
        table { border-collapse: collapse; width: 100%; margin: 0.75em 0 1.5em; font-size: 0.95em; }
        caption { text-align: left; font-weight: 600; margin-bottom: 0.4em; caption-side: top; }
        th, td { border: 1px solid #bbb; padding: 0.4em 0.6em; text-align: left; vertical-align: top; }
        th { background: #f2f2f2; }
        th[scope="row"] { font-weight: 600; white-space: nowrap; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .status { font-weight: 600; white-space: nowrap; }
        .status-does_not_support { color: #8a1c1c; }
        .status-supports { color: #1c5e2e; }
        .status-partially_supports { color: #7a4d00; }
        .evidence { color: #444; font-size: 0.92em; }
        .notice { border: 1px solid #999; padding: 0.75em 1em; background: #fafafa; }
        /* Links go to the W3C's own text for each criterion. Dark enough to
           pass 1.4.3 on white, underlined so colour is not the only cue. */
        a { color: #1a4d8a; text-decoration: underline; }
        /* The customer's mark, at about the size of a letterhead. Embedded
           as a data URI: headless Chrome fetches nothing at all for print. */
        img.mark { display: block; max-height: 18mm; max-width: 70mm; width: auto; margin: 0 0 1.2em; }
@if (! empty($r['brand']['accent']))
        /* The customer's heading colour. Text only, never a fill or a rule:
           Chrome prints those as untagged paths, which PDF/UA reads as
           content nobody can reach, which is why the print rules below drop
           the borders. This value reached here only by passing a contrast
           check against the white page, so it cannot make a heading
           unreadable. */
        h1, h2 { color: {{ $r['brand']['accent'] }}; }
@endif
        footer { margin-top: 3em; border-top: 1px solid #ccc; padding-top: 1em; font-size: 0.9em; color: #444; }
        @page { margin: 18mm; }
        /* No CSS borders on print except in tables. Chrome prints a border
           as an untagged path, which PDF/UA reads as content nobody can
           reach; table borders it marks as artifacts, the rest it does not. */
        @media print {
            /* The paper is white. A background fill is the first thing drawn on
               every page, as an untagged path, and PDF/UA reads it as content. */
            html, body { background: none; }
            body { padding: 0; max-width: none; }
            h2 { break-after: avoid; border-bottom: 0; }
            tr { break-inside: avoid; }
            .notice { border: 0; padding: 0; background: none; font-weight: 600; }
            footer { border-top: 0; }
        }
    </style>
</head>
<body>
<main>
    <header id="cover">
@if (! empty($r['brand']['logo']))
        <img class="mark" src="{{ $r['brand']['logo']['data_uri'] }}" alt="{{ $r['brand']['logo']['alt'] }}">
@endif
        <h1>{{ $r['title'] }}</h1>
        <p class="notice">A self-assessment against <a href="{{ $r['standard_url'] }}">{{ $r['standard_label'] }}</a>. It is not a certification and not a third-party audit. Read the section called Scope and limits before reading the conformance table.</p>
        <dl class="cover">
            <dt>Site</dt>
            <dd>{{ $r['subject']['name'] }}@if ($r['subject']['url'] !== ''), {{ $r['subject']['url'] }}@endif</dd>
            <dt>Standard</dt>
            <dd><a href="{{ $r['standard_url'] }}">{{ $r['standard_label'] }}</a></dd>
            <dt>Report date</dt>
            <dd>{{ \Illuminate\Support\Carbon::parse($r['generated_at'])->format('j F Y') }}</dd>
            <dt>Evaluation period</dt>
            <dd>
                @if ($r['scan']['started_at'] && $r['scan']['finished_at'])
                    {{ \Illuminate\Support\Carbon::parse($r['scan']['started_at'])->format('j F Y, H:i') }} to {{ \Illuminate\Support\Carbon::parse($r['scan']['finished_at'])->format('j F Y, H:i') }}
                @else
                    Scan {{ $r['scan']['uuid'] }}
                @endif
            </dd>
            <dt>Evaluator</dt>
            <dd>
                {{ $r['evaluator']['name'] ?: 'Not named' }}@if ($r['evaluator']['organization']), {{ $r['evaluator']['organization'] }}@endif
            </dd>
            <dt>Contact</dt>
            <dd>{{ $r['evaluator']['email'] ?: 'Not given' }}</dd>
            <dt>Generated by</dt>
            <dd>{{ $r['generated_by'] ?: 'Unknown' }}, using {{ $r['generator'] }}</dd>
            <dt>Report id</dt>
            <dd>{{ $r['uuid'] }}</dd>
        </dl>
    </header>

    <section id="methods" aria-labelledby="methods-heading">
        <h2 id="methods-heading">Evaluation methods</h2>
        <dl class="cover">
            <dt>Automated engine</dt>
            <dd>{{ $r['scan']['engine'] }} {{ $r['scan']['engine_version'] }}, ruleset {{ $r['scan']['ruleset'] }}</dd>
            <dt>Pages selected</dt>
            <dd>{{ $r['scan']['scope_text'] }}</dd>
            <dt>Pages read</dt>
            <dd>{{ $r['scan']['pages_scanned'] }} of {{ $r['scan']['pages_total'] }}{{ $r['scan']['pages_errored'] > 0 ? '; '.$r['scan']['pages_errored'].' could not be read and are not counted as clean' : '' }}{{ $r['scan']['pages_skipped'] > 0 ? '; '.$r['scan']['pages_skipped'].' had no page of their own' : '' }}</dd>
            <dt>Scan</dt>
            <dd>{{ $r['scan']['uuid'] }}, started {{ $r['scan']['trigger'] === 'ci' ? 'from a deploy pipeline' : ($r['scan']['trigger'] === 'scheduled' ? 'on schedule' : 'by hand') }}</dd>
            <dt>Automated</dt>
            <dd>{{ count($r['methods']['automated_criteria']) }} success criteria have automated checks covering part of them</dd>
            <dt>Assessed by a person</dt>
            <dd>{{ count($r['methods']['assessed_by_people']) }} success criteria carry a locked manual assessment</dd>
        </dl>
        @if ($r['coverage']['summaries'] !== [])
            <table>
                <caption>How much of each page the automated checks could see</caption>
                <thead><tr><th scope="col">Coverage</th><th scope="col" class="num">Pages</th></tr></thead>
                <tbody>
                    @foreach ($r['coverage']['summaries'] as $row)
                        <tr><td>{{ $row['summary'] }}</td><td class="num">{{ $row['pages'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    @include('a11y-report::report.limits', ['r' => $r])

    <section id="conformance" aria-labelledby="conformance-heading">
        <h2 id="conformance-heading">Conformance table</h2>
        <p>Every Level A and AA success criterion in {{ $r['standard_label'] }}. "Not evaluated" is the default and means no determination was made. Each criterion links to the W3C's explanation of what it requires.</p>
        <table>
            <caption>{{ $r['standard_label'] }} success criteria</caption>
            <thead>
                <tr>
                    <th scope="col">Criterion</th>
                    <th scope="col">Level</th>
                    <th scope="col">Result</th>
                    <th scope="col">Method</th>
                    <th scope="col">Remarks and evidence</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($r['criteria'] as $c)
                    <tr>
                        <th scope="row">@if (! empty($c['url']))<a href="{{ $c['url'] }}">{{ $c['number'] }} {{ $c['name'] }}</a>@else{{ $c['number'] }} {{ $c['name'] }}@endif</th>
                        <td>{{ $c['level'] }}</td>
                        <td class="status status-{{ $c['status'] }}">{{ \Bpmore\A11yReport\Document\AssessmentMerger::label($c['status']) }}</td>
                        <td>{{ $c['locked'] ? ucfirst($c['method']).', locked' : ucfirst($c['method']) }}</td>
                        <td>
                            @if ($c['remarks'] !== '')<p>{{ $c['remarks'] }}</p>@endif
                            <p class="evidence">{{ $c['evidence'] }}</p>
                            @if ($c['assessed_by'])<p class="evidence">Assessed by {{ $c['assessed_by'] }}{{ $c['assessed_at'] ? ' on '.\Illuminate\Support\Carbon::parse($c['assessed_at'])->format('j F Y') : '' }}.</p>@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <section id="findings" aria-labelledby="findings-heading">
        <h2 id="findings-heading">Findings summary</h2>
        <p>{{ $r['summary']['issues_total'] }} {{ $r['summary']['issues_total'] === 1 ? 'issue was' : 'issues were' }} found by the automated checks across {{ $r['scan']['pages_scanned'] }} {{ $r['scan']['pages_scanned'] === 1 ? 'page' : 'pages' }}. An issue is one problem on one page, however many times it occurs there.</p>
        <table>
            <caption>Issues by impact</caption>
            <thead><tr><th scope="col">Impact</th><th scope="col" class="num">Issues</th></tr></thead>
            <tbody>
                @foreach ($r['summary']['by_impact'] as $impact => $count)
                    <tr><th scope="row">{{ ucfirst($impact) }}</th><td class="num">{{ $count }}</td></tr>
                @endforeach
            </tbody>
        </table>
        @if ($r['summary']['by_criterion'] !== [])
            <table>
                <caption>Issues by success criterion</caption>
                <thead><tr><th scope="col">Criterion</th><th scope="col" class="num">Issues</th><th scope="col" class="num">Pages</th></tr></thead>
                <tbody>
                    @foreach ($r['summary']['by_criterion'] as $row)
                        <tr><th scope="row">@if (! empty($row['url']))<a href="{{ $row['url'] }}">{{ $row['number'] }} {{ $row['name'] }}</a>@else{{ $row['number'] }} {{ $row['name'] }}@endif</th><td class="num">{{ $row['issues'] }}</td><td class="num">{{ $row['pages'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
        @if ($r['summary']['outside_wcag'] !== [])
            <table>
                <caption>Findings outside WCAG</caption>
                <thead><tr><th scope="col">Check</th><th scope="col" class="num">Issues</th><th scope="col" class="num">Pages</th></tr></thead>
                <tbody>
                    @foreach ($r['summary']['outside_wcag'] as $row)
                        <tr><th scope="row">{{ $row['label'] }}</th><td class="num">{{ $row['issues'] }}</td><td class="num">{{ $row['pages'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
            <p class="evidence">These checks are house rules the automated engine applies. No success criterion covers them, and they are listed apart so they cannot be read as one.</p>
        @endif
        @if (is_array($r['summary']['diff']) && ($r['summary']['diff']['previous_scan_id'] ?? null) !== null)
            <p>Against the scan before this one: {{ $r['summary']['diff']['new'] }} new, {{ $r['summary']['diff']['fixed'] }} fixed, {{ $r['summary']['diff']['unchanged'] }} unchanged.</p>
        @endif
        @if ($r['summary']['previous_report'] !== null)
            <p>The previous report, {{ $r['summary']['previous_report']['uuid'] }}@if ($r['summary']['previous_report']['generated_at']) of {{ \Illuminate\Support\Carbon::parse($r['summary']['previous_report']['generated_at'])->format('j F Y') }}@endif, recorded {{ $r['summary']['previous_report']['issues_total'] }} {{ $r['summary']['previous_report']['issues_total'] === 1 ? 'issue' : 'issues' }}.</p>
        @endif
    </section>

    <section id="open-issues" aria-labelledby="open-issues-heading">
        <h2 id="open-issues-heading">Open issues</h2>
        @if ($r['issues'] === [])
            <p>No issue from this scan is open. An issue is open until a scan no longer finds it on a page it has read, or a person marks it as one that will not be fixed or was a false positive.</p>
        @else
            <p>{{ count($r['issues']) }} open {{ count($r['issues']) === 1 ? 'issue' : 'issues' }} from this scan{{ $r['issues_omitted'] > 0 ? ', and '.$r['issues_omitted'].' more not listed here' : '' }}. Ordered by impact, then by page.</p>
            <table>
                <caption>Open issues from this scan</caption>
                <thead>
                    <tr>
                        <th scope="col">Page</th>
                        <th scope="col">Criterion or check</th>
                        <th scope="col">Impact</th>
                        <th scope="col">Description</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($r['issues'] as $i)
                        <tr>
                            <td>{{ $i['path'] }}{{ $r['subject']['site'] === null && count($r['subject']['sites']) > 1 ? ' ('.$i['site'].')' : '' }}</td>
                            <td>@if (($u = \Bpmore\A11yReport\Document\Wcag::urlFor((string) (($i['criteria'] ?? [])[0] ?? ''), $r['standard'])) !== null)<a href="{{ $u }}">{{ $i['label'] }}</a>@else{{ $i['label'] }}@endif</td>
                            <td>{{ ucfirst($i['impact']) }}</td>
                            <td>{{ $i['message'] }}@if ($i['target']) <span class="evidence">({{ $i['target'] }}{{ $i['occurrences'] > 1 ? ', '.$i['occurrences'].' times' : '' }})</span>@endif</td>
                            <td>{{ str_replace('_', ' ', (string) $i['state']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section id="remediation" aria-labelledby="remediation-heading">
        <h2 id="remediation-heading">Remediation plan</h2>
        @if ($r['remediation_plan'])
            <p>{{ $r['remediation_plan'] }}</p>
        @else
            <p>No remediation plan has been recorded for this report.</p>
        @endif
    </section>

    <footer>
        <p>Generated {{ \Illuminate\Support\Carbon::parse($r['generated_at'])->format('j F Y, H:i T') }} by {{ $r['generated_by'] ?: 'an unknown user' }} from scan {{ $r['scan']['uuid'] }}, using {{ $r['generator'] }}. This document is a self-assessment. A page the automated checks found nothing wrong with has not been proven accessible.</p>
    </footer>
</main>
</body>
</html>
