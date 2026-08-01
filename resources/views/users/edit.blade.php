@extends('layouts.app')

@section('title', 'Edit Account')

@section('content')
    <x-page-header :title="'Edit ' . $user->name" subtitle="{{ $user->email }}" />

    <div class="row">
        <div class="col-lg-7">
            <x-card>
                <form method="POST" action="{{ route('users.update', $user) }}">
                    @csrf
                    @method('PUT')

                    <div class="mb-3">
                        <label for="name" class="form-label">Full name</label>
                        <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}"
                               class="form-control @error('name') is-invalid @enderror" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}"
                               class="form-control @error('email') is-invalid @enderror" required>
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-4">
                        <label for="role" class="form-label">Role</label>
                        <select id="role" name="role"
                                class="form-select @error('role') is-invalid @enderror" required>
                            @foreach ($assignableRoles as $role)
                                <option value="{{ $role->slug->value }}"
                                        @selected(old('role', $user->roleSlug()->value) === $role->slug->value)>
                                    {{ $role->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <a href="{{ route('users.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </x-card>
        </div>

        <div class="col-lg-5">
            <x-card title="Account status">
                <dl class="row mb-0">
                    <dt class="col-6 fw-normal text-muted">Status</dt>
                    <dd class="col-6">
                        @if ($user->is_active)
                            <span class="badge bg-label-success">Active</span>
                        @else
                            <span class="badge bg-label-danger">Inactive</span>
                        @endif
                    </dd>

                    <dt class="col-6 fw-normal text-muted">Temporary password</dt>
                    <dd class="col-6">{{ $user->must_change_password ? 'Pending change' : 'No' }}</dd>

                    <dt class="col-6 fw-normal text-muted">Password changed</dt>
                    <dd class="col-6">{{ $user->password_changed_at?->diffForHumans() ?? 'Never' }}</dd>

                    <dt class="col-6 fw-normal text-muted">Last sign-in</dt>
                    <dd class="col-6">{{ $user->last_login_at?->diffForHumans() ?? 'Never' }}</dd>

                    <dt class="col-6 fw-normal text-muted">Created by</dt>
                    <dd class="col-6 mb-0">{{ $user->creator?->name ?? 'Seeded' }}</dd>
                </dl>

                <hr>

                <p class="small text-muted">
                    Passwords cannot be read or set directly. Issue a new temporary password
                    instead, and the holder chooses their own on next sign-in.
                </p>

                <form method="POST" action="{{ route('users.password.reset', $user) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="icon-base bx bx-key icon-sm me-1"></i> Issue temporary password
                    </button>
                </form>
            </x-card>
        </div>
    </div>
@endsection
