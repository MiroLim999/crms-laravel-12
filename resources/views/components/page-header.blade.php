@props([
    'title',
    'subtitle' => null,
])

<div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
    <div>
        <h4 class="mb-1">{{ $title }}</h4>
        @if ($subtitle)
            <p class="mb-0 text-muted">{{ $subtitle }}</p>
        @endif
    </div>

    @if (! $slot->isEmpty())
        <div class="d-flex align-items-center gap-2">
            {{ $slot }}
        </div>
    @endif
</div>
