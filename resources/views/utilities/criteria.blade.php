{{--
    The criteria worksheet. Compiled as a Vue template like the other
    utility pages: @plain on every string, native form controls with labels.

    One form, every criterion, one save. The server writes only rows that
    changed, so pressing save with nothing altered attests to nothing.
--}}
@php
    $control = 'rounded border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-1 text-sm';
    $scan = $sheet['scan'];
    // See the queue view for why a link here is styled by hand.
    $link = 'underline underline-offset-2 text-blue-700 dark:text-blue-300 focus:focus-outline rounded-sm';
@endphp

<ui-header title="Accessibility Report" icon="pulse" />

<div class="space-y-6">

    @include('a11y-report::utilities.nav', ['active' => 'criteria', 'criteriaUrl' => $criteriaUrl])

@if (! $installed)
    <ui-card-panel heading="Criteria">
        <p>The worksheet needs a scan to fill in the automated evidence. Run one first.</p>
    </ui-card-panel>
@else

    <ui-card-panel heading="Criteria worksheet">
        <div class="space-y-4">
            <p>Every <a href="@plain($standardUrl)" target="_blank" rel="noopener" class="{{ $link }}">{{ $standardLabel }}<span class="sr-only"> (the W3C Recommendation, opens in a new tab)</span></a> success criterion, each linked to the W3C's explanation of what it requires. The automated evidence comes from the latest complete scan{{ $scan ? ' of '.($scan->finished_at ?? $scan->created_at)->diffForHumans().', '.$scan->pages_scanned.' pages read' : '' }}. What you write here is what the conformance report prints. A locked row is never changed by a scan. Nothing becomes "Supports" unless a person sets it.</p>

            @if (count($sites) > 1)
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm">Editing:</span>
                    <ui-button size="sm" href="@plain($criteriaUrl)" text="Global default" @if ($site === null) variant="primary" @endif />
                    @foreach ($sites as $s)
                        <ui-button size="sm" href="@plain($criteriaUrl.'?site='.$s['handle'])" text="@plain($s['name'])" @if ($site === $s['handle']) variant="primary" @endif />
                    @endforeach
                </div>
                <ui-description text="A site without a row of its own inherits the global default for that criterion." />
            @endif

            @if ($scan === null)
                <ui-description text="No complete scan yet, so no automated evidence. The rows below are yours alone." />
            @endif

            <form method="post" action="@plain($saveUrl)" class="space-y-4">
                @csrf
                @if ($site !== null)<input type="hidden" name="site" value="@plain($site)">@endif

                <ui-table>
                    <ui-table-columns>
                        <ui-table-column>Criterion</ui-table-column>
                        <ui-table-column>Automated evidence</ui-table-column>
                        <ui-table-column>Effective result</ui-table-column>
                        <ui-table-column>Your assessment</ui-table-column>
                    </ui-table-columns>
                    <ui-table-rows>
                        @foreach ($sheet['rows'] as $row)
                            @php
                                $own = $row['own'];
                                $inh = $row['inherited'];
                                $n = $row['number'];
                                $id = 'a11y-c-'.str_replace('.', '-', $n);
                            @endphp
                            <ui-table-row>
                                <ui-table-cell>
                                    <div class="font-medium"><a href="@plain($row['url'])" target="_blank" rel="noopener" class="{{ $link }}">{{ $n }} @plain($row['name'])<span class="sr-only"> (the W3C's explanation, opens in a new tab)</span></a></div>
                                    <ui-description text="Level {{ $row['level'] }}" />
                                </ui-table-cell>
                                <ui-table-cell>
                                    <div class="text-sm">@plain($row['evidence'])</div>
                                </ui-table-cell>
                                <ui-table-cell>
                                    <ui-badge text="@plain($label($row['effective_status']))" @if ($row['effective_status'] === 'does_not_support') color="red" @elseif ($row['effective_status'] === 'supports') color="emerald" @elseif ($row['effective_status'] === 'partially_supports') color="amber" @endif />
                                    @if ($inh)<ui-description text="@plain('Inherited from the global default'.($inh->assessed_by ? ', assessed by '.$inh->assessed_by : ''))" />@endif
                                    @if ($own && $own->assessed_by)<ui-description text="@plain('Assessed by '.$own->assessed_by.($own->assessed_at ? ' on '.$own->assessed_at->format('j M Y') : ''))" />@endif
                                </ui-table-cell>
                                <ui-table-cell>
                                    @if ($canAssess)
                                        <div class="space-y-2">
                                            <div class="flex flex-wrap gap-2">
                                                <div>
                                                    <label for="{{ $id }}-status" class="block text-xs font-medium mb-1">Status</label>
                                                    <select id="{{ $id }}-status" name="criteria[{{ $n }}][status]" class="{{ $control }}">
                                                        <option value="">No assessment of your own</option>
                                                        @foreach ($statuses as $status)
                                                            <option value="{{ $status }}" @if ($own && $own->status === $status) selected @endif>{{ $label($status) }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div>
                                                    <label for="{{ $id }}-method" class="block text-xs font-medium mb-1">Method</label>
                                                    <select id="{{ $id }}-method" name="criteria[{{ $n }}][method]" class="{{ $control }}">
                                                        @foreach ($methods as $method)
                                                            <option value="{{ $method }}" @if (($own->method ?? 'manual') === $method) selected @endif>{{ ucfirst($method) }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                            </div>
                                            <div>
                                                <label for="{{ $id }}-remarks" class="block text-xs font-medium mb-1">Remarks</label>
                                                <textarea id="{{ $id }}-remarks" name="criteria[{{ $n }}][remarks]" rows="2" class="{{ $control }} w-full">@plain($own->remarks ?? '')</textarea>
                                            </div>
                                            <label class="text-sm"><input type="checkbox" name="criteria[{{ $n }}][locked]" value="1" @if ($own && $own->locked) checked @endif> Locked: a scan never changes this</label>
                                        </div>
                                    @else
                                        @if ($own)
                                            <div class="text-sm">@plain($label($own->status).', '.$own->method.($own->locked ? ', locked' : ''))</div>
                                            @if ($own->remarks)<ui-description text="@plain($own->remarks)" />@endif
                                        @else
                                            <ui-description text="None" />
                                        @endif
                                    @endif
                                </ui-table-cell>
                            </ui-table-row>
                        @endforeach
                    </ui-table-rows>
                </ui-table>

                @if ($canAssess)
                    <div class="flex items-center gap-3">
                        <ui-button type="submit" variant="primary" text="Save the worksheet" />
                        <ui-description text="Only rows you changed are written, with your name and the date." />
                    </div>
                @endif
            </form>
        </div>
    </ui-card-panel>

@endif

</div>
