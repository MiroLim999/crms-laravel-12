@props([
    'icon' => 'bx-inbox',
    'title' => 'Nothing here yet',
    'message' => null,
])

<div class="text-center py-5">
    <i class="icon-base bx {{ $icon }} icon-lg text-muted mb-3 d-inline-block"></i>
    <h6 class="mb-1">{{ $title }}</h6>
    @if ($message)
        <p class="text-muted mb-3">{{ $message }}</p>
    @endif
    {{ $slot }}
</div>
