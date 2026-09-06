{{--
    The report's home under Tools: what is open now, the last scan, the trend,
    and every scan so far.

    Compiled as a Vue template, not printed as HTML (see the gate's utility
    view for the full reasoning). Two consequences: Statamic's `ui-*`
    components resolve here, and any value that could contain a brace goes
    through `@plain` rather than `{{ }}`. Numbers and dates are safe as they
    are.
--}}

<ui-header title="Accessibility Report" icon="pulse" />

<div class="space-y-6">

    @include('a11y-report::utilities.nav', ['active' => 'overview'])

@if (! $installed)
    <ui-card-panel heading="Not set up yet">
        <div class="space-y-3">
            @if ($ownsConnection)
                <p>The report keeps its scans in a database file of its own. Nothing exists until the first scan runs, and the first scan creates it.</p>
            @else
                <p>The report's tables are not on the <code>@plain($connection)</code> connection yet. Somebody with access to that database runs this once:</p>
                <pre class="text-sm">php please a11y:report:install</pre>
            @endif
            @if ($canRun && $ownsConnection)
                <form method="post" action="@plain($runUrl)">
                    @csrf
                    <ui-button type="submit" variant="primary" text="Run the first scan" />
                </form>
            @endif
        </div>
    </ui-card-panel>
@else

    @if (count($sites) > 1)
        <div class="flex flex-wrap items-center gap-2">
            <ui-button size="sm" href="@plain(cp_route('utilities.index').'/a11y-report')" text="All sites" @if ($site === null) variant="primary" @endif />
            @foreach ($sites as $s)
                <ui-button size="sm" href="@plain(cp_route('utilities.index').'/a11y-report?site='.$s['handle'])" text="@plain($s['name'])" @if ($site === $s['handle']) variant="primary" @endif />
            @endforeach
        </div>
    @endif

    @if ($schedule && ($schedule['last'] === null || $schedule['missed']))
        <ui-card-panel heading="{{ $schedule['last'] === null ? 'No scan has ever run on schedule' : 'Scheduled scans have stopped' }}">
            <div class="space-y-2">
                @if ($schedule['last'] === null)
                    <p>The config file asks for a <strong>@plain($schedule['label'])</strong> scan, and none has run. A schedule nobody runs looks exactly like one that runs and finds nothing, so this says it out loud rather than letting a report carry a date that quietly stopped moving.</p>
                @else
                    <p>The config file asks for a <strong>@plain($schedule['label'])</strong> scan. The last one ran {{ $schedule['last']->created_at->diffForHumans() }} and at least two runs have been missed since.</p>
                @endif
                <p>Laravel's scheduler has to be running on the server for any of it to happen. One line in the crontab, which covers every scheduled task the site has and not only this one:</p>
                <pre class="text-sm">* * * * * cd @plain(base_path()) &amp;&amp; php artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</pre>
                <p>To check what is registered: <code>php artisan schedule:list</code>. To stop asking for a scheduled scan, set <code>scan.schedule</code> to <code>false</code> in <code>config/statamic-a11y-report.php</code>.</p>
            </div>
        </ui-card-panel>
    @endif

    @if ($stale)
        <ui-card-panel heading="This scan is not moving">
            <div class="space-y-2">
                <p>
                    Scan <code>@plain($stale['scan']->uuid)</code> has been <strong>@plain($stale['scan']->status)</strong> for {{ $stale['minutes'] }} {{ $stale['minutes'] === 1 ? 'minute' : 'minutes' }}
                    with {{ $stale['pages_read'] }} of {{ $stale['scan']->pages_total }} pages read{{ $stale['pages_read'] === 0 ? ' and nothing read at all' : '' }}.
                </p>
                <p>
                    Scans run as jobs on the <code>@plain($queueConnection)</code> queue connection. The usual cause is that no worker is processing that connection: a site running Horizon, for example, works the Redis queue and not the database one. Either run a worker for it:
                </p>
                <pre class="text-sm">php artisan queue:work @plain($queueConnection)</pre>
                <p>Or finish this scan in one process from the command line, which needs no worker:</p>
                <pre class="text-sm">php please a11y:scan --resume=@plain($stale['scan']->uuid) --sync</pre>
                <p>Until it reaches an end, every scheduled scan is skipped: one is never started while another is queued or running.@if ($stale['others'] > 0) {{ $stale['others'] }} other {{ $stale['others'] === 1 ? 'scan has' : 'scans have' }} not finished either, and this is the oldest.@endif</p>
                <p>Or, where the pages cannot be read at all, end it. A scan that has been ended can never be reported on, and what it read is kept:</p>
                <pre class="text-sm">php please a11y:scan:cancel @plain($stale['scan']->uuid)</pre>
            </div>
        </ui-card-panel>
    @endif

    <div class="grid gap-6 md:grid-cols-2">

        <ui-card-panel heading="Open now">
            <div class="space-y-4">
                <div>
                    <div class="text-4xl font-semibold">{{ $openTotal }}</div>
                    <ui-description text="open {{ $openTotal === 1 ? 'issue' : 'issues' }}{{ $oldestOpenDays === null || $openTotal === 0 ? '' : ($oldestOpenDays === 0 ? ', the oldest opened today' : ', the oldest open for '.$oldestOpenDays.' '.($oldestOpenDays === 1 ? 'day' : 'days')) }}" />
                </div>
                <div class="flex flex-wrap gap-2">
                    @foreach ($byImpact as $impact => $count)
                        <ui-badge color="{{ ['critical' => 'red', 'serious' => 'amber', 'moderate' => 'blue', 'minor' => 'default'][$impact] }}" text="{{ $count }} {{ $impact }}" pill />
                    @endforeach
                </div>
                @if ($overdueTotal > 0 || $expiredExceptions > 0)
                    <div class="flex flex-wrap gap-2">
                        @if ($overdueTotal > 0)
                            <ui-badge color="red" text="{{ $overdueTotal }} past target" />
                        @endif
                        @if ($expiredExceptions > 0)
                            <ui-badge color="amber" text="{{ $expiredExceptions }} {{ $expiredExceptions === 1 ? 'acceptance has' : 'acceptances have' }} run out" />
                        @endif
                    </div>
                @endif
                <ui-description text="Counted from the last scan that read each page. A problem marked as a false positive is not open, and nor is an accepted one until the date it was accepted until has gone." />
                <ui-button size="sm" href="@plain($indexUrl)" text="Open the issue queue" />
            </div>
        </ui-card-panel>

        <ui-card-panel heading="Last scan">
            <div class="space-y-4">
                @if ($latest === null)
                    <p>No scan has run{{ $site !== null ? ' for this site' : '' }}.</p>
                @else
                    <div class="flex items-center gap-2">
                        <ui-badge color="{{ ['complete' => 'emerald', 'running' => 'blue', 'queued' => 'blue', 'failed' => 'red', 'cancelled' => 'default'][$latest->status] ?? 'default' }}" text="@plain($latest->status)" />
                        <span>{{ ($latest->finished_at ?? $latest->started_at ?? $latest->created_at)->diffForHumans() }}</span>
                    </div>
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        @if ($schedule && $schedule['last'] !== null && ! $schedule['missed'])
                            <dt class="opacity-70">Next scheduled</dt><dd>@plain($schedule['label']), next due {{ \Illuminate\Support\Carbon::instance($schedule['next'])->diffForHumans() }}</dd>
                        @endif
                        <dt class="opacity-70">Pages read</dt><dd>{{ $latest->pages_scanned }} of {{ $latest->pages_total }}</dd>
                        <dt class="opacity-70">Could not be read</dt><dd>{{ $latest->pages_errored }}</dd>
                        <dt class="opacity-70">Issues found</dt><dd>{{ $latest->issues_total }}</dd>
                        <dt class="opacity-70">Engine</dt><dd>@plain($latest->engine) @plain($latest->engine_version)</dd>
                        @if (is_array($latest->diff) && ($latest->diff['previous_scan_id'] ?? null) !== null)
                            <dt class="opacity-70">Against the scan before</dt><dd>{{ $latest->diff['new'] }} new, {{ $latest->diff['fixed'] }} fixed, {{ $latest->diff['unchanged'] }} unchanged</dd>
                        @endif
                        @if ($latest->error)
                            <dt class="opacity-70">Problem</dt><dd>@plain($latest->error)</dd>
                        @endif
                    </dl>
                @endif
                @if ($canRun)
                    <form method="post" action="@plain($runUrl)">
                        @csrf
                        @if ($site !== null)
                            <input type="hidden" name="site" value="@plain($site)">
                        @endif
                        <ui-button type="submit" variant="primary" text="Run a scan now" />
                    </form>
                    @if ($queueIsSync)
                        <ui-description text="This site's queue is set to run jobs in the request, so a scan runs while this page waits. On a large site that can take minutes. A queue worker would take it off the page." />
                    @else
                        <ui-description text="Scans run on the queue. Reload to watch one go." />
                    @endif
                @endif
            </div>
        </ui-card-panel>

    </div>

    <ui-card-panel heading="Issues found, last 90 days">
        @if ($chart === '')
            <p>No scan has completed in the last 90 days{{ $site !== null ? ' for this site' : '' }}.</p>
        @else
            <div class="text-gray-800 dark:text-gray-200">{!! $chart !!}</div>
            <ui-description text="One point per completed scan, at the time it finished. Every point is in the table below." />
        @endif
    </ui-card-panel>

    <ui-card-panel heading="Scan history">
        @if ($history->isEmpty())
            <p>Nothing yet.</p>
        @else
            <ui-table>
                <ui-table-columns>
                    <ui-table-column>When</ui-table-column>
                    <ui-table-column>Status</ui-table-column>
                    <ui-table-column>Started by</ui-table-column>
                    <ui-table-column class="text-right">Pages read</ui-table-column>
                    <ui-table-column class="text-right">Not read</ui-table-column>
                    <ui-table-column class="text-right">Issues</ui-table-column>
                    <ui-table-column>By impact</ui-table-column>
                    <ui-table-column>Change</ui-table-column>
                    <ui-table-column>Engine</ui-table-column>
                </ui-table-columns>
                <ui-table-rows>
                    @foreach ($history as $scan)
                        <ui-table-row>
                            <ui-table-cell>
                                <span title="{{ ($scan->finished_at ?? $scan->created_at)->toDayDateTimeString() }}">{{ ($scan->finished_at ?? $scan->created_at)->diffForHumans() }}</span>
                                @if ($scan->site === null && count($sites) > 1)<ui-description text="all sites" />@endif
                            </ui-table-cell>
                            <ui-table-cell>@plain($scan->status)</ui-table-cell>
                            <ui-table-cell>@plain($scan->trigger . ($scan->initiated_by ? ', '.$scan->initiated_by : ''))</ui-table-cell>
                            <ui-table-cell class="text-right">{{ $scan->pages_scanned }} / {{ $scan->pages_total }}</ui-table-cell>
                            <ui-table-cell class="text-right">{{ $scan->pages_errored }}</ui-table-cell>
                            <ui-table-cell class="text-right">{{ $scan->issues_total }}</ui-table-cell>
                            <ui-table-cell>
                                @if (is_array($scan->issues_by_impact))
                                    {{ $scan->issues_by_impact['critical'] ?? 0 }} critical, {{ $scan->issues_by_impact['serious'] ?? 0 }} serious, {{ $scan->issues_by_impact['moderate'] ?? 0 }} moderate, {{ $scan->issues_by_impact['minor'] ?? 0 }} minor
                                @endif
                            </ui-table-cell>
                            <ui-table-cell>
                                @if (is_array($scan->diff) && ($scan->diff['previous_scan_id'] ?? null) !== null)
                                    +{{ $scan->diff['new'] }} new, {{ $scan->diff['fixed'] }} fixed
                                @elseif ($scan->status === 'complete')
                                    first scan
                                @endif
                            </ui-table-cell>
                            <ui-table-cell>@plain($scan->engine) @plain($scan->engine_version)</ui-table-cell>
                        </ui-table-row>
                    @endforeach
                </ui-table-rows>
            </ui-table>
        @endif
    </ui-card-panel>

    <ui-card-panel heading="Conformance reports">
        <div class="space-y-4">
            <p>A conformance report is a document: the {{ $reports->isEmpty() ? '' : 'latest ' }}complete scan, every WCAG success criterion with its result, the findings, and the open issues, with a scope and limits statement that cannot be removed. It names who generated it and which scan it came from.</p>
            @if ($canGenerate)
                @if ($hasCompleteScan)
                    <form method="post" action="@plain($generateUrl)">
                        @csrf
                        @if ($site !== null)
                            <input type="hidden" name="site" value="@plain($site)">
                        @endif
                        <ui-button type="submit" variant="primary" text="Generate a report from the latest scan" />
                    </form>
                @else
                    <ui-description text="A report needs a complete scan. Run one first." />
                @endif
            @endif
            @if ($reports->isEmpty())
                <p>No report has been generated{{ $site !== null ? ' for this site' : '' }}.</p>
            @else
                <ui-table>
                    <ui-table-columns>
                        <ui-table-column>Generated</ui-table-column>
                        <ui-table-column>By</ui-table-column>
                        <ui-table-column>Standard</ui-table-column>
                        <ui-table-column>Coverage</ui-table-column>
                        <ui-table-column>Files</ui-table-column>
                    </ui-table-columns>
                    <ui-table-rows>
                        @foreach ($reports as $report)
                            <ui-table-row>
                                <ui-table-cell>
                                    <span title="{{ $report->generated_at->toDayDateTimeString() }}">{{ $report->generated_at->diffForHumans() }}</span>
                                    @if ($report->scan)<ui-description text="from the scan of {{ ($report->scan->finished_at ?? $report->scan->created_at)->diffForHumans() }}, {{ $report->scan->issues_total }} issues" />@endif
                                </ui-table-cell>
                                <ui-table-cell>@plain($report->generated_by)</ui-table-cell>
                                <ui-table-cell>@plain(\Bpmore\A11yReport\Document\Wcag::label($report->standard))</ui-table-cell>
                                <ui-table-cell>@plain($report->coverage_note)</ui-table-cell>
                                <ui-table-cell>
                                    <div class="flex gap-2">
                                        @if ($report->html_path)<ui-button size="sm" href="@plain(cp_route('utilities.a11y-report.reports.download', ['uuid' => $report->uuid, 'format' => 'html']))" target="_blank" text="HTML" />@endif
                                        @if ($report->json_path)<ui-button size="sm" href="@plain(cp_route('utilities.a11y-report.reports.download', ['uuid' => $report->uuid, 'format' => 'json']))" target="_blank" text="JSON" />@endif
                                        @if ($report->pdf_path)<ui-button size="sm" href="@plain(cp_route('utilities.a11y-report.reports.download', ['uuid' => $report->uuid, 'format' => 'pdf']))" target="_blank" text="PDF" />@endif
                                    </div>
                                </ui-table-cell>
                            </ui-table-row>
                        @endforeach
                    </ui-table-rows>
                </ui-table>
            @endif
        </div>
    </ui-card-panel>

@endif

    <ui-card-panel heading="What these numbers are">
        <div class="space-y-2">
            <p>
                Every scan renders each published page the way a visitor would see it and reads the finished markup with the
                same checks Accessibility Gate runs before a publish. It cannot see anything a stylesheet decides, colour
                contrast included, and it cannot judge meaning. A scan that finds nothing has not proven the site accessible.
            </p>
            <p>
                A page the scan could not read is counted on its own and never as clean. Every page keeps a record of how much of
                it the checks could see.
            </p>
        </div>
    </ui-card-panel>

</div>
