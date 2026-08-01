@extends('layouts.app')

@section('title', 'New Account')

@section('content')
    <x-page-header title="New Account"
                   subtitle="A temporary password is generated and shown once after creation." />

    <div class="row">
        <div class="col-lg-7">
            <x-card>
                <form method="POST" action="{{ route('users.store') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="name" class="form-label">Full name</label>
                        <input type="text" id="name" name="name" value="{{ old('name') }}"
                               class="form-control @error('name') is-invalid @enderror" required autofocus>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" id="email" name="email" value="{{ old('email') }}"
                               class="form-control @error('email') is-invalid @enderror" required>
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-4">
                        <label for="role" class="form-label">Role</label>
                        <select id="role" name="role"
                                class="form-select @error('role') is-invalid @enderror" required>
                            <option value="">Choose a role</option>
                            @foreach ($assignableRoles as $role)
                                <option value="{{ $role->slug->value }}" @selected(old('role') === $role->slug->value)>
                                    {{ $role->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">
                            @foreach ($assignableRoles as $role)
                                <div><strong>{{ $role->name }}</strong> — {{ $role->description }}</div>
                            @endforeach
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Create Account</button>
                        <a href="{{ route('users.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </x-card>
        </div>

        <div class="col-lg-5">
            <x-card title="How this works">
                <ol class="ps-3 mb-0 text-muted">
                    <li class="mb-2">The account is created with a generated temporary password.</li>
                    <li class="mb-2">You pass that password to the holder. It is displayed once and never stored in readable form.</li>
                    <li class="mb-2">On first sign-in they are locked to the change-password screen until they choose their own.</li>
                    <li>Every step is recorded in the audit log.</li>
                </ol>
            </x-card>
        </div>
    </div>
@endsection
