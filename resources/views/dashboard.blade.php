@extends('layouts.app')

@section('title', 'Dashboard')
@section('body-class', 'dashboard-page')

@section('content')
    @if ($analytics)
        @php
            $headline = $analytics['headline'];
            $quality = $analytics['ocr_quality'];
            $throughput = $analytics['throughput']->take(6)->values();
            $chartData = [
                'volume' => $analytics['trend'],
                'documentTypes' => $analytics['by_document_type']->map(fn ($row) => [
                    'label' => $row['type']->shortLabel(),
                    'total' => $row['total'],
                ])->values(),
                'quality' => [
                    'averageConfidence' => $quality['average_confidence'],
                    'correctionRate' => $quality['correction_rate'],
                    'threshold' => $quality['threshold'],
                ],
                'throughput' => [
                    'labels' => $throughput->pluck('name')->values(),
                    'totals' => $throughput->pluck('total')->values(),
                ],
            ];
        @endphp

        <section class="row g-4 mb-4" aria-label="CRMS performance summary">
            <div class="col-sm-6 col-xl-3">
                <a href="{{ route('reports.index', ['status' => 'submitted']) }}"
                   class="dashboard-kpi card h-100 text-reset">
                    <div class="card-body">
                        <div class="dashboard-kpi__header">
                            <span class="dashboard-kpi__icon bg-label-primary">
                                <i class="icon-base bx bx-archive" aria-hidden="true"></i>
                            </span>
                            <span class="badge bg-label-primary">All time</span>
                        </div>
                        <span class="dashboard-kpi__label">Total digitized records</span>
                        <strong class="dashboard-kpi__value">{{ number_format($headline['records']) }}</strong>
                        <span class="dashboard-kpi__meta">
                            {{ number_format($headline['period_records']) }} submitted in the last 12 months
                        </span>
                    </div>
                </a>
            </div>

            <div class="col-sm-6 col-xl-3">
                <div class="dashboard-kpi card h-100">
                    <div class="card-body">
                        <div class="dashboard-kpi__header">
                            <span class="dashboard-kpi__icon bg-label-info">
                                <i class="icon-base bx bx-brain" aria-hidden="true"></i>
                            </span>
                            <span class="badge bg-label-info">12 months</span>
                        </div>
                        <span class="dashboard-kpi__label">Average OCR confidence</span>
                        <strong class="dashboard-kpi__value">
                            {{ $quality['average_confidence'] === null ? '—' : $quality['average_confidence'].'%' }}
                        </strong>
                        <span class="dashboard-kpi__meta">
                            {{ number_format($quality['confidence_fields']) }} confidence-scored fields
                        </span>
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-xl-3">
                <div class="dashboard-kpi card h-100">
                    <div class="card-body">
                        <div class="dashboard-kpi__header">
                            <span class="dashboard-kpi__icon bg-label-warning">
                                <i class="icon-base bx bx-edit-alt" aria-hidden="true"></i>
                            </span>
                            <span class="badge bg-label-warning">12 months</span>
                        </div>
                        <span class="dashboard-kpi__label">Human correction rate</span>
                        <strong class="dashboard-kpi__value">
                            {{ $quality['correction_rate'] === null ? '—' : $quality['correction_rate'].'%' }}
                        </strong>
                        <span class="dashboard-kpi__meta">
                            {{ number_format($quality['corrected_fields']) }} of {{ number_format($quality['comparable_fields']) }} OCR fields edited
                        </span>
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-xl-3">
                <a href="{{ route('users.index') }}" class="dashboard-kpi card h-100 text-reset">
                    <div class="card-body">
                        <div class="dashboard-kpi__header">
                            <span class="dashboard-kpi__icon bg-label-success">
                                <i class="icon-base bx bx-user-check" aria-hidden="true"></i>
                            </span>
                            <span class="badge bg-label-success">Current</span>
                        </div>
                        <span class="dashboard-kpi__label">Active accounts</span>
                        <strong class="dashboard-kpi__value">{{ number_format($analytics['accounts']['active']) }}</strong>
                        <span class="dashboard-kpi__meta">
                            {{ number_format($analytics['accounts']['inactive']) }} inactive accounts
                        </span>
                    </div>
                </a>
            </div>
        </section>

        <div class="row g-4 mb-4">
            <div class="col-xl-8">
                <x-card class="dashboard-panel h-100"
                        title="Digitization Volume & Trend"
                        subtitle="Monthly submissions by document type over the last 12 months.">
                    <x-slot:actions>
                        @if ($analytics['trend']['growth_rate'] !== null)
                            <span class="badge bg-label-{{ $analytics['trend']['growth_rate'] >= 0 ? 'success' : 'danger' }}">
                                @if ($analytics['trend']['growth_rate'] >= 0)
                                    <i class="icon-base bx bx-trending-up me-1" aria-hidden="true"></i>
                                @else
                                    <i class="icon-base bx bx-trending-down me-1" aria-hidden="true"></i>
                                @endif
                                {{ $analytics['trend']['growth_rate'] >= 0 ? '+' : '' }}{{ $analytics['trend']['growth_rate'] }}% vs last month
                            </span>
                        @else
                            <span class="badge bg-label-secondary">Last 12 months</span>
                        @endif
                    </x-slot:actions>

                    <div id="dashboard-volume-chart" class="dashboard-chart dashboard-chart--volume"
                         aria-label="Monthly digitization volume by document type"></div>

                    <details class="dashboard-chart-data mt-2">
                        <summary>View chart data</summary>
                        <div class="table-responsive mt-2">
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        @foreach ($analytics['trend']['series'] as $series)
                                            <th class="text-end">{{ $series['name'] }}</th>
                                        @endforeach
                                        <th class="text-end">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($analytics['trend']['labels'] as $index => $label)
                                        <tr>
                                            <td>{{ $label }}</td>
                                            @foreach ($analytics['trend']['series'] as $series)
                                                <td class="text-end">{{ number_format($series['data'][$index]) }}</td>
                                            @endforeach
                                            <td class="text-end fw-medium">{{ number_format($analytics['trend']['totals'][$index]) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                </x-card>
            </div>

            <div class="col-xl-4">
                <x-card class="dashboard-panel h-100"
                        title="Document Type Distribution"
                        subtitle="Percentage share of records submitted in the last 12 months.">
                    @if ($analytics['by_document_type']->sum('total') > 0)
                        <div id="dashboard-type-chart" class="dashboard-chart dashboard-chart--donut"
                             aria-label="Document type distribution"></div>
                    @else
                        <x-empty-state icon="bx-pie-chart-alt-2" title="No submissions yet"
                                       message="Document type shares will appear after records are submitted." />
                    @endif

                    <div class="dashboard-ranked-list mt-2">
                        @foreach ($analytics['by_document_type'] as $row)
                            <div class="dashboard-ranked-list__row">
                                <span class="d-inline-flex align-items-center gap-2 text-truncate">
                                    <span class="dashboard-ranked-list__marker" aria-hidden="true"></span>
                                    <span class="text-truncate">{{ $row['type']->shortLabel() }}</span>
                                </span>
                                <span class="fw-medium text-nowrap">
                                    {{ number_format($row['total']) }}
                                    <small class="text-muted ms-1">{{ $row['share'] }}%</small>
                                </span>
                            </div>
                        @endforeach
                    </div>
                </x-card>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-xl-5">
                <x-card class="dashboard-panel h-100"
                        title="OCR AI Quality & Accuracy"
                        subtitle="OCR confidence and the share of fields corrected during verification.">
                    <div id="dashboard-quality-chart" class="dashboard-chart dashboard-chart--radial"
                         aria-label="OCR confidence and human correction rate"></div>

                    <div class="dashboard-quality-summary">
                        <div>
                            <span class="dashboard-quality-summary__dot bg-primary" aria-hidden="true"></span>
                            <span>
                                <small>Average confidence</small>
                                <strong>{{ $quality['average_confidence'] === null ? '—' : $quality['average_confidence'].'%' }}</strong>
                            </span>
                        </div>
                        <div>
                            <span class="dashboard-quality-summary__dot bg-warning" aria-hidden="true"></span>
                            <span>
                                <small>Human correction</small>
                                <strong>{{ $quality['correction_rate'] === null ? '—' : $quality['correction_rate'].'%' }}</strong>
                            </span>
                        </div>
                    </div>

                    <p class="dashboard-quality-note mb-0">
                        <i class="icon-base bx bx-target-lock" aria-hidden="true"></i>
                        System review threshold: <strong>{{ $quality['threshold'] }}%</strong>
                        <span>·</span>
                        {{ number_format($quality['below_threshold']) }} fields below threshold
                    </p>
                </x-card>
            </div>

            <div class="col-xl-7">
                <x-card class="dashboard-panel h-100"
                        title="Staff Processing & Throughput"
                        subtitle="Verified and submitted records per staff member over the last 12 months.">
                    @if ($throughput->isNotEmpty())
                        <div class="dashboard-throughput-layout">
                            <div id="dashboard-throughput-chart" class="dashboard-chart dashboard-chart--throughput"
                                 aria-label="Staff throughput comparison"></div>

                            <div class="table-responsive">
                                <table class="table table-borderless align-middle dashboard-throughput-table mb-0">
                                    <thead>
                                        <tr>
                                            <th>Staff member</th>
                                            <th class="text-end">Records</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($throughput as $person)
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2 min-w-0">
                                                        <span class="dashboard-throughput-avatar">
                                                            {{ str($person['name'])->substr(0, 1)->upper() }}
                                                        </span>
                                                        <span class="min-w-0">
                                                            <span class="d-block fw-medium text-truncate">{{ $person['name'] }}</span>
                                                            <small class="text-muted">{{ $person['role'] }}</small>
                                                        </span>
                                                    </div>
                                                </td>
                                                <td class="text-end fw-semibold">{{ number_format($person['total']) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @else
                        <x-empty-state icon="bx-group" title="No staff throughput yet"
                                       message="The leaderboard will populate after staff submit records." />
                    @endif
                </x-card>
            </div>
        </div>

        <script id="dashboard-chart-data" type="application/json">@json($chartData)</script>
    @else
        <x-page-header
            title="Welcome back, {{ $user->name }}"
            subtitle="Continue digitising records and follow your own requests.">
            @can('documents.process')
                <a href="{{ route('documents.create') }}" class="btn btn-primary">
                    <i class="icon-base bx bx-scan me-1" aria-hidden="true"></i>
                    New document
                </a>
            @endcan
        </x-page-header>

        <section class="row g-4 mb-4" aria-label="Your work summary">
            <div class="col-sm-6 col-lg-4">
                <div class="dashboard-kpi card h-100">
                    <div class="card-body">
                        <span class="dashboard-kpi__icon bg-label-success mb-3">
                            <i class="icon-base bx bx-check-shield" aria-hidden="true"></i>
                        </span>
                        <strong class="dashboard-kpi__value">{{ number_format($staffOverview['submitted_this_month']) }}</strong>
                        <span class="dashboard-kpi__label">Your submissions this month</span>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-4">
                <a href="{{ route('change-requests.index', ['status' => 'pending']) }}"
                   class="dashboard-kpi card h-100 text-reset">
                    <div class="card-body">
                        <span class="dashboard-kpi__icon bg-label-warning mb-3">
                            <i class="icon-base bx bx-git-pull-request" aria-hidden="true"></i>
                        </span>
                        <strong class="dashboard-kpi__value">{{ number_format($staffOverview['pending_change_requests']) }}</strong>
                        <span class="dashboard-kpi__label">Your pending change requests</span>
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
