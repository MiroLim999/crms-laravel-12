{{--
    Authenticated shell.

    The class names and element ids here form the DOM contract that SNEAT's
    harvested menu.js / main.js depend on (layout-wrapper, layout-menu,
    layout-navbar, content-wrapper, drag-target). Renaming them breaks the
    sidebar collapse and scrollbar behaviour.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      class="layout-menu-fixed-offcanvas layout-compact"
      dir="ltr"
      data-skin="default"
      data-bs-theme="light"
      data-template="vertical-menu-template">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    {{-- Restore the desktop sidebar preference before the first paint. --}}
    <script>
        try {
            if (window.matchMedia('(min-width: 1200px)').matches
                && window.localStorage.getItem('crms.sidebar.hidden') === 'true') {
                document.documentElement.classList.add('layout-menu-collapsed');
            }
        } catch (error) {
            // Storage can be unavailable in private or locked-down browsers.
        }
    </script>

    <title>@yield('title', 'Dashboard') · {{ config('app.name') }}</title>

    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('assets/img/favicon-32.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('assets/img/apple-touch-icon.png') }}">

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

    @stack('styles')
</head>

<body class="@yield('body-class')">
    <div class="layout-wrapper layout-content-navbar">
        <div class="layout-container">

            @include('layouts.partials.sidebar')

            <button type="button"
                    class="btn btn-sm btn-icon btn-outline-secondary rounded-pill layout-menu-toggle global-sidebar-toggle"
                    data-menu-toggle-control
                    aria-controls="layout-menu"
                    aria-expanded="true"
                    aria-label="Collapse navigation">
                <i class="icon-base bx bx-chevron-left" aria-hidden="true"></i>
            </button>

            <div class="layout-page">
                @hasSection('workspace-navbar')
                    @yield('workspace-navbar')
                @else
                    @include('layouts.partials.navbar')
                @endif

                <div class="content-wrapper">
                    <div class="container-xxl flex-grow-1 container-p-y">
                        <x-alerts />
                        @yield('content')
                    </div>

                    @include('layouts.partials.footer')

                    <div class="content-backdrop fade"></div>
                </div>
            </div>
        </div>

        <div class="layout-overlay layout-menu-toggle"></div>
        <div class="drag-target"></div>
    </div>

    @stack('scripts')
</body>
</html>
