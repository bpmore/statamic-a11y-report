{{-- Compiled as a Vue template, like the utility view. Braces go through @plain. --}}
<ui-card-panel heading="Accessibility">
    @if (! $overview->installed())
        <p>No scan has run yet.</p>
        @if ($url)<ui-button size="sm" href="@plain($url)" text="Open the report" />@endif
    @else
        <div class="space-y-3">
            <div class="flex items-baseline gap-2">
                <span class="text-3xl font-semibold">{{ $overview->openTotal() }}</span>
                <span class="text-sm opacity-70">open {{ $overview->openTotal() === 1 ? 'issue' : 'issues' }}</span>
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach ($byImpact as $impact => $count)
                    <ui-badge size="sm" color="{{ ['critical' => 'red', 'serious' => 'amber', 'moderate' => 'blue', 'minor' => 'default'][$impact] }}" text="{{ $count }} {{ $impact }}" pill />
                @endforeach
            </div>
            @if ($chart !== '')
                <div class="text-gray-800 dark:text-gray-200">{!! $chart !!}</div>
                <ui-description text="Issues found per scan, last 30 days." />
            @else
                <ui-description text="No completed scan in the last 30 days." />
            @endif
            @if ($latest)
                <ui-description text="Last scan {{ ($latest->finished_at ?? $latest->created_at)->diffForHumans() }}: @plain($latest->status), {{ $latest->pages_scanned }} pages read, {{ $latest->pages_errored }} not read." />
            @endif
            @if ($url)<ui-button size="sm" href="@plain($url)" text="Open the report" />@endif
        </div>
    @endif
</ui-card-panel>
