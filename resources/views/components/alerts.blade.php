{{--
    Dismissible session alerts and unhandled validation errors.
    Expected on every authenticated page, beneath the navbar.
--}}
@php
    $levels = [
        'success' => ['alert-success', 'bx-check-circle'],
        'error' => ['alert-danger', 'bx-error-circle'],
        'warning' => ['alert-warning', 'bx-error'],
        'info' => ['alert-info', 'bx-info-circle'],
    ];
@endphp

@foreach ($levels as $key => [$class, $icon])
    @if (session()->has($key))
        <div class="alert {{ $class }} fade show d-flex align-items-center" role="alert"
             data-auto-dismiss="{{ $key === 'success' ? 2500 : 6000 }}">
            <i class="icon-base bx {{ $icon }} icon-md me-2"></i>
            <div>{{ session($key) }}</div>
        </div>
    @endif
@endforeach

{{-- Validation errors not tied to a specific field. --}}
@if ($errors->any() && ! $errors->hasAny(['email', 'password', 'current_password']))
    <div class="alert alert-danger fade show" role="alert" data-auto-dismiss="6000">
        <ul class="mb-0 ps-3">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

{{-- Messages slide up into place when the page loads, and slide down as they leave. --}}
<style>
    @keyframes alert-slide-up {
        from { opacity: 0; transform: translateY(24px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @keyframes alert-slide-down {
        from { opacity: 1; transform: translateY(0); }
        to { opacity: 0; transform: translateY(24px); }
    }
    .alert[role="alert"] { animation: alert-slide-up .45s cubic-bezier(.22, 1, .36, 1) both; }
    .alert[role="alert"].alert-leaving { animation: alert-slide-down .4s cubic-bezier(.64, 0, .78, 0) both; pointer-events: none; }
    @media (prefers-reduced-motion: reduce) { .alert[role="alert"], .alert[role="alert"].alert-leaving { animation: none; } }
</style>

<script>
    (() => {
        // Slide the message down, then remove it. Used by the timer and the × button alike.
        const leave = (alert) => {
            if (alert.classList.contains('alert-leaving')) return;
            alert.classList.add('alert-leaving');
            setTimeout(() => alert.remove(), 400);
        };
        document.querySelectorAll('.alert[role="alert"]').forEach((alert) => {
            alert.querySelector('.btn-close')?.addEventListener('click', (event) => {
                event.stopImmediatePropagation();
                event.preventDefault();
                leave(alert);
            }, true);
            if (alert.dataset.autoDismiss) setTimeout(() => leave(alert), Number(alert.dataset.autoDismiss));
        });
    })();
</script>
