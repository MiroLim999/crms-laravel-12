@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <x-page-header
        title="Welcome back, {{ $user->name }}"
        subtitle="{{ $user->role->name }} · {{ $user->role->description }}" />

    @if ($oversight)
        <div class="row g-4 mb-4">
            @foreach ([
                ['Active accounts', $oversight['active_users'], 'bx-user-check'],
                ['Awaiting first sign-in', $oversight['pending_first_login'], 'bx-time-five'],
                ['Audit entries', $oversight['audit_entries'], 'bx-history'],
            ] as [$label, $value, $icon])
                <div class="col-sm-6 col-lg-4">
                    <div class="card h-100">
                        <div class="card-body d-flex align-items-center gap-3">
                            <span class="badge bg-label-primary rounded p-2 lh-1">
                                <i class="icon-base bx {{ $icon }} icon-md"></i>
                            </span>
                            <div>
                                <div class="h4 mb-0">{{ number_format($value) }}</div>
                                <small class="text-muted">{{ $label }}</small>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="row g-4">
        <div class="col-lg-7">
            <x-card title="Recent activity"
                    :subtitle="$user->hasOversight() ? 'Across all users' : 'Your actions'">
                @forelse ($recentActivity as $entry)
                    <div class="d-flex align-items-start gap-3 {{ ! $loop->last ? 'mb-3 pb-3 border-bottom' : '' }}">
                        <span class="badge bg-label-secondary rounded p-2 lh-1">
                            <i class="icon-base bx bx-check icon-sm"></i>
                        </span>
                        <div class="flex-grow-1">
                            <div class="fw-medium">{{ $entry->description ?? $entry->action }}</div>
                            <small class="text-muted">
                                {{ $entry->actor_name ?? 'System' }}
                                · {{ $entry->created_at->diffForHumans() }}
                            </small>
                        </div>
                        <code class="small text-muted">{{ $entry->action }}</code>
                    </div>
                @empty
                    <x-empty-state
                        icon="bx-history"
                        title="No activity yet"
                        message="Actions you take will be recorded here." />
                @endforelse
            </x-card>
        </div>

        <div class="col-lg-5">
            <x-card title="What you can do" subtitle="Based on your role">
                <ul class="list-unstyled mb-0">
                    @foreach ([
                        'Upload & process documents' => 'documents.process',
                        'Verify & submit records' => 'records.submit',
                        'Search records archive' => 'records.view',
                        'Request record changes' => 'change-requests.create',
                        'Approve change requests' => 'change-requests.moderate',
                        'Analytics dashboard' => 'analytics.view',
                        'Manage user accounts' => 'users.manage',
                        'View audit log' => 'audit.view',
                        'Generate reports' => 'reports.generate',
                        'Document templates' => 'templates.manage',
                        'OCR model management' => 'ocr.manage',
                    ] as $label => $ability)
                        <li class="d-flex align-items-center gap-2 py-1">
                            @can($ability)
                                <i class="icon-base bx bx-check-circle icon-sm text-success"></i>
                                <span>{{ $label }}</span>
                            @else
                                <i class="icon-base bx bx-minus-circle icon-sm text-muted"></i>
                                <span class="text-muted">{{ $label }}</span>
                            @endcan
                        </li>
                    @endforeach
                </ul>
            </x-card>
        </div>
    </div>
@endsection
