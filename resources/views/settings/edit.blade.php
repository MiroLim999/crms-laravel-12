@extends('layouts.app')

@section('title', 'Account Settings')

@section('content')
    <x-page-header title="Account Settings" subtitle="Manage your own credentials." />

    <div class="row g-4">
        <div class="col-lg-5">
            <x-card title="Your account">
                <dl class="row mb-0">
                    <dt class="col-5 text-muted fw-normal">Name</dt>
                    <dd class="col-7">{{ $user->name }}</dd>

                    <dt class="col-5 text-muted fw-normal">Email</dt>
                    <dd class="col-7">{{ $user->email }}</dd>

                    <dt class="col-5 text-muted fw-normal">Role</dt>
                    <dd class="col-7">
                        <span class="badge bg-label-primary">{{ $user->role->name }}</span>
                    </dd>

                    <dt class="col-5 text-muted fw-normal">Password changed</dt>
                    <dd class="col-7">
                        {{ $user->password_changed_at?->diffForHumans() ?? 'Never' }}
                    </dd>

                    <dt class="col-5 text-muted fw-normal">Last sign-in</dt>
                    <dd class="col-7 mb-0">
                        {{ $user->last_login_at?->diffForHumans() ?? '—' }}
                    </dd>
                </dl>

                <hr>
                <p class="small text-muted mb-0">
                    Your name, email, and role are managed by an administrator.
                </p>
            </x-card>
        </div>

        <div class="col-lg-7">
            <x-card title="Change password">
                <form method="POST" action="{{ route('settings.password.update') }}">
                    @csrf
                    @method('PUT')

                    <div class="mb-3">
                        <label for="current_password" class="form-label">Current password</label>
                        <input type="password"
                               id="current_password"
                               name="current_password"
                               class="form-control @error('current_password') is-invalid @enderror"
                               required
                               autocomplete="current-password">
                        @error('current_password')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">New password</label>
                        <input type="password"
                               id="password"
                               name="password"
                               class="form-control @error('password') is-invalid @enderror"
                               required
                               autocomplete="new-password">
                        @error('password')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">At least 8 characters, including a letter and a number.</div>
                    </div>

                    <div class="mb-4">
                        <label for="password_confirmation" class="form-label">Confirm new password</label>
                        <input type="password"
                               id="password_confirmation"
                               name="password_confirmation"
                               class="form-control"
                               required
                               autocomplete="new-password">
                    </div>

                    <button type="submit" class="btn btn-primary">Update Password</button>
                </form>
            </x-card>
        </div>
    </div>
@endsection
