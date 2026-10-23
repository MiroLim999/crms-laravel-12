{{--
    One oversight dashboard panel: the title, a muted line of context and the
    tools (a chart key, the period menu) across the top, then the content.

    The id is panel-{panel}: the period menu returns to it, and the dashboard
    script swaps the whole card when its period changes. A rejected period's
    message opens the body, where it is seen without opening the menu.

    The body is a size container, so what is inside lays itself out by the
    room the card actually has rather than by the width of the window.
--}}
@props(['panel', 'title'])

<div id="panel-{{ $panel }}" {{ $attributes->merge(['class' => 'card dashboard-panel h-100']) }}>
    <div class="card-header dashboard-panel__header">
        <div class="dashboard-panel__heading">
            <div class="dashboard-panel__title-row">
                <h5 class="card-title mb-0">{{ $title }}</h5>
                {{ $badge ?? '' }}
            </div>
            @isset($meta)
                <p class="dashboard-panel__meta">{{ $meta }}</p>
            @endisset
        </div>

        @isset($tools)
            <div class="dashboard-panel__tools">{{ $tools }}</div>
        @endisset
    </div>

    <div class="card-body dashboard-panel__body">
        <x-dashboard.period-error :panel="$panel" />

        {{ $slot }}
    </div>
</div>
