{{--
    Flash message renderer. Controllers set ->with('success'|'warning'|'error'|'info').
--}}
@php
    $levels = [
        'success' => ['alert-success', 'bx-check-circle'],
        'warning' => ['alert-warning', 'bx-error'],
        'error' => ['alert-danger', 'bx-x-circle'],
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

{{-- Auto-dismiss success alert after 4 seconds --}}
@if (session()->has('success'))
    <script>
        (function () {
            function autoDismissAlert() {
                const alerts = document.querySelectorAll('.alert-dismissible.alert-success');
                alerts.forEach(function (alertEl) {
                    setTimeout(function () {
                        if (window.bootstrap && window.bootstrap.Alert) {
                            const bsAlert = window.bootstrap.Alert.getOrCreateInstance(alertEl);
                            bsAlert.close();
                        } else {
                            alertEl.classList.remove('show');
                            setTimeout(function () { alertEl.remove(); }, 150);
                        }
                    }, 4000);
                });
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', autoDismissAlert);
            } else {
                autoDismissAlert();
            }
        })();
    </script>
@endif
