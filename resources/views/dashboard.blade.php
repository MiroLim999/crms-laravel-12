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
                'ocrPerformance' => $modelPerformance ?? null,
            ];

            $activePerfProfile = collect($modelPerformance['models'] ?? [])->firstWhere('is_active', true)
                ?? collect($modelPerformance['models'] ?? [])->firstWhere('has_data', true)
                ?? collect($modelPerformance['models'] ?? [])->first();
        @endphp

        {{-- Desktop keeps the unified 3-card top row and 8/4 analytics grid matching the wireframe. --}}
        <section class="row g-4 mb-4" aria-label="CRMS performance summary">
            {{-- Combined KPI Card: Total Digitized Records + Active Accounts --}}
            <div class="col-12 col-md-6 col-lg-4 dashboard-reveal" style="--dashboard-reveal: 0">
                <div id="summary-total" class="dashboard-kpi dashboard-kpi--combined card h-100" data-dashboard-summary>
                    <div class="card-body p-3 d-flex flex-column">
                        {{-- Top Half: Total Digitized Records --}}
                        <div class="dashboard-kpi-subcard">
                            <div class="d-flex align-items-center justify-content-between mb-1">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="dashboard-kpi__mini-icon bg-label-primary">
                                        <i class="icon-base bx bx-archive" aria-hidden="true"></i>
                                    </span>
                                    <span class="dashboard-kpi__label mb-0 fw-semibold text-truncate">Total digitized records</span>
                                </div>
                                <a href="{{ route('reports.index', ['status' => 'submitted']) }}" class="badge bg-label-primary text-decoration-none" title="View submitted records report">
                                    All time <i class="icon-base bx bx-chevron-right ms-0.5" aria-hidden="true"></i>
                                </a>
                            </div>

                            <div class="d-flex align-items-center justify-content-between mt-1">
                                <div class="dashboard-kpi__metric">
                                    <strong class="dashboard-kpi__value" data-dashboard-count>{{ number_format($headline['records']) }}</strong>
                                    <span class="dashboard-kpi__meta">
                                        {{ number_format($headline['period_records']) }} submitted in {{ $trendScope['phrase'] }}
                                    </span>
                                </div>

                                <div class="d-flex flex-column align-items-end flex-shrink-0 ms-2">
                                    <div class="dashboard-kpi__insight-head mb-1">
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
                                                cy="{{ number_format($recordSparkLast['y'], 2, '.', '') }}" r="2" />
                                    </svg>
                                </div>
                            </div>
                        </div>

                        {{-- Divider --}}
                        <div class="dashboard-kpi__divider"></div>

                        {{-- Bottom Half: Active Accounts --}}
                        <div class="dashboard-kpi-subcard">
                            <div class="d-flex align-items-center justify-content-between mb-1">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="dashboard-kpi__mini-icon bg-label-success">
                                        <i class="icon-base bx bx-user-check" aria-hidden="true"></i>
                                    </span>
                                    <span class="dashboard-kpi__label mb-0 fw-semibold text-truncate">Active accounts</span>
                                </div>
                                <a href="{{ route('users.index') }}" class="badge bg-label-success text-decoration-none" title="Manage user accounts">
                                    Current <i class="icon-base bx bx-chevron-right ms-0.5" aria-hidden="true"></i>
                                </a>
                            </div>

                            <div class="d-flex align-items-center justify-content-between mt-1">
                                <div class="dashboard-kpi__metric">
                                    <strong class="dashboard-kpi__value" data-dashboard-count>{{ number_format($accountActive) }}</strong>
                                    <div class="dashboard-kpi__health-details">
                                        <span>{{ number_format($accountTotal) }} total</span>
                                        <span>
                                            <i aria-hidden="true"></i>
                                            {{ number_format($accountInactive) }} inactive
                                        </span>
                                    </div>
                                </div>

                                <div class="dashboard-kpi__health d-flex flex-column align-items-end flex-shrink-0 ms-2">
                                    <div class="d-flex align-items-center gap-1 mb-1">
                                        <span class="dashboard-kpi__health-label text-muted">Availability</span>
                                        <strong class="dashboard-kpi__health-rate">{{ $percent($accountActiveRate) }}</strong>
                                    </div>
                                    <div class="dashboard-kpi__health-track w-100" aria-hidden="true"
                                         style="--dashboard-kpi-health: {{ number_format($accountActiveRate, 1, '.', '') }}%">
                                        <span></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Card 2: OCR Quality / Accuracy (Radial Gauge) --}}
            <div class="col-12 col-md-6 col-lg-4 dashboard-reveal" style="--dashboard-reveal: 1">
                <x-dashboard.panel panel="ocr" title="OCR Quality" class="dashboard-panel--quality h-100">
                    <x-slot:tools>
                        <x-dashboard.period-filter panel="ocr" label="OCR confidence and corrections"
                                                   :scopes="$scopes" :forms="$periodForms" :options="$periodOptions" />
                    </x-slot:tools>

                    {{-- The gauge carries the headline; two chips hold the rest. --}}
                    <div class="dashboard-quality">
                        <div class="dashboard-quality__visual"
                             title="Average OCR confidence across {{ number_format($quality['confidence_fields']) }} scored fields">
                            <div id="dashboard-quality-chart" class="dashboard-chart dashboard-chart--radial" role="img"
                                 aria-label="Average OCR confidence {{ $percent($quality['average_confidence']) }}, human correction rate {{ $percent($quality['correction_rate']) }}, {{ number_format($quality['below_threshold']) }} below {{ $quality['threshold'] }}%"></div>
                            <div class="dashboard-quality__center" aria-hidden="true">
                                <span>Confidence</span>
                                <strong data-dashboard-count>{{ $percent($quality['average_confidence']) }}</strong>
                            </div>
                        </div>

                        <div class="dashboard-quality__chips" aria-hidden="true">
                            <span class="dashboard-quality__chip"
                                  title="Human correction rate: {{ number_format($quality['corrected_fields']) }} of {{ number_format($quality['comparable_fields']) }} fields edited">
                                <span class="dashboard-quality__dot bg-warning"></span>
                                Corrected <strong>{{ $percent($quality['correction_rate']) }}</strong>
                            </span>
                            <span class="dashboard-quality__chip"
                                  title="{{ number_format($quality['below_threshold']) }} {{ $quality['below_threshold'] === 1 ? 'field' : 'fields' }} below the {{ $quality['threshold'] }}% confidence floor">
                                <i class="icon-base bx bx-target-lock text-info"></i>
                                <strong>{{ number_format($quality['below_threshold']) }}</strong> below {{ $quality['threshold'] }}%
                            </span>
                        </div>
                    </div>
                </x-dashboard.panel>
            </div>

            {{-- Card 3: OCR Performance Mini Radar (Direct from OCR Workspace - Sneat Radar Template) --}}
            <div class="col-12 col-lg-4 dashboard-reveal" style="--dashboard-reveal: 2">
                <div class="card h-100 dashboard-panel dashboard-panel--performance" id="panel-ocr-performance">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-1 pb-1">
                        <div class="d-flex align-items-center gap-1.5">
                            <span class="dashboard-kpi__mini-icon bg-label-info">
                                <i class="icon-base bx bx-radar" aria-hidden="true"></i>
                            </span>
                            <h6 class="card-title mb-0 fw-semibold" style="font-size: 0.875rem;">OCR Performance</h6>
                        </div>

                        <div class="d-flex align-items-center gap-1">
                            @can('ocr.manage')
                                <a href="{{ route('ocr.index') }}" class="btn btn-xs btn-label-primary px-2" title="Manage models in OCR Workspace">
                                    <span>Workspace</span>
                                    <i class="icon-base bx bx-right-arrow-alt ms-1" aria-hidden="true"></i>
                                </a>
                            @else
                                <span class="badge bg-label-primary">Test benchmark</span>
                            @endcan
                        </div>
                    </div>

                    <div class="card-body d-flex flex-column pt-0 pb-2 px-3">
                        {{-- ApexCharts Radar Container; scores sit on the axis labels. --}}
                        <div id="dashboard-ocr-radar-chart" class="dashboard-chart dashboard-chart--radar" role="img"
                             aria-label="OCR accuracy radar. Characters {{ isset($activePerfProfile['scores']['character_accuracy']) ? $percent($activePerfProfile['scores']['character_accuracy']) : 'n/a' }}, words {{ isset($activePerfProfile['scores']['word_accuracy']) ? $percent($activePerfProfile['scores']['word_accuracy']) : 'n/a' }}, exact match {{ isset($activePerfProfile['scores']['exact_match']) ? $percent($activePerfProfile['scores']['exact_match']) : 'n/a' }}."></div>

                        {{-- Legend chips: same style and baseline as the OCR Quality chips. --}}
                        <div class="dashboard-quality__chips" id="dashboard-radar-legend">
                            <span class="dashboard-quality__chip" title="Active fine-tuned model">
                                <span class="dashboard-quality__dot" style="background-color: #ff6384;" aria-hidden="true"></span>
                                <strong class="text-truncate" id="dashboard-radar-model-label">{{ $activePerfProfile['display_label'] ?? $activePerfProfile['label'] ?? 'Fine-tuned' }}</strong>
                            </span>
                            <span class="dashboard-quality__chip" title="TrOCR Base">
                                <span class="dashboard-quality__dot" style="background-color: #696cff;" aria-hidden="true"></span>
                                Base
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <div class="row g-4 mb-4">
            <div class="col-12 col-lg-8 dashboard-reveal" style="--dashboard-reveal: 3">
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

            <div class="col-12 col-lg-4 dashboard-reveal" style="--dashboard-reveal: 4">
                <x-dashboard.panel panel="types" title="Document Type Distribution" class="dashboard-panel--types">
                    <x-slot:meta>{{ $typesScope['short_label'] }}</x-slot:meta>
                    <x-slot:tools>
                        <x-dashboard.period-filter panel="types" label="document type distribution"
                                                   :scopes="$scopes" :forms="$periodForms" :options="$periodOptions" />
                    </x-slot:tools>

                    @if ($typeSlices->isNotEmpty())
                        {{-- The bars carry each type's count and share; the total sits above. --}}
                        <div class="dashboard-types-sneat">
                            <div class="dashboard-types__total">
                                <strong data-dashboard-count>{{ number_format($typeTotal) }}</strong>
                                <span>{{ $typeTotal === 1 ? 'record' : 'records' }}</span>
                                <span class="badge bg-label-primary ms-auto">
                                    {{ $typeSlices->count() }} {{ $typeSlices->count() === 1 ? 'type' : 'types' }}
                                </span>
                            </div>

                            <div class="dashboard-types__chart-shell">
                                <div id="dashboard-type-chart" class="dashboard-chart dashboard-chart--bars" role="img"
                                     aria-label="Document type distribution across {{ number_format($typeTotal) }} records: {{ $typeSlices->map(fn ($slice) => $slice['label'].' '.number_format($slice['total']).' ('.$percent($slice['share']).')')->implode(', ') }}"></div>
                            </div>

                            @if ($inactiveTypeCount > 0)
                                <p class="dashboard-types__inactive mb-0"
                                   title="{{ number_format($inactiveTypeCount) }} configured {{ $inactiveTypeCount === 1 ? 'type has' : 'types have' }} no submissions in this period">
                                    <i class="icon-base bx bx-info-circle" aria-hidden="true"></i>
                                    +{{ number_format($inactiveTypeCount) }} with no records
                                </p>
                            @endif
                        </div>
                    @else
                        <x-empty-state icon="bx-bar-chart-alt-2" title="No submissions in this period"
                                       message="Choose a longer period to see document type shares." />
                    @endif
                </x-dashboard.panel>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-12 dashboard-reveal" style="--dashboard-reveal: 5">
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
