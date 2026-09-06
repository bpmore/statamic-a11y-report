{{--
    What was promised about fixing things, how that is going, and what has
    been accepted instead of fixed.

    Everything here is about what the organisation chases. Nothing here
    changes what the report claims: an accepted issue is still a failure, is
    still listed, and is still counted under its success criterion in the
    conformance table. That sentence is in the section itself, because a
    reader who meets an exception register for the first time will otherwise
    wonder exactly that.
--}}
@php
    $rem = $r['remediation'];
    $date = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('j F Y') : null;
    $targeted = array_values(array_filter($rem['targets'], fn ($t) => $t['days'] !== null));
@endphp
<section id="remediation" aria-labelledby="remediation-heading">
    <h2 id="remediation-heading">Remediation</h2>

    <h3>Targets</h3>
    @if ($targeted === [])
        <p>No remediation target has been recorded, so no issue in this report is counted as overdue. A target is how long a problem of a given impact may stay open before the organisation counts itself late.</p>
    @else
        <p>These are the targets that were in force when this report was generated, and they are a commitment by the organisation named on the cover. They are not part of {{ $r['standard_label'] }}, and being inside or outside one changes no determination in the conformance table.</p>
        <table>
            <caption>Remediation targets, and the open issues measured against them</caption>
            <thead>
                <tr>
                    <th scope="col">Impact</th>
                    <th scope="col">Fixed within</th>
                    <th scope="col" class="num">Open</th>
                    <th scope="col" class="num">Past target</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rem['targets'] as $t)
                    <tr>
                        <th scope="row">{{ ucfirst($t['impact']) }}</th>
                        <td>{{ $t['days'] === null ? 'No target' : $t['days'].' '.($t['days'] === 1 ? 'day' : 'days') }}</td>
                        <td class="num">{{ $t['open'] }}</td>
                        <td class="num">{{ $t['days'] === null ? 'n/a' : $t['overdue'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p>
            {{ $rem['overdue_total'] }} of the {{ $rem['open_total'] }} open {{ $rem['open_total'] === 1 ? 'issue is' : 'issues are' }} past target.
            @if ($rem['untargeted_total'] > 0)
                {{ $rem['untargeted_total'] }} {{ $rem['untargeted_total'] === 1 ? 'issue has' : 'issues have' }} no target and are counted in neither column.
            @endif
            @if ($rem['oldest_open_days'] !== null)
                The longest-standing open issue has been open for {{ $rem['oldest_open_days'] }} {{ $rem['oldest_open_days'] === 1 ? 'day' : 'days' }}.
            @endif
        </p>
    @endif

    <h3>Accepted issues</h3>
    <p>An accepted issue is one the organisation has decided not to fix, with the reason recorded here. <strong>Accepting an issue does not change what was found.</strong> It is still a failure, it is still counted under its success criterion in the conformance table above, and it is listed below rather than left out. Every acceptance carries a date it runs out on, after which it counts as open again until somebody looks at it.</p>
    @if ($rem['exceptions'] === [])
        <p>No issue in this report has been accepted.</p>
    @else
        @if ($rem['exceptions_omitted'] > 0)
            <p>{{ count($rem['exceptions']) }} of {{ count($rem['exceptions']) + $rem['exceptions_omitted'] }} accepted issues are listed here, the ones whose acceptances run out soonest. The rest are held in the control panel and are still counted under their success criteria above.</p>
        @endif
        <table>
            <caption>Issues accepted rather than fixed</caption>
            <thead>
                <tr>
                    <th scope="col">Page</th>
                    <th scope="col">Criterion or check</th>
                    <th scope="col">Reason it was accepted</th>
                    <th scope="col">Accepted by</th>
                    <th scope="col">Runs out</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rem['exceptions'] as $e)
                    <tr>
                        <th scope="row">{{ $e['path'] }}{{ $r['subject']['site'] === null && count($r['subject']['sites']) > 1 ? ' ('.$e['site'].')' : '' }}</th>
                        <td>{{ $e['label'] }}</td>
                        <td>{{ $e['reason'] ?: 'No reason was recorded.' }}</td>
                        <td>{{ $e['accepted_by'] ?: 'Not recorded' }}@if ($date($e['accepted_at'])), {{ $date($e['accepted_at']) }}@endif</td>
                        <td>{{ $date($e['expires_at']) ?: 'No date recorded' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($rem['expired_exceptions'] !== [])
        <h3>Acceptances that have run out</h3>
        @php($listed = count($rem['expired_exceptions']))
        @php($ran = $listed + $rem['expired_exceptions_omitted'])
        @if ($ran === 1)
            <p>One issue was accepted, and that acceptance has passed the date it was accepted until. It is counted as open in this report and is listed under Open issues above, because the decision was to accept it until a date that has gone.</p>
        @else
            <p>{{ $ran }} issues were accepted, and those acceptances have passed the dates they were accepted until. They are counted as open in this report and are listed under Open issues above, because the decision was to accept them until dates that have gone.</p>
        @endif
        @if ($rem['expired_exceptions_omitted'] > 0)
            <p>{{ $listed }} of them are listed here, the ones that ran out earliest.</p>
        @endif
        <table>
            <caption>Acceptances that have run out</caption>
            <thead>
                <tr>
                    <th scope="col">Page</th>
                    <th scope="col">Criterion or check</th>
                    <th scope="col">Reason it was accepted</th>
                    <th scope="col">Accepted by</th>
                    <th scope="col">Ran out</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rem['expired_exceptions'] as $e)
                    <tr>
                        <th scope="row">{{ $e['path'] }}{{ $r['subject']['site'] === null && count($r['subject']['sites']) > 1 ? ' ('.$e['site'].')' : '' }}</th>
                        <td>{{ $e['label'] }}</td>
                        <td>{{ $e['reason'] ?: 'No reason was recorded.' }}</td>
                        <td>{{ $e['accepted_by'] ?: 'Not recorded' }}</td>
                        <td>{{ $date($e['expires_at']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h3>The plan</h3>
    @if ($rem['plan'])
        <p>{{ $rem['plan'] }}</p>
    @else
        <p>No remediation plan has been recorded for this report.</p>
    @endif
</section>
