{{--
    Unauthenticated shell: sign-in and the forced password change.

    Structure mirrors SNEAT's auth-login-basic page. The
    authentication-wrapper / authentication-basic / authentication-inner nesting is
    what page-auth.scss hooks into to centre the card and draw the corner shapes —
    changing those class names flattens the page back to full width.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      class="layout-wide"
      dir="ltr"
      data-skin="default"
      data-bs-theme="light"
      data-template="vertical-menu-template">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    <title>@yield('title', 'Sign In') · {{ config('app.name') }}</title>

    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('assets/img/favicon-32.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('assets/img/apple-touch-icon.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap"
          rel="stylesheet">

    @vite([
        'resources/fonts/iconify/iconify.css',
        'resources/scss/app.scss',
        'resources/scss/pages/page-auth.scss',
        'resources/css/app.css',
        'resources/js/app.js',
    ])
</head>

<body>
    <div class="container-xxl">
        <div class="authentication-wrapper authentication-basic container-p-y">
            <div class="authentication-inner">
                <div class="card px-sm-6 px-0">
                    <div class="card-body">

                        <div class="app-brand justify-content-center mb-6">
                            <span class="app-brand-link gap-2">
                                <span class="app-brand-logo demo">
                                    <img src="{{ asset('assets/img/logo.png') }}" alt="{{ config('app.name') }} logo"
                                         width="40" height="40" class="d-block rounded">
                                </span>
                                <span class="app-brand-text demo text-heading fw-bold ms-2 fs-4">CRMS</span>
                            </span>
                        </div>

                        <h4 class="mb-1">@yield('heading')</h4>
                        <p class="mb-6">@yield('subheading')</p>

                        <x-alerts />

                        @yield('content')
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
