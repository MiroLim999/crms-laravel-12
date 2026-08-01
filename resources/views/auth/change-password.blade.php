@extends('layouts.guest')

@section('title', 'Change Password')
@section('heading', 'Choose a new password')
@section('subheading', 'Your account was issued a temporary password. Replace it to continue.')

@section('content')
    <form method="POST" action="{{ route('password.change.store') }}" class="mb-6">
        @csrf

        <div class="mb-6 form-password-toggle">
            <label for="current_password" class="form-label">Temporary password</label>
            <div class="input-group input-group-merge">
                <input type="password"
                       id="current_password"
                       name="current_password"
                       class="form-control @error('current_password') is-invalid @enderror"
                       placeholder="&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;"
                       required
                       autofocus
                       autocomplete="current-password">
                <span class="input-group-text cursor-pointer">
                    <i class="icon-base bx bx-hide"></i>
                </span>
            </div>
            @error('current_password')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-6 form-password-toggle">
            <label for="password" class="form-label">New password</label>
            <div class="input-group input-group-merge">
                <input type="password"
                       id="password"
                       name="password"
                       class="form-control @error('password') is-invalid @enderror"
                       placeholder="&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;"
                       required
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

        <div class="mb-6 form-password-toggle">
            <label for="password_confirmation" class="form-label">Confirm new password</label>
            <div class="input-group input-group-merge">
                <input type="password"
                       id="password_confirmation"
                       name="password_confirmation"
                       class="form-control"
                       placeholder="&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;"
                       required
                       autocomplete="new-password">
                <span class="input-group-text cursor-pointer">
                    <i class="icon-base bx bx-hide"></i>
                </span>
            </div>
        </div>

        <button class="btn btn-primary d-grid w-100 mb-3" type="submit">Update Password</button>
    </form>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="btn btn-outline-secondary d-grid w-100">Sign Out</button>
    </form>
@endsection
