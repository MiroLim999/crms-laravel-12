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
        <div class="alert {{ $class }} alert-dismissible fade show d-flex align-items-center" role="alert">
            <i class="icon-base bx {{ $icon }} icon-md me-2"></i>
            <div>{{ session($key) }}</div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif
@endforeach

{{-- Validation errors not tied to a specific field. --}}
@if ($errors->any() && ! $errors->hasAny(['email', 'password', 'current_password']))
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <ul class="mb-0 ps-3">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif
