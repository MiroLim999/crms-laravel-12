@extends('layouts.guest')

@section('title', 'Authenticator code')
@section('heading', 'Enter your code')
@section('subheading', 'Open the authenticator app on your phone and type the 6-digit code it shows for CRMS.')

@section('content')
    {{-- novalidate: the page's own messages show under each field. --}}
    <form method="POST" action="{{ route('two-factor.challenge.store') }}" class="mb-6" novalidate>
        @csrf

        <div class="mb-6">
            <label for="code" class="form-label">Authenticator code</label>
            <input type="text" id="code" name="code" inputmode="numeric" maxlength="32"
                class="form-control form-control-lg font-monospace text-center @error('code') is-invalid @enderror"
                placeholder="123 456" autofocus autocomplete="one-time-code" spellcheck="false">
            @error('code')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
            <div class="form-text">A new code every 30 seconds. Each code works once.</div>
        </div>

        <details class="mb-6" @if ($errors->has('recovery_code')) open @endif>
            <summary class="mb-2">Phone not at hand? Use a recovery code</summary>
            <label for="recovery_code" class="form-label">Recovery code</label>
            <input type="text" id="recovery_code" name="recovery_code" maxlength="32" autocapitalize="characters"
                class="form-control font-monospace @error('recovery_code') is-invalid @enderror"
                placeholder="ABCDE-12345" autocomplete="off" spellcheck="false">
            @error('recovery_code')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
            <div class="form-text">One of the codes you made in Account Settings. It works once.</div>
        </details>

        <button class="btn btn-primary d-grid w-100" type="submit">Sign In</button>
    </form>

    <p class="text-center mb-0">
        <a href="{{ route('login') }}">Back to sign in</a>
    </p>
@endsection
