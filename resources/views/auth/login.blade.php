@extends('layouts.guest')

@section('title', 'Sign In')
@section('heading', 'Welcome to CRMS')
@section('subheading', 'Sign in to continue to the civil registry.')

@section('content')
    <form method="POST" action="{{ route('login.store') }}" class="mb-6">
        @csrf

        <div class="mb-6">
            <label for="email" class="form-label">Email</label>
            <input type="email"
                   id="email"
                   name="email"
                   value="{{ old('email') }}"
                   class="form-control @error('email') is-invalid @enderror"
                   placeholder="you@example.com"
                   required
                   autofocus
                   autocomplete="username">
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        {{-- form-password-toggle is what helpers.js binds the eye icon to. --}}
        <div class="mb-6 form-password-toggle">
            <label class="form-label" for="password">Password</label>
            <div class="input-group input-group-merge @error('password') is-invalid @enderror">
                <input type="password"
                       id="password"
                       name="password"
                       class="form-control"
                       placeholder="&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;"
                       aria-describedby="password"
                       required
                       autocomplete="current-password">
                <span class="input-group-text cursor-pointer">
                    <i class="icon-base bx bx-hide"></i>
                </span>
            </div>
            @error('password')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-8">
            <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1"
                       @checked(old('remember'))>
                <label class="form-check-label" for="remember">Remember me</label>
            </div>
        </div>

        <div class="mb-6">
            <button class="btn btn-primary d-grid w-100" type="submit">Sign In</button>
        </div>
    </form>

    {{-- No "create an account" link: accounts are provisioned by an administrator. --}}
    <p class="text-center text-muted small mb-0">
        Accounts are issued by your administrator. There is no public sign-up.
    </p>
@endsection
