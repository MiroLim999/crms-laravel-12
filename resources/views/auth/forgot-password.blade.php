@extends('layouts.guest')

@section('title', 'Forgot Password')

@if ($verifiedEmail)
    @section('heading', 'Choose a new password')
    @section('subheading', 'Your recovery code is accepted. The code is used up when you save the new password.')
@else
    @section('heading', 'Forgot your password?')
    @section('subheading', 'Enter your email and one of your recovery codes.')
@endif

@section('content')
    @if ($verifiedEmail)
        {{-- Step 2: the code was accepted; now the new password. --}}
        <form method="POST" action="{{ route('password.recover.reset') }}" class="mb-6" novalidate>
            @csrf
            <input type="text" name="email" value="{{ $verifiedEmail }}" autocomplete="username" hidden>

            <div class="alert alert-success d-flex align-items-center gap-2 small mb-6" role="status">
                <i class="icon-base bx bx-check-circle" aria-hidden="true"></i>
                <span>Code accepted for <strong>{{ $verifiedEmail }}</strong></span>
            </div>

            <div class="mb-6 form-password-toggle">
                <label class="form-label" for="password">New password</label>
                <div class="input-group input-group-merge @error('password') is-invalid @enderror">
                    <input type="password" id="password" name="password" class="form-control" required autofocus
                        autocomplete="new-password">
                    <span class="input-group-text cursor-pointer">
                        <i class="icon-base bx bx-hide"></i>
                    </span>
                </div>
                @error('password')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
                <div class="form-text">At least 8 characters, including a letter and a number.</div>
            </div>

            <div class="mb-8 form-password-toggle">
                <label class="form-label" for="password_confirmation">Confirm new password</label>
                <div class="input-group input-group-merge">
                    <input type="password" id="password_confirmation" name="password_confirmation" class="form-control" required
                        autocomplete="new-password">
                    <span class="input-group-text cursor-pointer">
                        <i class="icon-base bx bx-hide"></i>
                    </span>
                </div>
            </div>

            <div class="mb-6">
                <button class="btn btn-primary d-grid w-100" type="submit">Change Password</button>
            </div>

            <p class="text-center mb-0">
                <a href="{{ route('password.recover', ['start-over' => 1]) }}">Use another code</a>
                <span class="text-muted mx-2">·</span>
                <a href="{{ route('login') }}">Back to sign in</a>
            </p>
        </form>
    @else
        {{-- Step 1: email and code. novalidate: the page's own messages show under each field. --}}
        <form method="POST" action="{{ route('password.recover.store') }}" class="mb-6" novalidate>
            @csrf

            <div class="mb-6">
                <label for="email" class="form-label">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}"
                    class="form-control @error('email') is-invalid @enderror" placeholder="you@example.com" required autofocus
                    autocomplete="username">
                @error('email')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-8">
                <label for="code" class="form-label">Recovery code</label>
                <input type="text" id="code" name="code" maxlength="32" autocapitalize="characters"
                    class="form-control @error('code') is-invalid @enderror" placeholder="ABCDE-12345" required
                    autocomplete="one-time-code" spellcheck="false">
                @error('code')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="form-text">One of the 10 codes you made in Account Settings.</div>
            </div>

            <div class="mb-6">
                <button class="btn btn-primary d-grid w-100" type="submit">Continue</button>
            </div>

            <p class="text-center mb-0">
                <a href="{{ route('login') }}">Back to sign in</a>
            </p>
        </form>
    @endif
@endsection
