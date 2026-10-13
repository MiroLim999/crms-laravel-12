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
            $throughputCount = $throughput->count();
            $throughputTotal = (int) $throughput->sum('total');
            $sparseThroughput = $throughputCount > 0 && $throughputCount <= 2;
            $throughputMeta = match (true) {
                $throughputCount === 0 => 'No contributing accounts',
                $throughputCount <= 2 => number_format($throughputCount).' contributing '.($throughputCount === 1 ? 'account' : 'accounts'),
                default => 'Top '.number_format($throughputCount).' accounts by records submitted',
            };

            $typeSlices = collect($analytics['type_slices'])
                ->filter(fn (array $slice): bool => (int) $slice['total'] > 0)
                ->values();
            $typeTotal = (int) $typeSlices->sum('total');
            $typeSlices = $typeSlices->map(fn (array $slice): array => [
                ...$slice,
                'share' => $typeTotal > 0 ? round((int) $slice['total'] / $typeTotal * 100, 1) : 0.0,
            ]);
            $inactiveTypeCount = $analytics['by_document_type']->where('total', 0)->count();

            $accountActive = (int) $analytics['accounts']['active'];
            $accountInactive = (int) $analytics['accounts']['inactive'];
            $accountTotal = $accountActive + $accountInactive;
            $accountActiveRate = $accountTotal > 0 ? round($accountActive / $accountTotal * 100, 1) : 0.0;

            // A compact, truthful sparkline for the total card. It uses the
            // same buckets and period as the volume chart below.
            $recordSparkSource = collect($trend['totals'])->map(fn ($value): int => (int) $value)->values();
            $recordSparkValues = $recordSparkSource->count() > 12
                ? $recordSparkSource
                    ->chunk((int) ceil($recordSparkSource->count() / 12))
                    ->map(fn ($bucket): int => (int) $bucket->sum())
                    ->values()
                : $recordSparkSource;
            if ($recordSparkValues->count() < 2) {
                $onlyValue = (int) ($recordSparkValues->first() ?? 0);
                $recordSparkValues = collect([$onlyValue, $onlyValue]);
            }
            $recordSparkPeak = max(1, (int) $recordSparkValues->max());
            $recordSparkLastIndex = max(1, $recordSparkValues->count() - 1);
            $recordSparkCoordinates = $recordSparkValues->map(fn (int $value, int $index): array => [
                'x' => $index / $recordSparkLastIndex * 100,
                'y' => 31 - ($value / $recordSparkPeak * 25),
            ]);
            $recordSparkPath = $recordSparkCoordinates
                ->map(fn (array $point): string => number_format($point['x'], 2, '.', '').' '.number_format($point['y'], 2, '.', ''))
                ->implode(' L ');
            $recordSparkLine = 'M '.$recordSparkPath;
            $recordSparkArea = $recordSparkLine.' L 100 36 L 0 36 Z';
            $recordSparkLast = $recordSparkCoordinates->last();

            // Each panel keeps its own reporting period.
            $trendScope = $scopes['trend'];
            $typesScope = $scopes['types'];
            $staffScope = $scopes['staff'];
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

        {{-- Desktop keeps the 3/3/6 summary and 8/4 analytics grid. --}}
        <section class="row g-4 mb-4" aria-label="CRMS performance summary">
            <div class="col-12 col-sm-6 col-lg-3">
                {{-- This period line follows the volume panel and refreshes with it. --}}
                <a id="summary-total" href="{{ route('reports.index', ['status' => 'submitted']) }}"
                   class="dashboard-kpi card h-100 text-reset" data-dashboard-summary>
                    <div class="card-body">
                        <div class="dashboard-kpi__header">
                            <span class="dashboard-kpi__icon bg-label-primary">
                                <i class="icon-base bx bx-archive" aria-hidden="true"></i>
                            </span>
                            <span class="badge bg-label-primary">All time</span>
                        </div>

                        <div class="dashboard-kpi__metric">
                            <span class="dashboard-kpi__label">Total digitized records</span>
                            <strong class="dashboard-kpi__value">{{ number_format($headline['records']) }}</strong>
                        </div>

                        <div class="dashboard-kpi__insight dashboard-kpi__insight--records">
                            <div class="dashboard-kpi__insight-head">
                                <span>Period trend</span>
                                @if ($headline['change'] !== null)
                                    <span @class([
                                        'dashboard-kpi__delta',
                                        'dashboard-kpi__delta--up' => $headline['change'] >= 0,
                                        'dashboard-kpi__delta--down' => $headline['change'] < 0,
                                    ])>
                                        @if ($headline['change'] >= 0)
                                            <i class="icon-base bx bx-trending-up" aria-hidden="true"></i>
                                        @else
                                            <i class="icon-base bx bx-trending-down" aria-hidden="true"></i>
                                        @endif
                                        {{ $headline['change'] >= 0 ? '+' : '' }}{{ $percent($headline['change']) }}
                                    </span>
                                @elseif ($headline['period_records'] > 0)
                                    <span class="dashboard-kpi__delta">New activity</span>
                                @else
                                    <span class="dashboard-kpi__delta">No activity</span>
                                @endif
                            </div>

                            <svg class="dashboard-kpi__sparkline" viewBox="0 0 100 36"
                                 preserveAspectRatio="none" aria-hidden="true" focusable="false">
                                <line class="dashboard-kpi__sparkline-baseline" x1="0" y1="35" x2="100" y2="35" />
                                <path class="dashboard-kpi__sparkline-area" d="{{ $recordSparkArea }}" />
                                <path class="dashboard-kpi__sparkline-line" d="{{ $recordSparkLine }}" />
                                <circle class="dashboard-kpi__sparkline-point"
                                        cx="{{ number_format($recordSparkLast['x'], 2, '.', '') }}"
                                        cy="{{ number_format($recordSparkLast['y'], 2, '.', '') }}" r="1.75" />
                            </svg>

                            <span class="dashboard-kpi__meta">
                                {{ number_format($headline['period_records']) }} submitted in {{ $trendScope['phrase'] }}
                            </span>
                        </div>
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

                        <div class="dashboard-kpi__metric">
                            <span class="dashboard-kpi__label">Active accounts</span>
                            <strong class="dashboard-kpi__value">{{ number_format($accountActive) }}</strong>
                        </div>

                        <div class="dashboard-kpi__insight dashboard-kpi__insight--accounts">
                            <div class="dashboard-kpi__insight-head">
                                <span>Availability</span>
                                <strong class="dashboard-kpi__health-rate">{{ $percent($accountActiveRate) }}</strong>
                            </div>

                            <div class="dashboard-kpi__health-track" aria-hidden="true"
                                 style="--dashboard-kpi-health: {{ number_format($accountActiveRate, 1, '.', '') }}%">
                                <span></span>
                            </div>

                            <div class="dashboard-kpi__health-details">
                                <span>{{ number_format($accountTotal) }} total</span>
                                <span>
                                    <i aria-hidden="true"></i>
                                    {{ number_format($accountInactive) }} inactive
                                </span>
                            </div>
                        </div>
                    </div>
                </a>
            </div>

            <div class="col-12 col-lg-6">
                <x-dashboard.panel panel="ocr" title="OCR Quality" class="dashboard-panel--quality">
                    <x-slot:tools>
                        <x-dashboard.period-filter panel="ocr" label="OCR confidence and corrections"
                                                   :scopes="$scopes" :forms="$periodForms" :options="$periodOptions" />
                    </x-slot:tools>

                    <div class="dashboard-quality">
                        <div class="dashboard-quality__visual">
                            <div id="dashboard-quality-chart" class="dashboard-chart dashboard-chart--radial"
                                 aria-label="OCR confidence {{ $percent($quality['average_confidence']) }} and corrections {{ $percent($quality['correction_rate']) }}"></div>
                            <div class="dashboard-quality__center" aria-hidden="true">
                                <span>Confidence</span>
                                <strong>{{ $percent($quality['average_confidence']) }}</strong>
                            </div>
                        </div>

                        <div class="dashboard-quality__details">
                            <dl class="dashboard-quality__metrics">
                                <div class="dashboard-quality__metric">
                                    <dt aria-label="Average OCR confidence" title="Average OCR confidence">
                                        <span class="dashboard-quality__dot bg-primary" aria-hidden="true"></span>
                                        Confidence
                                    </dt>
                                    <dd class="dashboard-quality__value">{{ $percent($quality['average_confidence']) }}</dd>
                                    <dd class="dashboard-quality__caption">
                                        {{ number_format($quality['confidence_fields']) }} fields scored
                                    </dd>
                                </div>
                                <div class="dashboard-quality__metric">
                                    <dt aria-label="Human correction rate" title="Human correction rate">
                                        <span class="dashboard-quality__dot bg-warning" aria-hidden="true"></span>
                                        Corrections
                                    </dt>
                                    <dd class="dashboard-quality__value">{{ $percent($quality['correction_rate']) }}</dd>
                                    <dd class="dashboard-quality__caption">
                                        {{ number_format($quality['corrected_fields']) }} of {{ number_format($quality['comparable_fields']) }} fields edited
                                    </dd>
                                </div>
                            </dl>

                            <p class="dashboard-quality-note mb-0">
                                <i class="icon-base bx bx-target-lock" aria-hidden="true"></i>
                                <span>
                                    {{ number_format($quality['below_threshold']) }}
                                    {{ $quality['below_threshold'] === 1 ? 'field' : 'fields' }} below
                                    <strong>{{ $quality['threshold'] }}% review floor</strong>
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
                        {{-- Compare periods of equal length. --}}
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
                            <summary>
                                <span class="dashboard-chart-data__identity">
                                    <i class="icon-base bx bx-data" aria-hidden="true"></i>
                                    <span>Chart data</span>
                                    <span class="dashboard-chart-data__count">
                                        {{ number_format(count($trend['labels'])) }} {{ count($trend['labels']) === 1 ? 'point' : 'points' }}
                                    </span>
                                </span>
                                <span class="dashboard-chart-data__action" aria-hidden="true">
                                    <span class="dashboard-chart-data__view">View</span>
                                    <span class="dashboard-chart-data__hide">Hide</span>
                                    <i class="icon-base bx bx-chevron-down dashboard-chart-data__chevron"></i>
                                </span>
                            </summary>
                            <div class="dashboard-chart-data__surface">
                                <table class="table table-sm mb-0">
                                    <thead>
                                        <tr>
                                            <th scope="col">{{ $trend['mode'] === 'day' ? 'Day' : 'Month' }}</th>
                                            @foreach ($trend['series'] as $series)
                                                <th scope="col" class="text-end">{{ $series['name'] }}</th>
                                            @endforeach
                                            <th scope="col" class="text-end">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($trend['labels'] as $index => $label)
                                            <tr>
                                                <th scope="row">{{ $label }}</th>
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

                    @if ($typeSlices->isNotEmpty())
                        <div class="dashboard-types">
                            <div class="dashboard-types__visual">
                                <div class="dashboard-types__chart-shell">
                                    <div id="dashboard-type-chart" class="dashboard-chart dashboard-chart--donut"
                                         aria-label="Document type distribution across {{ number_format($typeTotal) }} records"></div>
                                    <div class="dashboard-types__center" aria-hidden="true">
                                        <span>Records</span>
                                        <strong>{{ number_format($typeTotal) }}</strong>
                                    </div>
                                </div>
                            </div>

                            <div class="dashboard-types__details">
                                <ul class="dashboard-type-slices" aria-label="Document type shares">
                                    @foreach ($typeSlices as $slice)
                                        <li class="dashboard-type-slices__row"
                                            style="--dashboard-type-color: {{ $slice['color'] }}; --dashboard-type-share: {{ number_format(min(100, max(0, $slice['share'])), 1, '.', '') }}%">
                                            <div class="dashboard-type-slices__line">
                                                <span class="dashboard-type-slices__name" title="{{ $slice['label'] }}">
                                                    <span class="dashboard-type-legend__dot" aria-hidden="true"></span>
                                                    <span class="text-truncate">{{ $slice['label'] }}</span>
                                                </span>
                                                <span class="dashboard-type-slices__metrics">
                                                    <strong>{{ number_format($slice['total']) }}</strong>
                                                    <span class="dashboard-type-slices__share">{{ $percent($slice['share']) }}</span>
                                                </span>
                                            </div>
                                            <span class="visually-hidden">
                                                {{ $slice['total'] === 1 ? 'record' : 'records' }}
                                            </span>
                                            <div class="dashboard-type-slices__track" aria-hidden="true"><span></span></div>
                                        </li>
                                    @endforeach
                                </ul>

                                @if ($inactiveTypeCount > 0)
                                    <p class="dashboard-types__inactive mb-0">
                                        <i class="icon-base bx bx-info-circle" aria-hidden="true"></i>
                                        {{ number_format($inactiveTypeCount) }} configured
                                        {{ $inactiveTypeCount === 1 ? 'type has' : 'types have' }} no submissions
                                    </p>
                                @endif
                            </div>
                        </div>
                    @else
                        <x-empty-state icon="bx-pie-chart-alt-2" title="No submissions in this period"
                                       message="Choose a longer period to see document type shares." />
                    @endif
                </x-dashboard.panel>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-12">
                <x-dashboard.panel panel="staff" title="Processing & Throughput">
                    <x-slot:meta>{{ $throughputMeta }} · {{ $staffScope['short_label'] }}</x-slot:meta>
                    <x-slot:tools>
                        <x-dashboard.period-filter panel="staff" label="processing and throughput"
                                                   :scopes="$scopes" :forms="$periodForms" :options="$periodOptions" />
                    </x-slot:tools>

                    @if ($sparseThroughput)
                        <div class="dashboard-throughput-sparse">
                            <dl class="dashboard-throughput-summary" aria-label="Displayed throughput summary">
                                <div>
                                    <dt>Records</dt>
                                    <dd>{{ number_format($throughputTotal) }}</dd>
                                </div>
                                <div>
                                    <dt>Accounts</dt>
                                    <dd>{{ number_format($throughputCount) }}</dd>
                                </div>
                            </dl>

                            <ol class="dashboard-contributors">
                                @foreach ($throughput as $person)
                                    @php
                                        $share = $throughputTotal > 0 ? round((int) $person['total'] / $throughputTotal * 100, 1) : 0.0;
                                    @endphp
                                    <li class="dashboard-contributor" style="--dashboard-throughput-share: {{ number_format($share, 1, '.', '') }}%">
                                        <div class="dashboard-contributor__topline">
                                            <span class="dashboard-contributor__rank" aria-label="Rank {{ $loop->iteration }}">
                                                #{{ $loop->iteration }}
                                            </span>
                                            <span class="dashboard-throughput-avatar" aria-hidden="true">
                                                {{ str($person['name'])->substr(0, 1)->upper() }}
                                            </span>
                                            <span class="dashboard-contributor__identity">
                                                <span class="dashboard-contributor__name" title="{{ $person['name'] }}">{{ $person['name'] }}</span>
                                                <small title="{{ $person['role'] }}">{{ $person['role'] }}</small>
                                            </span>
                                            <span class="dashboard-contributor__total">
                                                <strong>{{ number_format($person['total']) }}</strong>
                                                <small>{{ $person['total'] === 1 ? 'record' : 'records' }}</small>
                                            </span>
                                        </div>
                                        <div class="dashboard-contributor__share">
                                            <div class="dashboard-contributor__track" role="progressbar"
                                                 aria-label="{{ $person['name'] }} share of displayed output"
                                                 aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $share }}">
                                                <span></span>
                                            </div>
                                            <small>{{ $percent($share) }} of displayed output</small>
                                        </div>
                                    </li>
                                @endforeach
                            </ol>
                        </div>
                    @elseif ($throughput->isNotEmpty())
                        <div class="dashboard-throughput-layout">
                            <div id="dashboard-throughput-chart" class="dashboard-chart dashboard-chart--throughput"
                                 aria-label="Staff throughput comparison"></div>

                            <div class="dashboard-throughput-table-wrap">
                                <table class="table table-borderless align-middle dashboard-throughput-table mb-0">
                                    <thead>
                                        <tr>
                                            <th scope="col" class="dashboard-throughput-table__rank">Rank</th>
                                            <th scope="col">Account</th>
                                            <th scope="col" class="text-end">Records</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($throughput as $person)
                                            <tr>
                                                <td class="dashboard-throughput-table__rank">
                                                    <span>{{ $loop->iteration }}</span>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2 min-w-0">
                                                        <span class="dashboard-throughput-avatar" aria-hidden="true">
                                                            {{ str($person['name'])->substr(0, 1)->upper() }}
                                                        </span>
                                                        <span class="min-w-0">
                                                            <span class="d-block fw-medium text-truncate" title="{{ $person['name'] }}">{{ $person['name'] }}</span>
                                                            <small class="d-block text-muted text-truncate" title="{{ $person['role'] }}">{{ $person['role'] }}</small>
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
