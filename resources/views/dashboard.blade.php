@extends('layouts.app')

@section('title', 'Dashboard')
@section('body-class', 'dashboard-page')

@section('content')
    @if ($analytics)
        @php
            $headline = $analytics['headline'];
            $quality = $analytics['ocr_quality'];
            $trend = $analytics['trend'];
            $throughput = $analytics['throughput']->take(6)->values();
            // Each panel has its own period.
            $trendScope = $scopes['trend'];
            $typesScope = $scopes['types'];
            $ocrScope = $scopes['ocr'];
            $staffScope = $scopes['staff'];
            // One decimal everywhere, so 6.0% sits beside 91.2% rather than 6%.
            $percent = fn (int|float|null $value): string => $value === null ? '—' : number_format($value, 1).'%';
            $comparisonNote = number_format($headline['period_records']).' in '.$trendScope['label']
                .' · '.number_format($headline['previous_records']).' in '.$trendScope['previous_label'];
            $chartData = [
                'volume' => [
                    'labels' => $trend['labels'],
                    'titles' => $trend['titles'],
                    'series' => $trend['series'],
                ],
                'documentTypes' => $analytics['type_slices'],
                'quality' => [
                    'averageConfidence' => $quality['average_confidence'],
                    'correctionRate' => $quality['correction_rate'],
                ],
                'throughput' => [
                    'labels' => $throughput->pluck('name')->values(),
                    'totals' => $throughput->pluck('total')->values(),
                ],
            ];
        @endphp

        {{--
            Wide screens: two summary cards and OCR quality, then volume beside
            document types, then throughput across. The 1080px-wide portrait
            monitor keeps that arrangement; tablets put OCR quality, volume and
            types each on a row of their own; phones stack everything.
        --}}
        <section class="row g-4 mb-4" aria-label="CRMS performance summary">
            <div class="col-12 col-sm-6 col-lg-3">
                {{-- Its period line follows the volume panel, so it is swapped with it. --}}
                <a id="summary-total" href="{{ route('reports.index', ['status' => 'submitted']) }}"
                   class="dashboard-kpi card h-100 text-reset" data-dashboard-summary>
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
                            {{ number_format($headline['period_records']) }} submitted in {{ $trendScope['phrase'] }}
                        </span>
                    </div>
                </a>
            </div>

            <div class="col-12 col-sm-6 col-lg-3">
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

            <div class="col-12 col-lg-6">
                {{-- Confidence is the model's certainty in its own reading, not measured accuracy. --}}
                <x-dashboard.panel panel="ocr" title="OCR Confidence & Corrections">
                    <x-slot:meta>Records submitted {{ $ocrScope['short_label'] }}</x-slot:meta>
                    <x-slot:tools>
                        <x-dashboard.period-filter panel="ocr" label="OCR confidence and corrections"
                                                   :scopes="$scopes" :forms="$periodForms" :options="$periodOptions" />
                    </x-slot:tools>

                    <div class="dashboard-quality">
                        <div id="dashboard-quality-chart" class="dashboard-chart dashboard-chart--radial"
                             aria-label="OCR confidence and human correction rate"></div>

                        <div class="dashboard-quality__details">
                            <dl class="dashboard-quality__metrics">
                                <div class="dashboard-quality__metric">
                                    <dt>
                                        <span class="dashboard-quality__dot bg-primary" aria-hidden="true"></span>
                                        Average OCR confidence
                                    </dt>
                                    <dd class="dashboard-quality__value">{{ $percent($quality['average_confidence']) }}</dd>
                                    <dd class="dashboard-quality__caption">
                                        The model's own certainty, across {{ number_format($quality['confidence_fields']) }} scored fields
                                    </dd>
                                </div>
                                <div class="dashboard-quality__metric">
                                    <dt>
                                        <span class="dashboard-quality__dot bg-warning" aria-hidden="true"></span>
                                        Human correction rate
                                    </dt>
                                    <dd class="dashboard-quality__value">{{ $percent($quality['correction_rate']) }}</dd>
                                    <dd class="dashboard-quality__caption">
                                        {{ number_format($quality['corrected_fields']) }} of {{ number_format($quality['comparable_fields']) }} readings changed while verifying
                                    </dd>
                                </div>
                            </dl>

                            <p class="dashboard-quality-note mb-0">
                                <i class="icon-base bx bx-target-lock" aria-hidden="true"></i>
                                <span>
                                    Review threshold <strong>{{ $quality['threshold'] }}%</strong>
                                    · {{ number_format($quality['below_threshold']) }} {{ $quality['below_threshold'] === 1 ? 'field' : 'fields' }} below it
                                </span>
                            </p>
                        </div>
                    </div>
                </x-dashboard.panel>
            </div>
        </section>

        <div class="row g-4 mb-4">
            <div class="col-12 col-lg-8">
                <x-dashboard.panel panel="trend" title="Digitization Volume & Trend">
                    <x-slot:badge>
                        {{-- Compared with a period of the same length, never a running month with a finished one. --}}
                        @if ($headline['change'] !== null)
                            <span class="dashboard-delta dashboard-delta--{{ $headline['change'] >= 0 ? 'up' : 'down' }}"
                                  title="{{ $comparisonNote }}">
                                @if ($headline['change'] >= 0)
                                    <i class="icon-base bx bx-trending-up" aria-hidden="true"></i>
                                @else
                                    <i class="icon-base bx bx-trending-down" aria-hidden="true"></i>
                                @endif
                                {{ $headline['change'] >= 0 ? '+' : '' }}{{ $percent($headline['change']) }} vs {{ $trendScope['comparison'] }}
                            </span>
                        @elseif ($headline['period_records'] > 0)
                            <span class="dashboard-delta" title="{{ $comparisonNote }}">
                                None in {{ $trendScope['comparison'] }}
                            </span>
                        @endif
                    </x-slot:badge>
                    <x-slot:meta>
                        {{ $trend['mode'] === 'day' ? 'Daily' : 'Monthly' }} submissions · {{ $trendScope['short_label'] }}
                    </x-slot:meta>
                    <x-slot:tools>
                        @if ($trend['series'] !== [])
                            <ul class="dashboard-legend" aria-label="Document types on the chart">
                                @foreach ($trend['series'] as $series)
                                    <li>
                                        <span class="dashboard-legend__dot" aria-hidden="true"
                                              style="--dashboard-type-color: {{ $series['color'] }}"></span>
                                        {{ $series['name'] }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <x-dashboard.period-filter panel="trend" label="digitization volume and trend"
                                                   :scopes="$scopes" :forms="$periodForms" :options="$periodOptions" />
                    </x-slot:tools>

                    <div id="dashboard-volume-chart" class="dashboard-chart dashboard-chart--volume"
                         aria-label="{{ $trend['mode'] === 'day' ? 'Daily' : 'Monthly' }} digitization volume by document type"></div>

                    @if ($trend['starts_at'])
                        <p class="dashboard-chart-note mb-0">
                            The first archived record is from {{ $trend['starts_at']->format('j M Y') }},
                            so the chart starts there.
                        </p>
                    @endif

                    @if ($trend['series'] !== [])
                        <details class="dashboard-chart-data mt-2">
                            <summary>View chart data</summary>
                            <div class="table-responsive mt-2">
                                <table class="table table-sm mb-0">
                                    <thead>
                                        <tr>
                                            <th>{{ $trend['mode'] === 'day' ? 'Day' : 'Month' }}</th>
                                            @foreach ($trend['series'] as $series)
                                                <th class="text-end">{{ $series['name'] }}</th>
                                            @endforeach
                                            <th class="text-end">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($trend['labels'] as $index => $label)
                                            <tr>
                                                <td>{{ $label }}</td>
                                                @foreach ($trend['series'] as $series)
                                                    <td class="text-end">{{ number_format($series['data'][$index]) }}</td>
                                                @endforeach
                                                <td class="text-end fw-medium">{{ number_format($trend['totals'][$index]) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    @endif
                </x-dashboard.panel>
            </div>

            <div class="col-12 col-lg-4">
                <x-dashboard.panel panel="types" title="Document Type Distribution">
                    <x-slot:meta>Submitted {{ $typesScope['short_label'] }}</x-slot:meta>
                    <x-slot:tools>
                        <x-dashboard.period-filter panel="types" label="document type distribution"
                                                   :scopes="$scopes" :forms="$periodForms" :options="$periodOptions" />
                    </x-slot:tools>

                    <div class="dashboard-types">
                        @if ($analytics['by_document_type']->sum('total') > 0)
                            <div id="dashboard-type-chart" class="dashboard-chart dashboard-chart--donut"
                                 aria-label="Document type distribution"></div>
                        @else
                            <x-empty-state icon="bx-pie-chart-alt-2" title="No submissions in this period"
                                           message="Choose a longer period to see document type shares." />
                        @endif

                        {{-- The donut's key and its numbers. A type with no records stays listed, muted. --}}
                        <ul class="dashboard-type-legend">
                            @foreach ($analytics['by_document_type'] as $row)
                                <li @class(['dashboard-type-legend__row', 'is-empty' => $row['total'] === 0])>
                                    <span class="dashboard-type-legend__name" title="{{ $row['type']->shortLabel() }}">
                                        {{-- The type's own colour, the same on every chart. --}}
                                        <span class="dashboard-type-legend__dot" aria-hidden="true"
                                              style="--dashboard-type-color: {{ $row['color'] }}"></span>
                                        <span class="text-truncate">{{ $row['type']->shortLabel() }}</span>
                                    </span>
                                    <span class="dashboard-type-legend__value">
                                        <strong>{{ number_format($row['total']) }}</strong>
                                        <span class="visually-hidden">{{ $row['total'] === 1 ? 'record' : 'records' }},</span>
                                        <span aria-hidden="true">/</span>
                                        {{ $percent($row['share']) }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </x-dashboard.panel>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-12">
                <x-dashboard.panel panel="staff" title="Processing & Throughput">
                    <x-slot:meta>Top 6 accounts by records submitted · {{ $staffScope['short_label'] }}</x-slot:meta>
                    <x-slot:tools>
                        <x-dashboard.period-filter panel="staff" label="processing and throughput"
                                                   :scopes="$scopes" :forms="$periodForms" :options="$periodOptions" />
                    </x-slot:tools>

                    @if ($throughput->isNotEmpty())
                        <div class="dashboard-throughput-layout">
                            <div id="dashboard-throughput-chart" class="dashboard-chart dashboard-chart--throughput"
                                 aria-label="Staff throughput comparison"></div>

                            <div class="table-responsive">
                                <table class="table table-borderless align-middle dashboard-throughput-table mb-0">
                                    <thead>
                                        <tr>
                                            <th>Account</th>
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
                        <x-empty-state icon="bx-group" title="No submissions in this period"
                                       message="Choose a longer period to compare accounts." />
                    @endif
                </x-dashboard.panel>
            </div>
        </div>

        <p class="visually-hidden" aria-live="polite" data-dashboard-status></p>

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
