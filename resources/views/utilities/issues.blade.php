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
    // "wont_fix" is the column's value and "Wont fix" is not a phrase. The
    // apostrophe is not decoration: the badge, the dropdown and the report all
    // name the same decision, and two spellings of it read as two things.
    $label = fn (string $status) => $status === \Bpmore\A11yReport\Models\IssueState::WONT_FIX
        ? "Won't fix"
        : ucfirst(str_replace('_', ' ', $status));
    $colour = ['critical' => 'red', 'serious' => 'amber', 'moderate' => 'blue', 'minor' => 'default'];
    // The criterion the engine cited, looked up in the catalogue for its
    // name and the W3C's page on it. A house rule cites none and stays text.
    $criterion = function ($i) {
        $cited = is_string($i->wcag_criteria) ? (array) json_decode($i->wcag_criteria, true) : (array) $i->wcag_criteria;

        return isset($cited[0]) ? \Bpmore\A11yReport\Document\Wcag::find((string) $cited[0]) : null;
    };
    $version = \Bpmore\A11yReport\Document\Wcag::version($standard);
    // Underlined and blue, because the control panel's stylesheet resets
    // anchors to the text colour and a link nobody can tell from text is
    // not a link (1.4.1). The colours are the pair Statamic's own blue badge
    // uses, so they hold contrast in both themes. Statamic's focus utility
    // gives the keyboard ring. The tab warning is read, not shown.
    $link = 'underline underline-offset-2 text-blue-700 dark:text-blue-300 focus:focus-outline rounded-sm';
    // Said in words and never in colour alone. A queue that marks overdue
    // work with a red dot fails 1.4.1 on the screen of an accessibility
    // product, which is the story about the product.
    $dueLabel = ['overdue' => 'Past target', 'expiring' => 'Acceptance runs out within '.$expiringWithin.' days', 'expired' => 'Acceptance has run out'];
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
                @if ($policyCounts['overdue'] > 0)
                    <ui-badge color="red" text="{{ $policyCounts['overdue'] }} past target" />
                @endif
                @if ($policyCounts['expired'] > 0)
                    <ui-badge color="amber" text="{{ $policyCounts['expired'] }} {{ $policyCounts['expired'] === 1 ? 'acceptance' : 'acceptances' }} run out" />
                @endif
                @if ($policyCounts['expiring'] > 0)
                    <ui-badge text="{{ $policyCounts['expiring'] }} {{ $policyCounts['expiring'] === 1 ? 'acceptance' : 'acceptances' }} running out soon" />
                @endif
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
                    {{-- Every other filter here names the thing it filters on:
                         status, impact, site, collection. This one first named
                         the idea behind its options ("Against the policy") and
                         the person asked to use it could not find it, then
                         named a word this product uses nowhere else
                         ("Deadlines") and became the third name for one thing.
                         "Remediation" is the word already at the head of that
                         section in the report and on the settings screen, and
                         its two halves, targets and acceptances, are what the
                         options underneath say. --}}
                    <label for="a11y-f-due" class="block text-xs font-medium mb-1">Remediation</label>
                    <select id="a11y-f-due" name="due" class="rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-1 text-sm">
                        <option value="">Any</option>
                        @foreach ($dueOptions as $due)
                            <option value="{{ $due }}" @if ($f['due'] === $due) selected @endif>{{ $dueLabel[$due] }}</option>
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
                @php($narrowed = collect($f)->except('status')->filter(fn ($v) => $v !== '')->isNotEmpty())
                {{-- "No issue is open or in progress" was printed whenever the
                     status filter was on its default, whatever else was
                     narrowing the list. With a remediation state or a site chosen it
                     said no issue was open while hundreds were, which is the
                     one thing a queue must not say: a person reading it has
                     been told their work is done. --}}
                @if ($narrowed)
                    <p>Nothing matches these filters.
                        @if (($openNow = $counts[\Bpmore\A11yReport\Models\IssueState::OPEN] + $counts[\Bpmore\A11yReport\Models\IssueState::IN_PROGRESS]) > 0)
                            {{ $openNow }} {{ $openNow === 1 ? 'issue is' : 'issues are' }} open or in progress in all.
                        @endif
                        <a href="@plain($indexUrl)" class="{{ $link }}">Clear the filters</a> to see everything.</p>
                @elseif ($f['status'] === 'active')
                    <p>Nothing matches. No issue is open or in progress.</p>
                @else
                    <p>Nothing matches.</p>
                @endif
            @else
                <p class="text-sm">{{ $issues->total() }} {{ $issues->total() === 1 ? 'issue' : 'issues' }} match, oldest and most serious first{{ $issues->lastPage() > 1 ? ', page '.$issues->currentPage().' of '.$issues->lastPage() : '' }}.@if ($canManage) Tick any of them and use <a href="#a11y-bulk" class="{{ $link }}">Change the ticked issues</a>, below the table.@endif</p>

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
                            <ui-table-column>Open for, and due</ui-table-column>
                            <ui-table-column>Status</ui-table-column>
                            <ui-table-column>Assigned</ui-table-column>
                        </ui-table-columns>
                        <ui-table-rows>
                            @foreach ($issues as $i)
                                <ui-table-row>
                                    @if ($canManage)
                                        <ui-table-cell>
                                            <input type="checkbox" name="fingerprints[]" value="@plain($i->fingerprint)" id="a11y-i-@plain(substr($i->fingerprint, 0, 8))" aria-label="Select the issue on @plain($i->path)" @checked(in_array($i->fingerprint, (array) old('fingerprints', []), true))>
                                        </ui-table-cell>
                                    @endif
                                    <ui-table-cell>
                                        @php($edit = $editUrls[$i->entry_id] ?? null)
                                        {{-- The path goes to the entry, because in the control panel a
                                             path is a thing you manage and the queue is a list of things
                                             to fix. Left as plain text where there is no entry to open:
                                             deleted since the scan, or not this person's to edit. --}}
                                        @if ($edit)
                                            <a href="@plain($edit)" class="{{ $link }}">@plain($i->path)</a>
                                        @else
                                            <span>@plain($i->path)</span>
                                        @endif
                                        <ui-description text="@plain($i->collection . (count($options['sites']) > 1 ? ', '.$i->site : ''))" />
                                        {{-- And the page itself, said out loud rather than left as the
                                             path's own behaviour. For a contrast failure the rendered
                                             page is the only place the problem can be seen at all. --}}
                                        <div class="text-xs mt-1">
                                            <a href="@plain($i->url)" target="_blank" rel="noopener" class="{{ $link }}">View page<span class="sr-only"> @plain($i->path) on the site, opens in a new tab</span></a>
                                        </div>
                                    </ui-table-cell>
                                    <ui-table-cell>
                                        <div>@plain($i->message)</div>
                                        <div class="text-xs text-gray-600 dark:text-gray-400">
                                            @if (($c = $criterion($i)) !== null)<a href="@plain($c->understandingUrl($version))" target="_blank" rel="noopener" class="{{ $link }}">@plain($i->label) @plain($c->name)<span class="sr-only"> (the W3C's explanation, opens in a new tab)</span></a>@else @plain($i->label) @endif @plain((($where = $i->pointer ?: $i->selector) ? ': '.$where : '') . ($i->occurrences > 1 ? ', '.$i->occurrences.' times' : ''))
                                        </div>
                                        @if ($i->note)<ui-description text="Note: @plain($i->note)" />@endif
                                    </ui-table-cell>
                                    <ui-table-cell><ui-badge color="{{ $colour[$i->impact] ?? 'default' }}" text="@plain($i->impact)" pill /></ui-table-cell>
                                    <ui-table-cell>
                                        <span title="{{ $i->first_seen_at->toDayDateTimeString() }}">{{ (int) $i->first_seen_at->diffInDays(now()) }} {{ (int) $i->first_seen_at->diffInDays(now()) === 1 ? 'day' : 'days' }}</span>
                                        @php($over = $policy->overdueDays((string) $i->impact, $i->first_seen_at))
                                        @php($due = $policy->dueAt((string) $i->impact, $i->first_seen_at))
                                        @if ($over !== null)
                                            <ui-description text="Past target by {{ $over }} {{ $over === 1 ? 'day' : 'days' }}" />
                                        @elseif ($due !== null)
                                            <ui-description text="Due {{ $due->format('j M Y') }}" />
                                        @else
                                            <ui-description text="No target for {{ $i->impact }}" />
                                        @endif
                                    </ui-table-cell>
                                    <ui-table-cell>
                                        @plain($label($i->status))
                                        @if ($i->updated_by)<ui-description text="by @plain($i->updated_by)" />@endif
                                        @if ($i->status === \Bpmore\A11yReport\Models\IssueState::WONT_FIX)
                                            @if ($i->exception_expires_at === null)
                                                <ui-description text="No review date recorded" />
                                            @elseif ($i->acceptanceHasExpired())
                                                <ui-description text="Accepted until {{ $i->exception_expires_at->format('j M Y') }}, which has gone: open again until somebody looks" />
                                            @else
                                                <ui-description text="Accepted until {{ $i->exception_expires_at->format('j M Y') }}" />
                                            @endif
                                            @if ($i->exception_reason)<ui-description text="Because: @plain($i->exception_reason)" />@endif
                                        @endif
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
                        <fieldset id="a11y-bulk" class="space-y-3 border border-gray-300 dark:border-gray-700 rounded p-4">
                            <legend class="px-1 text-sm font-medium">Change the ticked issues</legend>
                            <div class="flex flex-wrap items-end gap-3">
                                <div>
                                    <label for="a11y-b-status" class="block text-xs font-medium mb-1">Set status</label>
                                    <select id="a11y-b-status" name="status" class="rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-1 text-sm">
                                        <option value="">Leave as is</option>
                                        @foreach ($statuses as $status)
                                            <option value="{{ $status }}" @selected(old('status') === $status)>{{ $label($status) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label for="a11y-b-assignee" class="block text-xs font-medium mb-1">Assign to</label>
                                    <input id="a11y-b-assignee" type="text" name="assigned_to" value="@plain(old('assigned_to'))" placeholder="leave as is" aria-describedby="a11y-b-assignee-help" class="rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-1 text-sm">
                                    <p id="a11y-b-assignee-help" class="text-xs mt-1 opacity-70">A dash clears it.</p>
                                </div>
                                <div class="grow">
                                    <label for="a11y-b-note" class="block text-xs font-medium mb-1">Note</label>
                                    <input id="a11y-b-note" type="text" name="note" value="@plain(old('note'))" placeholder="why, for whoever reads this next" class="w-full rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-1 text-sm">
                                </div>
                            </div>
                            <div class="flex flex-wrap items-end gap-3">
                                <div class="grow">
                                    <label for="a11y-b-reason" class="block text-xs font-medium mb-1">Reason, if the status is won't fix</label>
                                    <input id="a11y-b-reason" type="text" name="exception_reason" value="@plain(old('exception_reason'))" aria-describedby="a11y-b-reason-help" class="w-full rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-1 text-sm">
                                    <p id="a11y-b-reason-help" class="text-xs mt-1 opacity-70">Required to accept an issue, and printed in the conformance report.</p>
                                </div>
                                <div>
                                    <label for="a11y-b-expires" class="block text-xs font-medium mb-1">Accepted until</label>
                                    <input id="a11y-b-expires" type="date" name="exception_expires_at" value="@plain(old('exception_expires_at'))" max="@plain($latestExpiry)" aria-describedby="a11y-b-expires-help" class="rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-1 text-sm">
                                    <p id="a11y-b-expires-help" class="text-xs mt-1 opacity-70">Leave this empty and it runs to {{ $latestExpiry }}, which is as far as the policy allows.</p>
                                </div>
                            </div>
                            {{-- The checkbox that widens this from the ticked rows to every
                                 row the filter matches used to sit on the same line as the
                                 button that acts on it, a slip apart from editing hundreds of
                                 issues in one press. It is its own block now, above the
                                 button and marked as the different thing it is. --}}
                            <div class="rounded border border-amber-500/60 bg-amber-50/60 dark:bg-amber-950/20 p-3">
                                <label class="text-sm flex items-start gap-2">
                                    <input type="checkbox" name="all_matching" value="1" class="mt-1" @checked(old('all_matching'))>
                                    <span>Apply to <strong>all {{ $issues->total() }}</strong> {{ $issues->total() === 1 ? 'issue' : 'issues' }} the current filter matches, not only the ticked ones. This reaches rows on other pages of the list.</span>
                                </label>
                            </div>
                            <div>
                                <ui-button type="submit" variant="primary" size="sm" text="Apply" />
                            </div>
                            <ui-description text="A scan never reopens an issue marked won't fix or false positive. It reopens a fixed one, or one whose page was removed, only if the problem comes back." />
                            <ui-description text="Accepting an issue needs a reason and a date it runs out on. It does not hide the issue: an accepted failure is still listed in the conformance report and still counted under its success criterion. Past the date, it counts as open again, and the record of who accepted it and why is kept." />
                        </fieldset>
                    @endif
                </form>
            @endif
        </div>
    </ui-card-panel>

@endif

</div>
