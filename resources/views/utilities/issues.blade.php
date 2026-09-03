{{--
    The remediation queue. Compiled as a Vue template like the overview, so
    every data value goes through @plain and there is no interpolation.

    Native form controls on purpose. Statamic's select and checkbox are Vue
    components bound to state this template does not have, and a plain
    <select> inside a plain <form> is the most accessible control there is.
    Every control has a label, and the whole page works with no script.
--}}
@php
    $f = $filters;
    $href = fn (array $extra = []) => $indexUrl.(($qs = http_build_query(array_merge($queryString, $extra))) !== '' ? '?'.$qs : '');
    $label = fn (string $status) => ucfirst(str_replace('_', ' ', $status));
    $colour = ['critical' => 'red', 'serious' => 'amber', 'moderate' => 'blue', 'minor' => 'default'];
    // The criterion the engine cited, looked up in the catalogue for its
    // name and the W3C's page on it. A house rule cites none and stays text.
    $criterion = function ($i) {
        $cited = is_string($i->wcag_criteria) ? (array) json_decode($i->wcag_criteria, true) : (array) $i->wcag_criteria;

        return isset($cited[0]) ? \Bpmore\A11yReport\Document\Wcag::find((string) $cited[0]) : null;
    };
    $version = \Bpmore\A11yReport\Document\Wcag::version($standard);
@endphp

<ui-header title="Accessibility Report" icon="pulse" />

<div class="space-y-6">

    @include('a11y-report::utilities.nav', ['active' => 'issues'])

@if (! $installed)
    <ui-card-panel heading="No issues yet">
        <p>The queue fills from scans, and no scan has run.</p>
    </ui-card-panel>
