@extends('layouts.guest')

@section('title', 'Set up CRMS')
@section('heading', 'Set up CRMS')
@section('subheading', 'Make the Super Admin account. This page is used once.')

@section('content')
    @if (! $allowed)
        <div class="alert alert-warning mb-0" role="alert">
            For safety, the account can only be made on the CRMS computer itself. Open this page there.
            Other computers can sign in once it is made.
        </div>
    @else
        <form method="POST" action="{{ route('setup.store') }}" class="mb-2">
            @csrf

            @if ($needsToken)
                <div class="mb-6">
                    <label for="setup_token" class="form-label">Setup token</label>
                    <input type="text" id="setup_token" name="setup_token" maxlength="64"
                        class="form-control font-monospace @error('setup_token') is-invalid @enderror"
                        placeholder="ABCD-EFGH-JKMN" required autofocus autocomplete="off" spellcheck="false"
                        autocapitalize="characters">
                    @error('setup_token')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <div class="form-text">
                        Proves you are at the CRMS computer. See it there with
                        <code>docker compose exec web php artisan crms:setup-token</code>.
                    </div>
                </div>
            @endif

            <div class="mb-6">
                <label for="name" class="form-label">Your name</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}"
                    class="form-control @error('name') is-invalid @enderror" required @unless ($needsToken) autofocus @endunless autocomplete="name">
                @error('name')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-6">
                <label for="email" class="form-label">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}"
                    class="form-control @error('email') is-invalid @enderror" placeholder="you@example.com" required
                    autocomplete="username">
                @error('email')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="form-text">You sign in with this. It does not need to receive mail.</div>
            </div>

            <div class="mb-6 form-password-toggle">
                <label for="password" class="form-label">Password</label>
                <div class="input-group input-group-merge">
                    <input type="password" id="password" name="password"
                        class="form-control @error('password') is-invalid @enderror"
                        placeholder="&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;" required
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
                <label for="password_confirmation" class="form-label">Confirm password</label>
                <div class="input-group input-group-merge">
                    <input type="password" id="password_confirmation" name="password_confirmation" class="form-control"
                        placeholder="&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;" required
                        autocomplete="new-password">
                    <span class="input-group-text cursor-pointer">
                        <i class="icon-base bx bx-hide"></i>
                    </span>
                </div>
            </div>

            <button class="btn btn-primary d-grid w-100" type="submit">Create Account</button>
        </form>
    @endif
@endsection
