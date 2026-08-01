@extends('layouts.app')

@section('title', 'User Accounts')

@section('content')
    <x-page-header title="User Accounts"
                   subtitle="Provision accounts and manage access. There is no public sign-up.">
        <a href="{{ route('users.create') }}" class="btn btn-primary">
            <i class="icon-base bx bx-plus icon-sm me-1"></i> New Account
        </a>
    </x-page-header>

    {{-- One-time display of a generated temporary password. --}}
    @if ($provisioned = session('provisioned'))
        <div class="alert alert-success" role="alert">
            <h6 class="alert-heading mb-1">
                {{ ($provisioned['reset'] ?? false) ? 'New temporary password issued' : 'Account created' }}
            </h6>
            <p class="mb-2">
                Give this password to <strong>{{ $provisioned['name'] }}</strong>
                ({{ $provisioned['email'] }}). They will be required to change it when they sign in.
            </p>
            <div class="d-flex align-items-center gap-2">
                <code class="fs-5 px-2 py-1 bg-white rounded border" id="temp-password">{{ $provisioned['password'] }}</code>
                <button type="button" class="btn btn-sm btn-outline-primary"
                        onclick="navigator.clipboard.writeText(document.getElementById('temp-password').textContent.trim())">
                    Copy
                </button>
            </div>
            <p class="mb-0 mt-2 small text-muted">
                This is shown once and is not recoverable. Issue a new one if it is lost.
            </p>
        </div>
    @endif

    <x-card>
        <form method="GET" action="{{ route('users.index') }}" class="row g-3 mb-4">
            <div class="col-md-5">
                <input type="search" name="q" value="{{ request('q') }}"
                       class="form-control" placeholder="Search name or email">
            </div>
            <div class="col-md-3">
                <select name="role" class="form-select">
                    <option value="">All roles</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role->slug->value }}"
                                @selected(request('role') === $role->slug->value)>{{ $role->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select name="status" class="form-select">
                    <option value="">Any status</option>
                    <option value="active" @selected(request('status') === 'active')>Active</option>
                    <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
                </select>
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-outline-secondary" type="submit">Filter</button>
            </div>
        </form>

        @if ($users->isEmpty())
            <x-empty-state icon="bx-user-x" title="No accounts match"
                           message="Adjust the filters, or create a new account." />
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Account</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Last sign-in</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $account)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="avatar avatar-sm">
                                            <span class="avatar-initial rounded-circle bg-label-secondary">
                                                {{ $account->initials() }}
                                            </span>
                                        </div>
                                        <div>
                                            <div class="fw-medium">{{ $account->name }}</div>
                                            <small class="text-muted">{{ $account->email }}</small>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge bg-label-primary">{{ $account->role->name }}</span></td>
                                <td>
                                    @if (! $account->is_active)
                                        <span class="badge bg-label-danger">Inactive</span>
                                    @elseif ($account->must_change_password)
                                        <span class="badge bg-label-warning">Awaiting first sign-in</span>
                                    @else
                                        <span class="badge bg-label-success">Active</span>
                                    @endif
                                </td>
                                <td class="text-muted">
                                    {{ $account->last_login_at?->diffForHumans() ?? 'Never' }}
                                </td>
                                <td class="text-end">
                                    @can('users.update', $account)
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-icon btn-text-secondary rounded-pill dropdown-toggle hide-arrow"
                                                    data-bs-toggle="dropdown" aria-label="Actions">
                                                <i class="icon-base bx bx-dots-vertical-rounded"></i>
                                            </button>
                                            <div class="dropdown-menu dropdown-menu-end">
                                                <a class="dropdown-item" href="{{ route('users.edit', $account) }}">
                                                    <i class="icon-base bx bx-edit-alt icon-sm me-2"></i> Edit
                                                </a>

                                                <form method="POST" action="{{ route('users.password.reset', $account) }}">
                                                    @csrf
                                                    <button type="submit" class="dropdown-item">
                                                        <i class="icon-base bx bx-key icon-sm me-2"></i>
                                                        Issue temporary password
                                                    </button>
                                                </form>

                                                @if ($account->is_active)
                                                    @can('users.deactivate', $account)
                                                        <div class="dropdown-divider"></div>
                                                        <form method="POST" action="{{ route('users.deactivate', $account) }}">
                                                            @csrf
                                                            <button type="submit" class="dropdown-item text-danger">
                                                                <i class="icon-base bx bx-user-x icon-sm me-2"></i> Deactivate
                                                            </button>
                                                        </form>
                                                    @endcan
                                                @else
                                                    <div class="dropdown-divider"></div>
                                                    <form method="POST" action="{{ route('users.activate', $account) }}">
                                                        @csrf
                                                        <button type="submit" class="dropdown-item text-success">
                                                            <i class="icon-base bx bx-user-check icon-sm me-2"></i> Reactivate
                                                        </button>
                                                    </form>
                                                @endif
                                            </div>
                                        </div>
                                    @else
                                        <span class="text-muted small">—</span>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $users->links() }}
        @endif
    </x-card>
@endsection
