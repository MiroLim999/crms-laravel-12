{{--
    The reporting period of one dashboard panel: a compact menu in the panel's
    header that opens like a select.

    Each preset is a link and the custom dates a small form. Their fields are
    prefixed with the panel's name, and the other panels' current choices ride
    along, so choosing one never resets the rest. Both return to the panel,
    which can be a long way down a tall screen. The dashboard script swaps the
    panel in place; these plain links and the form are what it falls back to.

    The menu is positioned against the window, so the card's rounded corners,
    which clip their contents, never cut it off.
--}}
@props(['panel', 'label', 'scopes', 'forms', 'options'])

@php
    $scope = $scopes[$panel];
    $form = $forms[$panel];
    $default = \App\Services\DashboardAnalytics::DEFAULT_PERIOD;
    $today = now($scope['timezone'])->toDateString();
    $earliest = \App\Services\DashboardAnalytics::EARLIEST_DATE;

    $carried = [];
    foreach ($scopes as $name => $other) {
        if ($name === $panel || $other['period'] === $default) {
            continue;
        }

        $carried["{$name}_period"] = $other['period'];

        if ($other['period'] === 'custom') {
            $carried["{$name}_from"] = $other['from'];
            $carried["{$name}_to"] = $other['to'];
        }
    }

    // The default period needs no parameter, so choosing it is also the reset.
    $presetUrl = fn (string $value): string => route(
        'dashboard',
        $value === $default ? $carried : [...$carried, "{$panel}_period" => $value],
    ).'#panel-'.$panel;

    // A rejected period's message is shown in the panel by <x-dashboard.period-error>.
    $hasError = $errors->hasAny(["{$panel}_period", "{$panel}_from", "{$panel}_to"]);
@endphp

<div class="dropdown dashboard-period">
    <button id="{{ $panel }}-period-toggle" type="button"
            class="btn btn-outline-secondary dashboard-period__toggle dropdown-toggle"
            data-bs-toggle="dropdown" data-bs-auto-close="outside"
            data-bs-popper-config='{"strategy":"fixed"}'
            aria-expanded="false">
        <span class="visually-hidden">Reporting period for {{ $label }}:</span>
        {{ $scope['short_badge'] }}
    </button>

    <div class="dropdown-menu dropdown-menu-end dashboard-period__menu">
        @foreach ($options as $value => $optionLabel)
            @continue((string) $value === 'custom')
            @php($active = $scope['period'] === (string) $value)
            <a href="{{ $presetUrl((string) $value) }}"
               class="dropdown-item dashboard-period__option {{ $active ? 'active' : '' }}"
               data-dashboard-period-link
               @if ($active) aria-current="true" @endif>
                <span>{{ $optionLabel }}</span>
                @if ($active)
                    <i class="icon-base bx bx-check" aria-hidden="true"></i>
                @endif
            </a>
        @endforeach

        <div class="dropdown-divider"></div>

        <form method="GET" action="{{ route('dashboard') }}#panel-{{ $panel }}"
              class="dashboard-period__custom" data-dashboard-filter
              aria-labelledby="{{ $panel }}-custom-title">
            @foreach ($carried as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <input type="hidden" name="{{ $panel }}_period" value="custom">

            <span id="{{ $panel }}-custom-title" class="dashboard-period__custom-title">
                Custom dates
                @if ($scope['period'] === 'custom')
                    <i class="icon-base bx bx-check" aria-hidden="true"></i>
                @endif
            </span>

            <div class="dashboard-period__dates">
                <div>
                    <label for="{{ $panel }}-from" class="form-label">From</label>
                    <input id="{{ $panel }}-from" type="date" name="{{ $panel }}_from" required
                           class="form-control form-control-sm @error($panel.'_from') is-invalid @enderror"
                           value="{{ $form['from'] }}" min="{{ $earliest }}" max="{{ $today }}"
                           data-dashboard-from
                           @if ($hasError) aria-describedby="{{ $panel }}-period-error" @endif>
                </div>
                <div>
                    <label for="{{ $panel }}-to" class="form-label">To</label>
                    <input id="{{ $panel }}-to" type="date" name="{{ $panel }}_to" required
                           class="form-control form-control-sm @error($panel.'_to') is-invalid @enderror"
                           value="{{ $form['to'] }}" min="{{ $earliest }}" max="{{ $today }}"
                           data-dashboard-to
                           @if ($hasError) aria-describedby="{{ $panel }}-period-error" @endif>
                </div>
            </div>

            <button type="submit" class="btn btn-sm btn-primary w-100">Apply dates</button>
        </form>

        <div class="dropdown-divider"></div>

        <p class="dashboard-period__note">
            Showing <strong>{{ $scope['label'] }}</strong>
            <span>{{ $scope['timezone'] }} time</span>
        </p>
    </div>
</div>
