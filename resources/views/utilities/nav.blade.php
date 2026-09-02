{{-- The report's own sections, as a row of buttons. Tabs need state a static template does not have; links do not. --}}
<div class="flex flex-wrap items-center gap-2">
    <ui-button size="sm" href="@plain($overviewUrl)" text="Overview" @if ($active === 'overview') variant="primary" @endif />
    <ui-button size="sm" href="@plain($indexUrl)" text="Issues" @if ($active === 'issues') variant="primary" @endif />
</div>
