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
    </style>
@endpush

@section('content')
    <x-page-header title="Account Settings" subtitle="Your name, email, password, recovery codes and upload checks." />

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
                    <span><strong>Write these down or print them now.</strong> They will not be shown again.
                        A copy was downloaded to this computer (<em>CRMS recovery codes</em>): keep it somewhere safe.</span>
                </div>
                <h6 class="d-none d-print-block mb-3">CRMS recovery codes · {{ $user->email }} · {{ now()->format('M j, Y') }}</h6>
                <ol class="recovery-codes list-unstyled mb-3" id="recoveryCodeList">
                    @foreach (session('recovery_codes') as $code)
                        {{-- The gap is CSS, not a character, so a copied code is just its 6 digits. --}}
                        <li><span class="me-2">{{ substr($code, 0, 3) }}</span>{{ substr($code, 3) }}</li>
                    @endforeach
                </ol>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm d-print-none" id="printRecoveryCodes"
                            data-email="{{ $user->email }}" data-date="{{ now()->format('F j, Y') }}">
                        <i class="icon-base bx bx-printer me-1" aria-hidden="true"></i>Print codes
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm d-print-none" id="downloadRecoveryCodes"
                            data-auto="1" data-day="{{ now()->format('Y-m-d') }}">
                        <i class="icon-base bx bx-download me-1" aria-hidden="true"></i>Download codes
                    </button>
                </div>
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

    <x-card title="Uploads" subtitle="Whether a file you add is checked against what was saved or queued before." class="mt-4">
        <form method="POST" action="{{ route('settings.uploads.update') }}">
            @csrf
            @method('PUT')

            {{-- An unchecked box sends nothing: the hidden 0 says "off". --}}
            <input type="hidden" name="duplicate_file_check" value="0">
            <div class="form-check form-switch mb-2">
                <input class="form-check-input"
                       type="checkbox"
                       role="switch"
                       id="duplicate_file_check"
                       name="duplicate_file_check"
                       value="1"
                       @checked(old('duplicate_file_check', $user->duplicate_file_check))>
                <label class="form-check-label fw-semibold" for="duplicate_file_check">Check uploads for duplicate files</label>
            </div>
            <p class="form-text mb-4">
                When on, a file that is a copy of one already in the batch, or a page already saved as a record,
                is left out of a batch (you can add it anyway), a saved page of a PDF is ticked, and saving a page
                twice asks first. When off, none of this is checked and every file is queued as it is. The file's
                fingerprint is still kept with each record, so turning this on later checks against them too.
            </p>

            <button type="submit" class="btn btn-primary">Save</button>
        </form>
    </x-card>
@endsection

@push('scripts')
    <script>
        // The new codes as a text file: downloaded by itself as soon as they are shown
        // (they are shown once), and again with Download codes.
        (() => {
            const button = document.getElementById('downloadRecoveryCodes');
            if (!button) return;
            const print = document.getElementById('printRecoveryCodes');
            const download = () => {
                const codes = [...document.querySelectorAll('#recoveryCodeList li')].map((li) => li.textContent.replace(/\s+/g, ' ').trim());
                if (codes.length === 0) return;
                const text = [
                    'CRMS recovery codes',
                    `${print?.dataset.email ?? ''} - made ${print?.dataset.date ?? ''}`,
                    '',
                    ...codes.map((code, index) => `${String(index + 1).padStart(2, ' ')}.  ${code}`),
                    '',
                    'Forgot your password? On the sign-in page press "Forgot password?", enter your email',
                    'and one of these codes, then choose a new password. Each code works once.',
                    'Making a new set in Account Settings stops these from working. Keep this file safe.',
                    '',
                ].join('\r\n');
                const url = URL.createObjectURL(new Blob([text], { type: 'text/plain;charset=utf-8' }));
                const link = document.createElement('a');
                link.href = url;
                link.download = `CRMS recovery codes - ${button.dataset.day}.txt`;
                document.body.append(link);
                link.click();
                link.remove();
                window.setTimeout(() => URL.revokeObjectURL(url), 1000);
            };
            button.addEventListener('click', download);
            if (button.dataset.auto === '1') window.setTimeout(download, 300);
        })();

        // Print the codes alone, on one page: a small page of their own in a hidden
        // frame (printing this page would carry every card along, over several pages).
        document.getElementById('printRecoveryCodes')?.addEventListener('click', (event) => {
            const button = event.currentTarget;
            const codes = [...document.querySelectorAll('#recoveryCodeList li')].map((li) => li.textContent.replace(/\s+/g, ' ').trim());
            if (codes.length === 0) return;
            const escape = (text) => String(text).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
            const frame = document.createElement('iframe');
            frame.setAttribute('aria-hidden', 'true');
            frame.style.cssText = 'position:fixed;inline-size:0;block-size:0;border:0;inset:0 auto auto 0;visibility:hidden';
            document.body.append(frame);
            const doc = frame.contentDocument;
            doc.open();
            doc.write(`<!doctype html><html><head><meta charset="utf-8"><title>CRMS recovery codes</title><style>
                @page { size: A4 portrait; margin: 18mm; }
                * { box-sizing: border-box; }
                body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #222; }
                h1 { margin: 0 0 4px; font-size: 20px; }
                .meta { margin: 0 0 18px; font-size: 12px; color: #555; }
                ol { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px 16px; margin: 0 0 18px; padding: 0; list-style: none; counter-reset: code; }
                li { counter-increment: code; display: flex; align-items: center; gap: 12px; padding: 10px 14px; border: 1px dashed #999; border-radius: 6px;
                     font: 600 20px/1.2 ui-monospace, Consolas, "Courier New", monospace; letter-spacing: .12em; break-inside: avoid; }
                li::before { content: counter(code) "."; min-inline-size: 2ch; font: 400 12px system-ui, sans-serif; color: #777; letter-spacing: 0; }
                .note { font-size: 12px; line-height: 1.5; color: #444; border-top: 1px solid #ddd; padding-top: 10px; }
                .page { break-after: avoid; page-break-after: avoid; }
            </style></head><body><div class="page">
                <h1>CRMS recovery codes</h1>
                <p class="meta">${escape(button.dataset.email)} &middot; made ${escape(button.dataset.date)}</p>
                <ol>${codes.map((code) => `<li>${escape(code)}</li>`).join('')}</ol>
                <p class="note">Forgot your password? On the sign-in page press <strong>Forgot password?</strong>, enter your email and one of
                these codes, then choose a new password. Each code works once. Making a new set in Account Settings stops these from working.
                Keep this page somewhere safe.</p>
            </div></body></html>`);
            doc.close();
            const done = () => window.setTimeout(() => frame.remove(), 1000);
            frame.contentWindow.addEventListener('afterprint', done);
            window.setTimeout(() => {
                frame.contentWindow.focus();
                frame.contentWindow.print();
                // Some browsers send no afterprint: the frame goes anyway after a while.
                window.setTimeout(done, 60000);
            }, 50);
        });
    </script>
@endpush
