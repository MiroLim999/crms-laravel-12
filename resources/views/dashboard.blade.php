@extends('layouts.app')

@section('title', 'Dashboard')
@section('body-class', 'dashboard-page')

@section('content')
    <x-page-header
        title="Welcome back, {{ $user->name }}"
        :subtitle="$user->hasOversight()
            ? 'Registry analytics, current priorities, and governance in one place.'
            : 'Continue digitising records and follow your own requests.'">
        @can('documents.process')
            <a href="{{ route('documents.create') }}" class="btn btn-primary">
                <i class="icon-base bx bx-scan me-1" aria-hidden="true"></i>
                New document
            </a>
        @endcan
        @can('change-requests.moderate')
            <a href="{{ route('change-requests.index', ['status' => 'pending']) }}" class="btn btn-outline-primary">
                Review requests
            </a>
        @endcan
    </x-page-header>

    @if ($analytics)
        <section class="dashboard-filter-card card mb-4" aria-labelledby="dashboard-filter-title">
            <div class="card-body">
                <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
                    <div>
                        <h5 class="mb-1" id="dashboard-filter-title">Reporting scope</h5>
                        <p class="mb-0 text-muted small">
                            Submission and OCR charts use {{ $scope['label'] }}
                            <span class="dashboard-scope-timezone">({{ $scope['timezone'] }})</span>.
                        </p>
                    </div>
                    <a href="{{ route('dashboard') }}" class="btn btn-sm btn-label-secondary">Reset filters</a>
                </div>

                <form method="GET" action="{{ route('dashboard') }}" class="dashboard-filter-grid">
                    <div>
                        <label for="dashboard-period" class="form-label">Period</label>
                        <select id="dashboard-period" name="period" class="form-select">
                            @foreach ($periodOptions as $value => $label)
                                <option value="{{ $value }}" @selected($scope['period'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="dashboard-from" class="form-label">From</label>
                        <input id="dashboard-from" type="date" name="from" class="form-control"
                               value="{{ $scope['period'] === 'custom' ? $scope['from'] : '' }}">
                    </div>
                    <div>
                        <label for="dashboard-to" class="form-label">To</label>
                        <input id="dashboard-to" type="date" name="to" class="form-control"
                               value="{{ $scope['period'] === 'custom' ? $scope['to'] : '' }}">
                    </div>
                    <div>
                        <label for="dashboard-type" class="form-label">Document type</label>
                        <select id="dashboard-type" name="document_type" class="form-select">
                            <option value="">All document types</option>
                            @foreach ($filterOptions['document_types'] as $type)
                                <option value="{{ $type->key }}" @selected($scope['document_type'] === $type->key)>
                                    {{ $type->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="dashboard-model" class="form-label">OCR model</label>
                        <select id="dashboard-model" name="ocr_model" class="form-select">
                            <option value="">All OCR models</option>
                            @foreach ($filterOptions['ocr_models'] as $model)
                                <option value="{{ $model['key'] }}" @selected($scope['ocr_model'] === $model['key'])>
                                    {{ $model['label'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="dashboard-filter-submit">
                        <button type="submit" class="btn btn-primary w-100">Apply</button>
                    </div>
                </form>
            </div>
        </section>

        @php
            $headline = $analytics['headline'];
            $quality = $analytics['ocr_quality'];
            $oldestPending = $headline['oldest_pending_at']
                ? \Illuminate\Support\Carbon::parse($headline['oldest_pending_at'])
                : null;
            $readinessValue = $system
                ? $system['ready_types'].'/'.$system['total_types']
                : number_format($analytics['accounts']['active']);
            $readinessLabel = $system ? 'Document types scan-ready' : 'Active accounts';
            $readinessNote = $system
                ? ($system['template_issues'] > 0
                    ? $system['template_issues'].' '.\Illuminate\Support\Str::plural('type', $system['template_issues']).' need attention'
                    : 'Every type has one published layout')
                : $analytics['accounts']['password_change_required'].' password changes required';
            $chartData = [
                'volume' => $analytics['trend'],
                'documentTypes' => $analytics['by_document_type']->map(fn ($row) => [
                    'label' => $row['type']->shortLabel(),
                    'total' => $row['total'],
                ])->values(),
                'quality' => [
                    'passRate' => $quality['threshold_pass_rate'],
                    'threshold' => $quality['threshold'],
                ],
                'governance' => $analytics['governance'],
            ];
        @endphp

        <section class="row g-4 mb-4" aria-label="Dashboard summary">
            <div class="col-sm-6 col-xl-3">
                <a href="{{ route('reports.index', array_filter([
                    'from' => $scope['from'],
                    'to' => $scope['to'],
                    'doc_type' => $scope['document_type'],
                    'status' => 'submitted',
                ])) }}" class="dashboard-metric card h-100 text-reset">
                    <div class="card-body">
                        <div class="dashboard-metric__topline">
                            <span class="dashboard-metric__icon bg-label-primary">
                                <i class="icon-base bx bx-archive" aria-hidden="true"></i>
                            </span>
                            <span class="badge bg-label-primary">Selected period</span>
                        </div>
                        <div class="dashboard-metric__value">{{ number_format($headline['records']) }}</div>
                        <div class="dashboard-metric__label">Records digitized</div>
                        <small class="dashboard-metric__note">
                            @if ($headline['records_delta'] === null)
                                No comparable previous-period baseline
                            @else
                                {{ $headline['records_delta'] >= 0 ? '+' : '' }}{{ $headline['records_delta'] }}% vs previous period
                            @endif
                        </small>
                    </div>
                </a>
            </div>

            <div class="col-sm-6 col-xl-3">
                <a href="{{ route('change-requests.index', ['status' => 'pending']) }}"
                   class="dashboard-metric card h-100 text-reset {{ $headline['pending_requests'] > 0 ? 'dashboard-metric--warning' : '' }}">
                    <div class="card-body">
                        <div class="dashboard-metric__topline">
                            <span class="dashboard-metric__icon bg-label-warning">
                                <i class="icon-base bx bx-git-pull-request" aria-hidden="true"></i>
                            </span>
                            <span class="badge bg-label-secondary">Current status</span>
                        </div>
                        <div class="dashboard-metric__value">{{ number_format($headline['pending_requests']) }}</div>
                        <div class="dashboard-metric__label">Pending change requests</div>
                        <small class="dashboard-metric__note">
                            {{ $oldestPending ? 'Oldest submitted '.$oldestPending->diffForHumans() : 'Nothing is waiting for a decision' }}
                        </small>
                    </div>
                </a>
            </div>

            <div class="col-sm-6 col-xl-3">
                <div class="dashboard-metric card h-100">
                    <div class="card-body">
                        <div class="dashboard-metric__topline">
                            <span class="dashboard-metric__icon bg-label-info">
                                <i class="icon-base bx bx-brain" aria-hidden="true"></i>
                            </span>
                            <span class="badge bg-label-info">Selected period</span>
                        </div>
                        <div class="dashboard-metric__value">
                            {{ $headline['threshold_pass_rate'] === null ? '—' : $headline['threshold_pass_rate'].'%' }}
                        </div>
                        <div class="dashboard-metric__label">Fields meeting review threshold</div>
                        <small class="dashboard-metric__note">
                            {{ number_format($headline['confidence_fields']) }} confidence-scored fields
                        </small>
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-xl-3">
                <a href="{{ $system ? route('templates.index') : route('users.index') }}"
                   class="dashboard-metric card h-100 text-reset {{ $system && $system['template_issues'] > 0 ? 'dashboard-metric--danger' : '' }}">
                    <div class="card-body">
                        <div class="dashboard-metric__topline">
                            <span class="dashboard-metric__icon bg-label-success">
                                <i class="icon-base bx {{ $system ? 'bx-layout' : 'bx-user-check' }}" aria-hidden="true"></i>
                            </span>
                            <span class="badge bg-label-secondary">Current status</span>
                        </div>
                        <div class="dashboard-metric__value">{{ $readinessValue }}</div>
                        <div class="dashboard-metric__label">{{ $readinessLabel }}</div>
                        <small class="dashboard-metric__note">{{ $readinessNote }}</small>
                    </div>
                </a>
            </div>
        </section>

        <div class="row g-4 mb-4">
            <div class="col-xl-8">
                <x-card class="h-100" title="Digitisation volume"
                        :subtitle="'Submitted records by '.$analytics['trend']['mode'].' · '.$scope['label']">
                    <div id="dashboard-volume-chart" class="dashboard-chart" aria-label="Digitisation volume chart"></div>
                    <details class="dashboard-chart-data mt-2">
                        <summary>View chart data</summary>
                        <div class="table-responsive mt-2">
                            <table class="table table-sm mb-0">
                                <thead><tr><th>Period</th><th class="text-end">Submitted</th></tr></thead>
                                <tbody>
                                    @foreach ($analytics['trend']['labels'] as $index => $label)
                                        <tr><td>{{ $label }}</td><td class="text-end">{{ number_format($analytics['trend']['totals'][$index]) }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                </x-card>
            </div>

            <div class="col-xl-4">
                <x-card class="h-100" title="Records by document type" subtitle="Share of the selected submissions.">
                    @if ($analytics['by_document_type']->sum('total') > 0)
                        <div id="dashboard-type-chart" class="dashboard-chart dashboard-chart--donut" aria-label="Records by document type chart"></div>
                    @else
                        <x-empty-state icon="bx-archive" title="No submissions in this period"
                                       message="Try a wider date range or clear a filter." />
                    @endif

                    <div class="dashboard-ranked-list mt-2">
                        @foreach ($analytics['by_document_type']->take(6) as $row)
                            <div class="dashboard-ranked-list__row">
                                <span class="d-inline-flex align-items-center gap-2 text-truncate">
                                    <i class="icon-base bx {{ $row['type']->icon() }} text-muted" aria-hidden="true"></i>
                                    <span class="text-truncate">{{ $row['type']->shortLabel() }}</span>
                                </span>
                                <span class="fw-medium text-nowrap">
                                    {{ number_format($row['total']) }}
                                    <small class="text-muted">{{ $row['share'] }}%</small>
                                </span>
                            </div>
                        @endforeach
                    </div>
                </x-card>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-xl-5">
                <x-card class="h-100" title="OCR review signals"
                        subtitle="Confidence and human edits are review indicators, not validated accuracy.">
                    <div class="dashboard-quality-layout">
                        <div id="dashboard-quality-chart" class="dashboard-chart dashboard-chart--radial"
                             aria-label="Fields meeting the review threshold"></div>
                        <dl class="dashboard-signal-list mb-0">
                            <div>
                                <dt>Average confidence</dt>
                                <dd>{{ $quality['average_confidence'] === null ? '—' : $quality['average_confidence'].'%' }}</dd>
                            </div>
                            <div>
                                <dt>Below {{ $quality['threshold'] }}%</dt>
                                <dd>{{ number_format($quality['below_threshold']) }}</dd>
                            </div>
                            <div>
                                <dt>Human edit rate</dt>
                                <dd>{{ $quality['correction_rate'] === null ? '—' : $quality['correction_rate'].'%' }}</dd>
                            </div>
                            <div>
                                <dt>Comparable OCR fields</dt>
                                <dd>{{ number_format($quality['comparable_fields']) }}</dd>
                            </div>
                        </dl>
                    </div>
                    <div class="alert alert-info d-flex align-items-start gap-2 mt-3 mb-0 small" role="note">
                        <i class="icon-base bx bx-info-circle flex-shrink-0" aria-hidden="true"></i>
                        <span>A human edit can be a formatting change, and an unchanged value can still be wrong. Use these signals to investigate—not as an accuracy score.</span>
                    </div>
                </x-card>
            </div>

            <div class="col-xl-7">
                @if ($system)
                    <x-card class="h-100" title="System readiness" subtitle="Current OCR and template configuration.">
                        <x-slot:actions>
                            <span class="badge bg-label-secondary">Current status</span>
                        </x-slot:actions>

                        <div class="dashboard-readiness-grid">
                            <a href="{{ route('ocr.index') }}" class="dashboard-readiness-item text-reset">
                                <span class="dashboard-readiness-item__icon bg-label-info">
                                    <i class="icon-base bx bx-brain" aria-hidden="true"></i>
                                </span>
                                <span>
                                    <small class="text-muted d-block">OCR engine</small>
                                    <strong id="dashboard-ocr-status"
                                            data-status-url="{{ route('dashboard.system-status') }}">Checking…</strong>
                                    <small id="dashboard-ocr-detail" class="text-muted d-block">Live status loads separately</small>
                                </span>
                            </a>
                            <a href="{{ route('ocr.index') }}" class="dashboard-readiness-item text-reset">
                                <span class="dashboard-readiness-item__icon bg-label-primary">
                                    <i class="icon-base bx bx-check-shield" aria-hidden="true"></i>
                                </span>
                                <span>
                                    <small class="text-muted d-block">Active OCR model</small>
                                    <strong>{{ $system['active_model']?->label ?: $system['active_model']?->key ?: 'Not configured' }}</strong>
                                    <small class="text-muted d-block">Review threshold {{ $system['threshold'] }}%</small>
                                </span>
                            </a>
                            <a href="{{ route('templates.index') }}" class="dashboard-readiness-item text-reset">
                                <span class="dashboard-readiness-item__icon bg-label-success">
                                    <i class="icon-base bx bx-layout" aria-hidden="true"></i>
                                </span>
                                <span>
                                    <small class="text-muted d-block">Published coverage</small>
                                    <strong>{{ $system['ready_types'] }} of {{ $system['total_types'] }} types ready</strong>
                                    <small class="text-muted d-block">{{ $system['draft_templates'] }} draft layouts</small>
                                </span>
                            </a>
                            <a href="{{ route('users.index') }}" class="dashboard-readiness-item text-reset">
                                <span class="dashboard-readiness-item__icon bg-label-warning">
                                    <i class="icon-base bx bx-user" aria-hidden="true"></i>
                                </span>
                                <span>
                                    <small class="text-muted d-block">Account health</small>
                                    <strong>{{ $analytics['accounts']['active'] }} active · {{ $analytics['accounts']['inactive'] }} inactive</strong>
                                    <small class="text-muted d-block">{{ $analytics['accounts']['password_change_required'] }} require a password change</small>
                                </span>
                            </a>
                            <div class="dashboard-readiness-item">
                                <span class="dashboard-readiness-item__icon bg-label-secondary">
                                    <i class="icon-base bx bx-folder" aria-hidden="true"></i>
                                </span>
                                <span>
                                    <small class="text-muted d-block">Original scan storage</small>
                                    <strong id="dashboard-storage-value">Calculating…</strong>
                                    <small id="dashboard-storage-detail" class="text-muted d-block">Cached for 10 minutes</small>
                                </span>
                            </div>
                        </div>
                    </x-card>
                @else
                    <x-card class="h-100" title="Account health" subtitle="Current account state across CRMS.">
                        <x-slot:actions>
                            <a href="{{ route('users.index') }}" class="btn btn-sm btn-label-secondary">Manage accounts</a>
                        </x-slot:actions>
                        <div class="dashboard-readiness-grid">
                            @foreach ([
                                ['Active accounts', $analytics['accounts']['active'], 'bx-user-check', 'success'],
                                ['Inactive accounts', $analytics['accounts']['inactive'], 'bx-user', 'secondary'],
                                ['Password change required', $analytics['accounts']['password_change_required'], 'bx-key', 'warning'],
                                ['Never logged in', $analytics['accounts']['never_logged_in'], 'bx-time-five', 'info'],
                            ] as [$label, $value, $icon, $tone])
                                <div class="dashboard-readiness-item">
                                    <span class="dashboard-readiness-item__icon bg-label-{{ $tone }}">
                                        <i class="icon-base bx {{ $icon }}" aria-hidden="true"></i>
                                    </span>
                                    <span>
                                        <strong class="d-block">{{ number_format($value) }}</strong>
                                        <small class="text-muted">{{ $label }}</small>
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </x-card>
                @endif
            </div>
        </div>

        @if ($system)
            <x-card class="mb-4" title="Template usage and OCR review signals"
                    subtitle="Current published layouts; signal values use the selected submissions.">
                <x-slot:actions>
                    <a href="{{ route('templates.index') }}" class="btn btn-sm btn-label-primary">Open Template Builder</a>
                </x-slot:actions>

                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Published layout</th>
                                <th class="text-end">Records</th>
                                <th class="text-end">Fields / groups</th>
                                <th class="text-end">Avg. confidence</th>
                                <th class="text-end">Below threshold</th>
                                <th class="text-end">Human edit rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($system['template_performance'] as $row)
                                <tr>
                                    <td>
                                        <div class="fw-medium">{{ $row['template']->name }}</div>
                                        <small class="text-muted">{{ $row['template']->typeLabel() }}</small>
                                    </td>
                                    <td class="text-end">{{ number_format($row['records']) }}</td>
                                    <td class="text-end">
                                        {{ $row['fields'] }} /
                                        {{ $row['template']->grouping_mode === 'auto' ? 'Auto' : $row['person_groups'] }}
                                    </td>
                                    <td class="text-end">{{ $row['average_confidence'] === null ? '—' : $row['average_confidence'].'%' }}</td>
                                    <td class="text-end">{{ $row['below_rate'] === null ? '—' : $row['below_rate'].'%' }}</td>
                                    <td class="text-end">{{ $row['edit_rate'] === null ? '—' : $row['edit_rate'].'%' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted py-4">No published layouts yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-card>
        @endif

        <div class="row g-4 mb-4">
            <div class="col-xl-7">
                <x-card class="h-100" title="Governance activity"
                        subtitle="Successful audited actions in the selected date range.">
                    <div id="dashboard-governance-chart" class="dashboard-chart" aria-label="Governance event chart"></div>
                    <p class="small text-muted mb-0">
                        Authentication represents successful sign-ins. Failed sign-ins and denied access are not currently captured.
                    </p>
                </x-card>
            </div>

            <div class="col-xl-5">
                <x-card class="h-100" title="Recent audited activity" subtitle="Latest recorded actions across CRMS.">
                    <x-slot:actions>
                        <a href="{{ route('audit.index') }}" class="btn btn-sm btn-label-secondary">View Audit Log</a>
                    </x-slot:actions>

                    @forelse ($recentActivity as $entry)
                        @php
                            [$activityIcon, $activityTone] = match (true) {
                                str_starts_with($entry->action, 'template.'), str_starts_with($entry->action, 'document_type.') => ['bx-layout', 'primary'],
                                str_starts_with($entry->action, 'ocr_'), str_starts_with($entry->action, 'ocr_model.') => ['bx-brain', 'info'],
                                str_starts_with($entry->action, 'user.') => ['bx-user', 'warning'],
                                str_starts_with($entry->action, 'change_request.') => ['bx-git-pull-request', 'success'],
                                str_starts_with($entry->action, 'report.') => ['bx-file', 'secondary'],
                                default => ['bx-check', 'secondary'],
                            };
                        @endphp
                        <div class="dashboard-activity {{ ! $loop->last ? 'border-bottom' : '' }}">
                            <span class="dashboard-activity__icon bg-label-{{ $activityTone }}">
                                <i class="icon-base bx {{ $activityIcon }}" aria-hidden="true"></i>
                            </span>
                            <span class="min-w-0">
                                <span class="d-block fw-medium">{{ $entry->description ?? str($entry->action)->replace(['.', '_'], ' ')->title() }}</span>
                                <small class="text-muted">{{ $entry->actor_name ?? 'System' }} · {{ $entry->created_at->diffForHumans() }}</small>
                            </span>
                        </div>
                    @empty
                        <x-empty-state icon="bx-history" title="No activity yet"
                                       message="Administrative actions will appear here." />
                    @endforelse
                </x-card>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-xl-7">
                <x-card class="h-100" title="Recent submissions" subtitle="Latest records in the selected reporting scope.">
                    <x-slot:actions>
                        <a href="{{ route('records.index') }}" class="btn btn-sm btn-label-secondary">Open archive</a>
                    </x-slot:actions>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead><tr><th>Record</th><th>Type</th><th>Submitted by</th><th class="text-end">Submitted</th></tr></thead>
                            <tbody>
                                @forelse ($analytics['recent_records'] as $record)
                                    <tr>
                                        <td><a href="{{ route('records.show', $record) }}">{{ $record->registry_number ?: 'Record #'.$record->getKey() }}</a></td>
                                        <td>{{ $record->typeShortLabel() }}</td>
                                        <td>{{ $record->submitter?->name ?? 'Removed account' }}</td>
                                        <td class="text-end text-nowrap">{{ $record->submitted_at?->diffForHumans() }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-center text-muted py-4">No submissions match this scope.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-card>
            </div>

            <div class="col-xl-5">
                <x-card class="h-100" title="Submitted records by account"
                        subtitle="Top accounts in the selected period; relative bars are visual comparison only.">
                    @forelse ($analytics['throughput'] as $person)
                        <div class="dashboard-account-row">
                            <div class="d-flex align-items-center justify-content-between gap-3 mb-1">
                                <span class="text-truncate">
                                    {{ $person['name'] }}
                                    <small class="text-muted">· {{ $person['role'] }}</small>
                                </span>
                                <strong>{{ number_format($person['total']) }}</strong>
                            </div>
                            <div class="dashboard-comparison-bar" aria-hidden="true">
                                <span style="width: {{ $person['relative'] }}%"></span>
                            </div>
                        </div>
                    @empty
                        <x-empty-state icon="bx-user" title="No submissions in this period"
                                       message="Account comparisons appear after records are submitted." />
                    @endforelse
                </x-card>
            </div>
        </div>

        <script id="dashboard-chart-data" type="application/json">@json($chartData)</script>
    @else
        <section class="row g-4 mb-4" aria-label="Your work summary">
            <div class="col-sm-6 col-lg-4">
                <div class="dashboard-metric card h-100">
                    <div class="card-body">
                        <span class="dashboard-metric__icon bg-label-success mb-3">
                            <i class="icon-base bx bx-check-shield" aria-hidden="true"></i>
                        </span>
                        <div class="dashboard-metric__value">{{ number_format($staffOverview['submitted_this_month']) }}</div>
                        <div class="dashboard-metric__label">Your submissions this month</div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-4">
                <a href="{{ route('change-requests.index', ['status' => 'pending']) }}" class="dashboard-metric card h-100 text-reset">
                    <div class="card-body">
                        <span class="dashboard-metric__icon bg-label-warning mb-3">
                            <i class="icon-base bx bx-git-pull-request" aria-hidden="true"></i>
                        </span>
                        <div class="dashboard-metric__value">{{ number_format($staffOverview['pending_change_requests']) }}</div>
                        <div class="dashboard-metric__label">Your pending change requests</div>
                    </div>
                </a>
            </div>
            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-body">
                        <h5 class="mb-3">Quick actions</h5>
                        <div class="d-grid gap-2">
                            <a href="{{ route('documents.create') }}" class="btn btn-primary">New document</a>
                            <a href="{{ route('records.index') }}" class="btn btn-label-secondary">Search archive</a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <div class="row g-4">
            <div class="col-lg-7">
                <x-card class="h-100" title="Your recent submissions">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead><tr><th>Record</th><th>Type</th><th class="text-end">Submitted</th></tr></thead>
                            <tbody>
                                @forelse ($staffOverview['recent_records'] as $record)
                                    <tr>
                                        <td><a href="{{ route('records.show', $record) }}">{{ $record->registry_number ?: 'Record #'.$record->getKey() }}</a></td>
                                        <td>{{ $record->typeShortLabel() }}</td>
                                        <td class="text-end text-nowrap">{{ $record->submitted_at?->diffForHumans() }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-center text-muted py-4">You have not submitted a record yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-card>
            </div>
            <div class="col-lg-5">
                <x-card class="h-100" title="Your recent activity">
                    @forelse ($recentActivity as $entry)
                        <div class="dashboard-activity {{ ! $loop->last ? 'border-bottom' : '' }}">
                            <span class="dashboard-activity__icon bg-label-secondary">
                                <i class="icon-base bx bx-check" aria-hidden="true"></i>
                            </span>
                            <span>
                                <span class="d-block fw-medium">{{ $entry->description ?? str($entry->action)->replace(['.', '_'], ' ')->title() }}</span>
                                <small class="text-muted">{{ $entry->created_at->diffForHumans() }}</small>
                            </span>
                        </div>
                    @empty
                        <x-empty-state icon="bx-history" title="No activity yet"
                                       message="Your completed actions will appear here." />
                    @endforelse
                </x-card>
            </div>
        </div>
    @endif
@endsection

@if ($analytics)
    @push('scripts')
        @vite('resources/js/dashboard-analytics.js')
    @endpush
@endif
