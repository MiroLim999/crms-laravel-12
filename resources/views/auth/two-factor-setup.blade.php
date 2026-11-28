@extends('layouts.guest')

@section('title', 'Set up the authenticator app')
@section('heading', 'Set up two-factor sign-in')
@section('subheading', 'Signing in to CRMS needs your password and a code from your phone.')

@section('content')
    <ol class="ps-4 mb-6">
        <li class="mb-2">
            Install an authenticator app on your phone: <strong>Google Authenticator</strong>,
            Microsoft Authenticator or Authy. No internet is needed after that.
        </li>
        <li class="mb-2">In the app, add an account and scan this code:</li>
    </ol>

    {{-- Drawn by CRMS itself (App\Services\TwoFactor), offline. White behind it so a phone can read it in dark mode. --}}
    <div class="d-flex justify-content-center mb-4">
        <div class="p-2 rounded" style="background:#fff; line-height:0" aria-label="QR code to scan with the authenticator app" role="img">
            {!! $qrCode !!}
        </div>
    </div>

    <details class="mb-6">
        <summary>Cannot scan? Type this key into the app instead</summary>
        <p class="font-monospace text-center fs-5 my-3 user-select-all" style="letter-spacing:.06em">{{ $secret }}</p>
        <p class="form-text mb-0">Account: your email. Type: time-based. Keep this key private: it makes the codes.</p>
    </details>

    <form method="POST" action="{{ route('two-factor.setup.store') }}" class="mb-6" novalidate>
        @csrf
        <div class="mb-6">
            <label for="code" class="form-label">3. Type the 6-digit code the app now shows</label>
            <input type="text" id="code" name="code" inputmode="numeric" maxlength="32"
                class="form-control form-control-lg font-monospace text-center @error('code') is-invalid @enderror"
                placeholder="123 456" required autofocus autocomplete="one-time-code" spellcheck="false">
            @error('code')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <button class="btn btn-primary d-grid w-100" type="submit">Turn On</button>
    </form>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="btn btn-outline-secondary d-grid w-100">Sign Out</button>
    </form>
@endsection
