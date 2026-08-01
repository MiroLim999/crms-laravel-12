@props([
    'title' => null,
    'subtitle' => null,
    'bodyClass' => '',
])

<div {{ $attributes->merge(['class' => 'card']) }}>
    @if ($title || isset($actions))
        <div class="card-header d-flex align-items-start justify-content-between gap-3">
            <div>
                @if ($title)
                    <h5 class="card-title mb-0">{{ $title }}</h5>
                @endif
                @if ($subtitle)
                    <small class="text-muted">{{ $subtitle }}</small>
                @endif
            </div>

            @isset($actions)
                <div class="d-flex align-items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    <div class="card-body {{ $bodyClass }}">
        {{ $slot }}
    </div>
</div>