@else

    <ui-card-panel heading="Issues">
        <div class="space-y-4">
            <div class="flex flex-wrap gap-2">
                @foreach ($counts as $status => $n)
                    <ui-badge text="{{ $n }} {{ strtolower($label($status)) }}" @if ($f['status'] === $status) color="blue" @endif />
                @endforeach
            </div>

            @if ($f['path'] !== '')
                <div class="flex flex-wrap items-center gap-2">
                    <ui-badge color="blue" text="@plain('Only the page '.$f['path'])" />
                    <ui-button size="sm" variant="ghost" href="@plain($href(['path' => null]))" text="Every page" />
                </div>
            @endif

            <form method="get" action="@plain($indexUrl)" class="flex flex-wrap items-end gap-3">
                @if ($f['path'] !== '')<input type="hidden" name="path" value="@plain($f['path'])">@endif
                <div>
                    <label for="a11y-f-status" class="block text-xs font-medium mb-1">Status</label>
                    <select id="a11y-f-status" name="status" class="rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-1 text-sm">
                        <option value="active" @if ($f['status'] === 'active') selected @endif>Open or in progress</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @if ($f['status'] === $status) selected @endif>{{ $label($status) }}</option>
                        @endforeach
                        <option value="all" @if ($f['status'] === 'all') selected @endif>Everything</option>
                    </select>
                </div>
                <div>
                    <label for="a11y-f-impact" class="block text-xs font-medium mb-1">Impact</label>
                    <select id="a11y-f-impact" name="impact" class="rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-1 text-sm">
                        <option value="">Any</option>
                        @foreach (['critical', 'serious', 'moderate', 'minor'] as $impact)
                            <option value="{{ $impact }}" @if ($f['impact'] === $impact) selected @endif>{{ ucfirst($impact) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="a11y-f-criterion" class="block text-xs font-medium mb-1">Criterion or check</label>
                    <select id="a11y-f-criterion" name="criterion" class="rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-1 text-sm">
                        <option value="">Any</option>
                        @foreach ($options['criteria'] as $c)
                            <option value="@plain($c)" @if ($f['criterion'] === $c) selected @endif>@plain($c)</option>
                        @endforeach
                    </select>
                </div>
                @if (count($options['sites']) > 1)
                    <div>
                        <label for="a11y-f-site" class="block text-xs font-medium mb-1">Site</label>
                        <select id="a11y-f-site" name="site" class="rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-1 text-sm">
                            <option value="">Any</option>
                            @foreach ($options['sites'] as $s)
                                <option value="@plain($s)" @if ($f['site'] === $s) selected @endif>@plain($s)</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div>
                    <label for="a11y-f-collection" class="block text-xs font-medium mb-1">Collection</label>
                    <select id="a11y-f-collection" name="collection" class="rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-1 text-sm">
                        <option value="">Any</option>
                        @foreach ($options['collections'] as $c)
                            <option value="@plain($c)" @if ($f['collection'] === $c) selected @endif>@plain($c)</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="a11y-f-assignee" class="block text-xs font-medium mb-1">Assigned to</label>
                    <select id="a11y-f-assignee" name="assignee" class="rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-1 text-sm">
                        <option value="">Anyone</option>
                        <option value="-" @if ($f['assignee'] === '-') selected @endif>Nobody</option>
                        @foreach ($options['assignees'] as $a)
                            <option value="@plain($a)" @if ($f['assignee'] === $a) selected @endif>@plain($a)</option>
                        @endforeach
                    </select>
                </div>
                <ui-button type="submit" size="sm" text="Filter" />
                <ui-button size="sm" variant="ghost" href="@plain($indexUrl)" text="Clear" />
            </form>

            @if ($issues->total() === 0)
                <p>Nothing matches. @if ($f['status'] === 'active')No issue is open or in progress.@endif</p>
            @else
                <p class="text-sm">{{ $issues->total() }} {{ $issues->total() === 1 ? 'issue' : 'issues' }} match, oldest and most serious first{{ $issues->lastPage() > 1 ? ', page '.$issues->currentPage().' of '.$issues->lastPage() : '' }}.</p>

                <form method="post" action="@plain($updateUrl)" class="space-y-4">
                    @csrf
                    @foreach ($queryString as $k => $v)
                        <input type="hidden" name="filters[@plain($k)]" value="@plain($v)">
                    @endforeach

                    <ui-table>
                        <ui-table-columns>
                            @if ($canManage)<ui-table-column><span class="sr-only">Select</span></ui-table-column>@endif
                            <ui-table-column>Page</ui-table-column>
                            <ui-table-column>Problem</ui-table-column>
                            <ui-table-column>Impact</ui-table-column>
                            <ui-table-column>Open for</ui-table-column>
                            <ui-table-column>Status</ui-table-column>
                            <ui-table-column>Assigned</ui-table-column>
                        </ui-table-columns>
                        <ui-table-rows>
                            @foreach ($issues as $i)
                                <ui-table-row>
                                    @if ($canManage)
                                        <ui-table-cell>
                                            <input type="checkbox" name="fingerprints[]" value="@plain($i->fingerprint)" id="a11y-i-@plain(substr($i->fingerprint, 0, 8))" aria-label="Select the issue on @plain($i->path)">
                                        </ui-table-cell>
                                    @endif
                                    <ui-table-cell>
                                        <a href="@plain($i->url)" target="_blank" rel="noopener">@plain($i->path)</a>
                                        <ui-description text="@plain($i->collection . (count($options['sites']) > 1 ? ', '.$i->site : ''))" />
                                    </ui-table-cell>
                                    <ui-table-cell>
                                        <div>@plain($i->message)</div>
                                        <div class="text-xs text-gray-600 dark:text-gray-400">
                                            @if (($c = $criterion($i)) !== null)<a href="@plain($c->understandingUrl($version))" target="_blank" rel="noopener" title="What this criterion requires, at w3.org">@plain($i->label) @plain($c->name)</a>@else @plain($i->label) @endif @plain(($i->pointer ? ': '.$i->pointer : '') . ($i->occurrences > 1 ? ', '.$i->occurrences.' times' : ''))
                                        </div>
                                        @if ($i->note)<ui-description text="Note: @plain($i->note)" />@endif
                                    </ui-table-cell>
                                    <ui-table-cell><ui-badge color="{{ $colour[$i->impact] ?? 'default' }}" text="@plain($i->impact)" pill /></ui-table-cell>
                                    <ui-table-cell>
                                        <span title="{{ $i->first_seen_at->toDayDateTimeString() }}">{{ (int) $i->first_seen_at->diffInDays(now()) }} {{ (int) $i->first_seen_at->diffInDays(now()) === 1 ? 'day' : 'days' }}</span>
                                    </ui-table-cell>
                                    <ui-table-cell>
                                        @plain($label($i->status))
                                        @if ($i->updated_by)<ui-description text="by @plain($i->updated_by)" />@endif
                                    </ui-table-cell>
                                    <ui-table-cell>@plain($i->assigned_to ?: 'nobody')</ui-table-cell>
                                </ui-table-row>
                            @endforeach
                        </ui-table-rows>
                    </ui-table>

                    @if ($issues->lastPage() > 1)
                        <div class="flex gap-2">
                            @if ($issues->currentPage() > 1)<ui-button size="sm" href="@plain($href(['page' => $issues->currentPage() - 1]))" text="Previous page" />@endif
                            @if ($issues->hasMorePages())<ui-button size="sm" href="@plain($href(['page' => $issues->currentPage() + 1]))" text="Next page" />@endif
                        </div>
                    @endif

                    @if ($canManage)
                        <fieldset class="space-y-3 border border-gray-300 dark:border-gray-700 rounded p-4">
                            <legend class="px-1 text-sm font-medium">Change the ticked issues</legend>
                            <div class="flex flex-wrap items-end gap-3">
                                <div>
                                    <label for="a11y-b-status" class="block text-xs font-medium mb-1">Set status</label>
                                    <select id="a11y-b-status" name="status" class="rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-1 text-sm">
                                        <option value="">Leave as is</option>
                                        @foreach ($statuses as $status)
                                            <option value="{{ $status }}">{{ $label($status) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label for="a11y-b-assignee" class="block text-xs font-medium mb-1">Assign to</label>
                                    <input id="a11y-b-assignee" type="text" name="assigned_to" placeholder="leave as is, or - for nobody" class="rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-1 text-sm">
                                </div>
                                <div class="grow">
                                    <label for="a11y-b-note" class="block text-xs font-medium mb-1">Note</label>
                                    <input id="a11y-b-note" type="text" name="note" placeholder="why, for whoever reads this next" class="w-full rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-1 text-sm">
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-4">
                                <label class="text-sm"><input type="checkbox" name="all_matching" value="1"> Apply to all {{ $issues->total() }} matching the current filter, not only the ticked ones</label>
                                <ui-button type="submit" variant="primary" size="sm" text="Apply" />
                            </div>
                            <ui-description text="A scan never reopens an issue marked won't fix or false positive. It reopens a fixed one, or one whose page was removed, only if the problem comes back." />
                        </fieldset>
                    @endif
                </form>
            @endif
        </div>
    </ui-card-panel>

@endif

</div>
