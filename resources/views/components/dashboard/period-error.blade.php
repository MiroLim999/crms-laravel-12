{{--
    Why a panel shows the default period: the period in the address was not
    valid. It sits in the panel's body, where it is seen without opening the
    period menu, and the menu's date fields point to it.
--}}
@props(['panel'])

@php
    $messages = collect(["{$panel}_period", "{$panel}_from", "{$panel}_to"])
        ->flatMap(fn (string $key) => $errors->get($key))
        ->unique();
    $fallback = strtolower(\App\Services\DashboardAnalytics::PERIODS[\App\Services\DashboardAnalytics::DEFAULT_PERIOD]);
@endphp

@if ($messages->isNotEmpty())
    <div id="{{ $panel }}-period-error" class="dashboard-period-error" role="alert">
        <i class="icon-base bx bx-error-circle" aria-hidden="true"></i>
        <span>
            @foreach ($messages as $message)
                {{ $message }}
            @endforeach
            Showing the {{ $fallback }} instead.
        </span>
    </div>
@endif
