@extends('layouts.app')

@section('title', 'Account Settings')

@php
    $detailsLocked = $user->details_locked_at !== null;
    $codesLeft = $user->recoveryCodesLeft();
    $codesMade = $user->recovery_codes_generated_at !== null;
    $codeTotal = \App\Models\User::RECOVERY_CODE_COUNT;
    $codeTone = ! $codesMade ? 'secondary' : ($codesLeft === 0 ? 'danger' : ($codesLeft <= 2 ? 'warning' : 'success'));
@endphp

@push('styles')
    <style>
        .settings-summary .avatar-initial { font-size: 1.25rem; }
        .settings-meta { display: flex; flex-wrap: wrap; gap: .5rem 1.5rem; }
        .settings-meta__item { display: flex; align-items: center; gap: .4rem; font-size: .8125rem; }
        .settings-meta__item i { opacity: .7; }
        .recovery-codes { display: grid; grid-template-columns: repeat(auto-fill, minmax(7.5rem, 1fr)); gap: .5rem; }
        .recovery-codes li {
            border: 1px dashed var(--bs-border-color); border-radius: .5rem; padding: .5rem 0;
            text-align: center; font-family: var(--bs-font-monospace); font-size: 1.125rem; letter-spacing: .08em;
            background: var(--bs-tertiary-bg, transparent);
        }
        /* Print: only the new codes. */
        @media print {
            body * { visibility: hidden; }
            #recoveryCodePrint, #recoveryCodePrint * { visibility: visible; }
            #recoveryCodePrint { position: absolute; inset: 0 auto auto 0; width: 100%; }
            #recoveryCodePrint .d-print-none { display: none !important; }
        }
    </style>
@endpush

