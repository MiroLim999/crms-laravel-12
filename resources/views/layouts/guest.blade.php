{{-- Unauthenticated shell: sign-in and the forced password change. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      class="layout-blank"
      dir="ltr"
      data-skin="default"
      data-bs-theme="light">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    <title>@yield('title', 'Sign In') · {{ config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap"
          rel="stylesheet">

    @vite([
        'resources/fonts/iconify/iconify.css',
        'resources/scss/app.scss',
        'resources/css/app.css',
        'resources/js/app.js',
    ])
</head>

<body>
    <div class="container-xxl">
        <div class="authentication-wrapper authentication-basic container-p-y">
            <div class="authentication-inner">
                <div class="card">
                    <div class="card-body">

                        <div class="app-brand justify-content-center mb-4">
                            <span class="app-brand-link gap-2">
                                <span class="d-flex align-items-center justify-content-center rounded text-white fw-bold"
                                      style="width: 2.5rem; height: 2.5rem; background-color: #696cff;">
                                    CR
                                </span>
                                <span class="app-brand-text fw-bold ms-2 fs-4 text-heading">CRMS</span>
                            </span>
                        </div>

                        <h4 class="mb-1">@yield('heading')</h4>
                        <p class="mb-4 text-muted">@yield('subheading')</p>

                        <x-alerts />

                        @yield('content')
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
