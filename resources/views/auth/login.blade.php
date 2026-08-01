@extends('layouts.guest')

@section('title', 'Sign In')
@section('heading', 'Welcome to CRMS')
@section('subheading', 'Sign in to continue to the civil registry.')

@section('content')
    <form method="POST" action="{{ route('login.store') }}">
        @csrf

        <div class="mb-3">
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

        <div class="mb-3 form-password-toggle">
            <label for="password" class="form-label">Password</label>
            <div class="input-group input-group-merge @error('password') is-invalid @enderror">
                <input type="password"
                       id="password"
                       name="password"
                       class="form-control @error('password') is-invalid @enderror"
                       placeholder="&#183;&#183;&#183;&#183;&#183;&#183;&#183;&#183;&#183;&#183;&#183;&#183;"
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

        <div class="mb-3">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1">
                <label class="form-check-label" for="remember">Remember me</label>
            </div>
        </div>

        <button class="btn btn-primary d-grid w-100" type="submit">Sign In</button>
    </form>

    <p class="text-center text-muted small mt-4 mb-0">
        Accounts are issued by your administrator. There is no public sign-up.
    </p>
@endsection