@section('content')
    <x-page-header title="Account Settings" subtitle="Your name, email, password and recovery codes." />

    {{-- Who is signed in, at a glance. --}}
    <div class="card settings-summary mb-4">
        <div class="card-body d-flex flex-wrap align-items-center gap-4">
            <div class="avatar avatar-lg flex-shrink-0">
                <span class="avatar-initial rounded-circle bg-label-primary">{{ $user->initials() }}</span>
            </div>
            <div class="flex-grow-1 min-w-0">
                <h5 class="mb-1 text-truncate">{{ $user->name }}</h5>
                <div class="text-muted small text-truncate mb-2">{{ $user->email }}</div>
                <div class="settings-meta text-muted">
                    <span class="settings-meta__item">
                        <i class="icon-base bx bx-log-in icon-sm" aria-hidden="true"></i>
                        Last sign-in {{ $user->last_login_at?->diffForHumans() ?? '—' }}
                    </span>
                    <span class="settings-meta__item">
                        <i class="icon-base bx bx-key icon-sm" aria-hidden="true"></i>
                        Password changed {{ $user->password_changed_at?->diffForHumans() ?? 'never' }}
                    </span>
                    <span class="settings-meta__item">
                        <i class="icon-base bx bx-shield-quarter icon-sm" aria-hidden="true"></i>
                        {{ $codesMade ? "{$codesLeft} of {$codeTotal} recovery codes left" : 'No recovery codes yet' }}
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <x-card title="Your details" subtitle="The name shown in CRMS and the email you sign in with." class="h-100">
                @if ($detailsLocked)
                    <x-slot:actions>
                        <span class="badge bg-label-secondary"><i class="icon-base bx bx-lock-alt icon-xs me-1" aria-hidden="true"></i>Locked</span>
                    </x-slot:actions>
                @endif

                {{-- Name and email can be changed once: the first save that changes them locks both. --}}
                <form method="POST" action="{{ route('settings.profile.update') }}" class="d-flex flex-column h-100"
                      @unless ($detailsLocked)
                          onsubmit="return confirm('Your name and email can be changed only once. After saving, they cannot be edited again. Save now?')"
                      @endunless>
                    @csrf
                    @method('PUT')

                    <div class="mb-3">
                        <label for="name" class="form-label">Name</label>
                        <input type="text"
                               id="name"
                               name="name"
                               value="{{ old('name', $user->name) }}"
                               maxlength="255"
                               class="form-control @error('name') is-invalid @enderror"
                               required
                               @disabled($detailsLocked)
                               autocomplete="name">
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-4">
                        <label for="email" class="form-label">Email</label>
                        <input type="email"
                               id="email"
                               name="email"
                               value="{{ old('email', $user->email) }}"
                               maxlength="255"
                               class="form-control @error('email') is-invalid @enderror"
                               required
                               @disabled($detailsLocked)
                               autocomplete="email">
                        @error('email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">You sign in with this email.</div>
                    </div>

                    <div class="mt-auto">
                        @if ($detailsLocked)
                            <div class="alert alert-secondary d-flex align-items-start gap-2 mb-0 small" role="status">
                                <i class="icon-base bx bx-lock-alt mt-1" aria-hidden="true"></i>
                                <span>Locked: changed once, {{ $user->details_locked_at->diffForHumans() }}. They can no longer be edited here.</span>
                            </div>
                        @else
                            <div class="alert alert-warning d-flex align-items-start gap-2 mb-4 small" role="note">
                                <i class="icon-base bx bx-error mt-1" aria-hidden="true"></i>
                                <span>You can change your name and email only once. Check them before saving.</span>
                            </div>
                            <button type="submit" class="btn btn-primary">Save details</button>
                        @endif
                    </div>
                </form>
            </x-card>
        </div>

        <div class="col-lg-6">
            <x-card title="Change password" subtitle="Use one you do not use anywhere else." class="h-100">
                <form method="POST" action="{{ route('settings.password.update') }}" class="d-flex flex-column h-100">
                    @csrf
                    @method('PUT')

                    <div class="mb-3 form-password-toggle">
                        <label for="current_password" class="form-label">Current password</label>
                        <div class="input-group input-group-merge @error('current_password') is-invalid @enderror">
                            <input type="password"
                                   id="current_password"
                                   name="current_password"
                                   class="form-control @error('current_password') is-invalid @enderror"
                                   required
                                   autocomplete="current-password">
                            <span class="input-group-text cursor-pointer" title="Show or hide">
                                <i class="icon-base bx bx-hide" aria-hidden="true"></i>
                            </span>
                        </div>
                        @error('current_password')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-sm-6 form-password-toggle">
                            <label for="password" class="form-label">New password</label>
                            <div class="input-group input-group-merge @error('password') is-invalid @enderror">
                                <input type="password"
                                       id="password"
                                       name="password"
                                       class="form-control @error('password') is-invalid @enderror"
                                       required
                                       autocomplete="new-password">
                                <span class="input-group-text cursor-pointer" title="Show or hide">
                                    <i class="icon-base bx bx-hide" aria-hidden="true"></i>
                                </span>
                            </div>
                            @error('password')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-sm-6 form-password-toggle">
                            <label for="password_confirmation" class="form-label">Confirm new password</label>
                            <div class="input-group input-group-merge">
                                <input type="password"
                                       id="password_confirmation"
                                       name="password_confirmation"
                                       class="form-control"
                                       required
                                       autocomplete="new-password">
                                <span class="input-group-text cursor-pointer" title="Show or hide">
                                    <i class="icon-base bx bx-hide" aria-hidden="true"></i>
                                </span>
                            </div>
                        </div>
                        <div class="col-12 form-text mt-1">At least 8 characters, including a letter and a number.</div>
                    </div>

                    <div class="mt-auto">
                        <button type="submit" class="btn btn-primary">Update Password</button>
                    </div>
                </form>
            </x-card>
        </div>
    </div>

    <x-card title="Recovery codes" subtitle="For a forgotten password: each 6-digit code works once.">
        <x-slot:actions>
            <span class="badge bg-label-{{ $codeTone }}">
                {{ $codesMade ? "{$codesLeft} of {$codeTotal} left" : 'None yet' }}
            </span>
        </x-slot:actions>

        @if (session('recovery_codes'))
            <div id="recoveryCodePrint" class="mb-4">
                <div class="alert alert-warning d-flex align-items-start gap-2 small d-print-none" role="alert">
                    <i class="icon-base bx bx-error mt-1" aria-hidden="true"></i>
                    <span><strong>Write these down or print them now.</strong> They will not be shown again.</span>
                </div>
                <h6 class="d-none d-print-block mb-3">CRMS recovery codes · {{ $user->email }} · {{ now()->format('M j, Y') }}</h6>
                <ol class="recovery-codes list-unstyled mb-3" id="recoveryCodeList">
                    @foreach (session('recovery_codes') as $code)
                        {{-- The gap is CSS, not a character, so a copied code is just its 6 digits. --}}
                        <li><span class="me-2">{{ substr($code, 0, 3) }}</span>{{ substr($code, 3) }}</li>
                    @endforeach
                </ol>
                <button type="button" class="btn btn-outline-secondary btn-sm d-print-none" onclick="window.print()">
                    <i class="icon-base bx bx-printer me-1" aria-hidden="true"></i>Print codes
                </button>
            </div>
        @endif

        <div class="row g-4 align-items-end">
            <div class="col-lg-6">
                <p class="mb-0">
                    Forgot your password? Press <strong>Forgot password?</strong> on the sign-in page and enter one of
                    these codes to choose a new one. Making a new set stops the old codes from working.
                </p>
            </div>
            <div class="col-lg-6">
                <form method="POST" action="{{ route('settings.recovery-codes.generate') }}">
                    @csrf
                    <label for="recovery_password" class="form-label">Current password</label>
                    <div class="d-flex flex-wrap flex-sm-nowrap gap-2">
                        <div class="input-group input-group-merge form-password-toggle flex-grow-1 @error('recovery_password') is-invalid @enderror">
                            <input type="password"
                                   id="recovery_password"
                                   name="recovery_password"
                                   class="form-control @error('recovery_password') is-invalid @enderror"
                                   required
                                   autocomplete="current-password">
                            <span class="input-group-text cursor-pointer" title="Show or hide">
                                <i class="icon-base bx bx-hide" aria-hidden="true"></i>
                            </span>
                        </div>
                        <button type="submit" class="btn btn-primary text-nowrap">
                            {{ $codesMade ? 'Make new codes' : 'Make 10 codes' }}
                        </button>
                    </div>
                    @error('recovery_password')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </form>
            </div>
        </div>
    </x-card>
@endsection
