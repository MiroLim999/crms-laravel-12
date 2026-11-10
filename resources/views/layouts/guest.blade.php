{{--
Unauthenticated shell: sign-in and the forced password change.

Split-panel layout: a navy brand panel (civil registry seal) on the left and the
form on the right. Panel styling is scoped under .auth-shell below, so it does
not depend on page-auth.scss (no longer loaded here).
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="layout-wide" dir="ltr" data-skin="default"
    data-bs-theme="light" data-template="vertical-menu-template">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    @include('layouts.partials.theme-init')

    <title>@yield('title', 'Sign In') · {{ config('app.name') }}</title>

    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('assets/img/favicon-32.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('assets/img/apple-touch-icon.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap"
        rel="stylesheet">
    <link
        href="https://fonts.googleapis.com/css?family=Rubik:300,400,500,600,700%7CIBM+Plex+Sans:300,400,500,600,700&display=swap"
        rel="stylesheet">

    @vite([
        'resources/fonts/iconify/iconify.css',
        'resources/scss/app.scss',
        'resources/css/app.css',
        'resources/js/app.js',
    ])

    <style>
        .auth-shell {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3rem 1.5rem;
            background: #f4f6fa;
        }

        .auth-card {
            display: flex;
            width: 100%;
            max-width: 1040px;
            min-height: 600px;
            background: #fff;
            border-radius: 1.5rem;
            overflow: hidden;
            box-shadow: 0 24px 60px rgba(15, 36, 84, .14);
        }

        .auth-brand {
            position: relative;
            flex: 0 0 46%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 2.5rem;
            color: #fff;
            overflow: hidden;
            background:
                radial-gradient(circle at 50% 38%, rgba(59, 130, 246, .35), transparent 55%),
                linear-gradient(165deg, #12306b 0%, #0b1f4a 55%, #071535 100%);
        }

        .auth-brand::before,
        .auth-brand::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, .08);
            pointer-events: none;
        }

        .auth-brand::before {
            width: 520px;
            height: 520px;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -62%);
        }

        .auth-brand::after {
            width: 760px;
            height: 760px;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -58%);
        }

        .auth-brand>* {
            position: relative;
            z-index: 1;
        }

        .auth-brand-top {
            display: flex;
            align-items: center;
            gap: .75rem;
            font-weight: 600;
            letter-spacing: .06em;
            text-transform: uppercase;
            font-size: .8rem;
            line-height: 1.3;
        }

        .auth-brand-top img {
            width: 36px;
            height: 36px;
        }

        .auth-seal {
            display: flex;
            justify-content: center;
            padding: 1.5rem 0;
        }

        .auth-seal img {
            width: min(250px, 70%);
            height: auto;
            filter: drop-shadow(0 18px 30px rgba(0, 0, 0, .45));
        }

        .auth-eyebrow {
            font-size: .68rem;
            font-weight: 700;
            letter-spacing: .18em;
            text-transform: uppercase;
            color: #8fb7ff;
            margin-bottom: .6rem;
        }

        .auth-brand h2 {
            color: #fff;
            font-weight: 600;
            font-size: 1.9rem;
            line-height: 1.2;
            margin-bottom: .75rem;
        }

        .auth-brand p {
            color: rgba(255, 255, 255, .75);
            margin-bottom: 0;
            font-size: .92rem;
        }

        .auth-brand-foot {
            margin-top: 1.5rem;
            padding-top: 1.25rem;
            border-top: 1px solid rgba(255, 255, 255, .15);
            font-size: .78rem;
            color: rgba(255, 255, 255, .6);
        }

        .auth-form {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 3rem 3.25rem;
        }

        .auth-form .auth-kicker {
            font-size: .68rem;
            font-weight: 700;
            letter-spacing: .18em;
            text-transform: uppercase;
            color: #2f4a85;
            margin-bottom: .5rem;
        }

        /* Typed text (and browser autofill) must stay readable on the white inputs. */
        .auth-form .form-control,
        .auth-form .form-control:focus {
            color: #14274e;
            -webkit-text-fill-color: #14274e;
            background-color: #fff;
        }

        .auth-form .form-control::placeholder {
            color: #8a94ab;
            -webkit-text-fill-color: #8a94ab;
        }

        .auth-form .form-control:-webkit-autofill {
            -webkit-text-fill-color: #14274e;
            box-shadow: 0 0 0 1000px #fff inset;
        }

        .auth-form .btn-primary {
            background: #1a3466;
            border-color: #1a3466;
            padding: .85rem 1rem;
            border-radius: .6rem;
            font-weight: 600;
            box-shadow: 0 8px 18px rgba(26, 52, 102, .25);
        }

        .auth-form .btn-primary:hover {
            background: #12306b;
            border-color: #12306b;
        }

        @media (max-width: 860px) {
            .auth-shell {
                padding: 1rem;
                align-items: flex-start;
            }

            .auth-card {
                flex-direction: column;
                min-height: 0;
            }

            .auth-brand {
                flex: none;
                padding: 1.75rem;
            }

            .auth-seal img {
                width: 130px;
            }

            .auth-brand h2,
            .auth-brand p,
            .auth-brand-foot {
                display: none;
            }

            .auth-form {
                padding: 2rem 1.5rem;
            }
        }
    </style>
</head>

<body>
    {{-- authentication-wrapper is the hook _crms.scss uses for the login fonts (IBM Plex Sans / Rubik labels). --}}
    <div class="auth-shell authentication-wrapper">
        <div class="auth-card">

            <aside class="auth-brand">
                <div class="auth-brand-top">
                    <img src="{{ asset('assets/img/crms-logo.png') }}" alt="" aria-hidden="true">
                    <span>Civil Registry<br>Management System</span>
                </div>

                <div class="auth-seal">
                    <img src="{{ asset('assets/img/crms-logo.png') }}"
                        alt="Office of the City Civil Registrar, City of Maasin seal">
                </div>

                <div>
                    <div class="auth-eyebrow">Civil Registry Management System</div>
                    <h2>Every record, safely in one place.</h2>
                    <p>Scan civil registry documents, extract their data with OCR, and keep every record organized and searchable.</p>
                    <div class="auth-brand-foot">Office of the City Civil Registrar · City of Maasin</div>
                </div>
            </aside>

            <main class="auth-form" data-bs-theme="light">
                <h4 class="mb-1">@yield('heading')</h4>
                <p class="mb-6">@yield('subheading')</p>

                <x-alerts />

                @yield('content')
            </main>
        </div>
    </div>
</body>

</html>
