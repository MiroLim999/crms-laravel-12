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

            $accountActive = (int) $analytics['accounts']['active'];
            $accountInactive = (int) $analytics['accounts']['inactive'];
            $accountTotal = $accountActive + $accountInactive;
            $accountActiveRate = $accountTotal > 0 ? round($accountActive / $accountTotal * 100, 1) : 0.0;

            // What the archive holds, all time: the total card's breakdown. The
            // trend chart beside it shows the chosen period, over time.
            $archiveTypes = collect($analytics['archive_types']);
            $archiveTotal = (int) $archiveTypes->sum('total');
            $archiveShare = fn (int $total): float => $archiveTotal > 0 ? round($total / $archiveTotal * 100, 1) : 0.0;

            // Each panel keeps its own reporting period.
            $trendScope = $scopes['trend'];
            $staffScope = $scopes['staff'];
            $percent = fn (int|float|null $value): string => $value === null ? '—' : number_format($value, 1).'%';
            $comparisonNote = number_format($headline['period_records']).' in '.$trendScope['label']
                .' · '.number_format($headline['previous_records']).' in '.$trendScope['previous_label'];
            $belowNoun = $quality['below_threshold'] === 1 ? 'field' : 'fields';

            // OCR Performance sets the model in use against TrOCR Base, on the
            // scores each earned on the locked test set. A model with no scores
            // is named but never drawn, so the chart shows only measured numbers.
            $perfModels = collect($modelPerformance['models'] ?? []);
            $perfInUse = $perfModels->firstWhere('is_active', true);
            $perfBase = ($perfInUse['key'] ?? null) === 'base' ? null : $perfModels->firstWhere('key', 'base');
            $perfSeries = collect([
                $perfInUse && $perfInUse['has_data']
                    ? ['role' => 'model', 'name' => $perfInUse['display_label'], 'scores' => $perfInUse['scores']]
                    : null,
                $perfBase && $perfBase['has_data']
                    ? ['role' => 'base', 'name' => 'TrOCR Base', 'scores' => $perfBase['scores']]
                    : null,
            ])->filter()->values();
            $perfSummary = $perfSeries
                ->map(fn (array $series): string => $series['name']
                    .': characters '.$percent($series['scores']['character_accuracy'])
                    .', words '.$percent($series['scores']['word_accuracy'])
                    .', exact match '.$percent($series['scores']['exact_match']))
                ->implode('; ');

            $chartData = [
                'volume' => [
                    'labels' => $trend['labels'],
                    'titles' => $trend['titles'],
                    'series' => $trend['series'],
                ],
                'quality' => [
                    'averageConfidence' => $quality['average_confidence'],
                    'correctionRate' => $quality['correction_rate'],
                ],
                'throughput' => [
                    'labels' => $throughput->pluck('name')->values(),
                    'totals' => $throughput->pluck('total')->values(),
                ],
                'ocrPerformance' => ['series' => $perfSeries->all()],
            ];

            // The New document card, for those who can scan (not Admin).
            $canScan = $scanTypes !== null;
            $scanTypeCount = $canScan ? count($scanTypes) : 0;
            $readyTypeCount = $canScan ? collect($scanTypes)->where('ready', true)->count() : 0;
        @endphp

        {{--
            New document, OCR Quality and OCR Performance on top; the trend
            beside the record and account totals; throughput below. Admin
            cannot scan, so for Admin the two OCR cards share the top row.
        --}}
        <section class="row g-4 mb-4" aria-label="CRMS performance summary">
            @if ($canScan)
                {{-- Card 1: the whole card opens the New Document page. --}}
                <div class="col-12 col-md-6 col-lg-4 dashboard-reveal" style="--dashboard-reveal: 0">
                    <a href="{{ route('documents.create') }}" id="dashboard-new-document"
                       class="card dashboard-panel dashboard-scan h-100 text-reset"
                       aria-labelledby="dashboard-scan-title" aria-describedby="dashboard-scan-text dashboard-scan-types">
                        <div class="card-header">
                            <span class="dashboard-kpi__mini-icon bg-label-primary">
                                <i class="icon-base bx bx-scan" aria-hidden="true"></i>
                            </span>
                            <h5 id="dashboard-scan-title" class="card-title mb-0">New document</h5>
                            @if ($scanTypeCount === 0)
                                <span class="badge bg-label-warning">No document types</span>
                            @elseif ($readyTypeCount === $scanTypeCount)
                                <span class="badge bg-label-success">
                                    {{ $readyTypeCount }} {{ $readyTypeCount === 1 ? 'type' : 'types' }} ready
                                </span>
                            @else
                                <span class="badge bg-label-warning">{{ $readyTypeCount }} of {{ $scanTypeCount }} ready</span>
                            @endif
                        </div>

                        <div class="card-body">
                            {{-- Paper in, fields marked and checked, record archived. --}}
                            <div class="dashboard-scan__stage" aria-hidden="true">
                                <svg class="dashboard-scan__art" viewBox="0 0 240 120" focusable="false">
                                    <defs>
                                        <linearGradient id="dashboard-scan-glow" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="0" class="dashboard-scan__glow-edge" />
                                            <stop offset=".5" class="dashboard-scan__glow-core" />
                                            <stop offset="1" class="dashboard-scan__glow-edge" />
                                        </linearGradient>
                                    </defs>

                                    <g class="dashboard-scan__step">
                                        <circle cx="34" cy="60" r="15" />
                                        <path d="M34 64V52.5m-4.5 4.5 4.5-4.5 4.5 4.5M27 61.5v3a1.5 1.5 0 0 0 1.5 1.5h11a1.5 1.5 0 0 0 1.5-1.5v-3" />
                                    </g>
                                    <path class="dashboard-scan__link" d="M53 60h17" />
                                    <path class="dashboard-scan__arrow" d="m69 57 3 3-3 3" />

                                    <rect class="dashboard-scan__sheet dashboard-scan__sheet--back"
                                          x="94" y="13" width="62" height="96" rx="5" transform="rotate(7 125 61)" />
                                    <g class="dashboard-scan__page">
                                        <rect class="dashboard-scan__sheet" x="88" y="10" width="64" height="100" rx="5" />
                                        <rect class="dashboard-scan__ink dashboard-scan__ink--strong" x="96" y="18" width="30" height="4" rx="2" />
                                        <circle class="dashboard-scan__seal" cx="142" cy="20.5" r="5" />
                                        <rect class="dashboard-scan__field" x="94" y="30" width="52" height="15" rx="2.5" />
                                        <path class="dashboard-scan__hand" d="M98 39.5q2.5-5 5 0t5 0 5 0 5 0 5 0 5 0 5 0 5 0" />
                                        <rect class="dashboard-scan__ink" x="96" y="51" width="44" height="2.5" rx="1.25" />
                                        <rect class="dashboard-scan__ink" x="96" y="58" width="32" height="2.5" rx="1.25" />
                                        <rect class="dashboard-scan__field" x="94" y="66" width="52" height="15" rx="2.5" />
                                        <path class="dashboard-scan__hand" d="M98 75.5q2-4.5 4 0t4 0 4 0 4 0 4 0 4 0 4 0 4 0 4 0" />
                                        <rect class="dashboard-scan__ink" x="96" y="88" width="40" height="2.5" rx="1.25" />
                                        <rect class="dashboard-scan__ink" x="96" y="95" width="26" height="2.5" rx="1.25" />
                                    </g>
                                    <path class="dashboard-scan__corner" d="M80 17V4h13M147 4h13v13M160 103v13h-13M93 116H80v-13" />
                                    <g class="dashboard-scan__beam">
                                        <rect x="82" y="-10" width="76" height="20" fill="url(#dashboard-scan-glow)" />
                                        <rect class="dashboard-scan__line" x="82" y="-1" width="76" height="2" rx="1" />
                                    </g>
                                    <g class="dashboard-scan__check">
                                        <circle cx="152" cy="94" r="8.5" />
                                        <path d="m148.2 94.2 2.6 2.6 5-5.3" />
                                    </g>

                                    <path class="dashboard-scan__link" d="M168 60h17" />
                                    <path class="dashboard-scan__arrow" d="m184 57 3 3-3 3" />
                                    <g class="dashboard-scan__step dashboard-scan__step--done">
                                        <circle cx="206" cy="60" r="15" />
                                        <path d="M197 52h18v4.5h-18zm1.5 4.5h15V66a1.5 1.5 0 0 1-1.5 1.5h-12a1.5 1.5 0 0 1-1.5-1.5zm5 4h5" />
                                    </g>
                                </svg>
                            </div>

                            <p id="dashboard-scan-text" class="dashboard-scan__text">
                                Scan a paper certificate, check what was read, and save it to the archive.
                            </p>

                            <ul id="dashboard-scan-types" class="dashboard-scan__types" aria-label="Document types">
                                @foreach ($scanTypes as $type)
                                    <li @class(['dashboard-scan__type', 'dashboard-scan__type--off' => ! $type['ready']])>
                                        <i class="icon-base bx {{ $type['icon'] }}" aria-hidden="true"></i>
                                        <span class="text-truncate">{{ $type['label'] }}</span>
                                        @unless ($type['ready'])
                                            <small>no template</small>
                                        @endunless
                                    </li>
                                @endforeach
                            </ul>

                            <span class="btn btn-primary dashboard-scan__action">
                                Start scanning
                                <i class="icon-base bx bx-right-arrow-alt" aria-hidden="true"></i>
                            </span>
                        </div>
                    </a>
                </div>
            @endif

            {{-- Card 2: OCR Quality / Accuracy (Radial Gauge) --}}
            <div @class(['col-12 col-md-6 dashboard-reveal', 'col-lg-4' => $canScan]) style="--dashboard-reveal: 1">
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
                                 aria-label="Average OCR confidence {{ $percent($quality['average_confidence']) }}, human correction rate {{ $percent($quality['correction_rate']) }}, {{ number_format($quality['below_threshold']) }} {{ $belowNoun }} below {{ $quality['threshold'] }}%"></div>
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
                                  title="{{ number_format($quality['below_threshold']) }} {{ $belowNoun }} below the {{ $quality['threshold'] }}% confidence floor">
                                <i class="icon-base bx bx-target-lock text-info"></i>
                                <strong>{{ number_format($quality['below_threshold']) }}</strong> {{ $belowNoun }} below {{ $quality['threshold'] }}%
                            </span>
                        </div>
                    </div>
                </x-dashboard.panel>
            </div>

            {{-- Card 3: OCR Performance Mini Radar (Direct from OCR Workspace - Sneat Radar Template) --}}
            <div @class(['col-12 dashboard-reveal', 'col-lg-4' => $canScan, 'col-md-6' => ! $canScan]) style="--dashboard-reveal: 2">
                <div class="card h-100 dashboard-panel dashboard-panel--performance" id="panel-ocr-performance">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-1 pb-1">
                        <div class="d-flex align-items-center gap-1.5">
                            <span class="dashboard-kpi__mini-icon bg-label-info">
                                <i class="icon-base bx bx-radar" aria-hidden="true"></i>
                            </span>
                            <h5 class="card-title mb-0">OCR Performance</h5>
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
                        @if ($perfSeries->isEmpty())
                            {{-- Nothing measured, so nothing is drawn. --}}
                            <div class="my-auto">
                                <x-empty-state icon="bx-radar" title="No test scores yet"
                                               :message="$perfInUse ? $perfInUse['display_label'].' has no test results yet.' : 'No OCR model is in use yet.'" />
                            </div>
                        @else
                            {{-- ApexCharts radar; the first model's scores sit on the axis labels. --}}
                            <div id="dashboard-ocr-radar-chart" class="dashboard-chart dashboard-chart--radar" role="img"
                                 aria-label="OCR test scores. {{ $perfSummary }}."></div>

                            {{-- Legend chips: same style and baseline as the OCR Quality chips.
                                 A model in use without scores is named, without a dot. --}}
                            <div class="dashboard-quality__chips">
                                @if ($perfInUse)
                                    <span class="dashboard-quality__chip" title="The model in use">
                                        @if ($perfInUse['has_data'])
                                            <span class="dashboard-quality__dot" style="background-color: #ff6384;" aria-hidden="true"></span>
                                        @endif
                                        <strong class="text-truncate">{{ $perfInUse['display_label'] }}</strong>
                                        @unless ($perfInUse['has_data'])
                                            no test scores
                                        @endunless
                                    </span>
                                @else
                                    <span class="dashboard-quality__chip">No model in use</span>
                                @endif
                                @if ($perfSeries->contains('role', 'base'))
                                    <span class="dashboard-quality__chip" title="TrOCR Base">
                                        <span class="dashboard-quality__dot" style="background-color: #696cff;" aria-hidden="true"></span>
                                        Base
                                    </span>
                                @endif
                            </div>
                        @endif
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
                        <span>
                            <strong>{{ number_format($headline['period_records']) }}</strong> submitted
                            · {{ $trend['mode'] === 'day' ? 'by day' : 'by month' }} · {{ $trendScope['short_label'] }}
                        </span>
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

            {{-- What CRMS holds now: the archive by document type, and the
                 accounts. Nothing here follows a period; the trend beside it
                 shows change over time. --}}
            <div class="col-12 col-lg-4 dashboard-reveal" style="--dashboard-reveal: 4">
                <div id="summary-total" class="dashboard-kpi dashboard-kpi--combined card h-100">
                    <div class="card-body">
                        <div class="dashboard-kpi-subcard">
                            <div class="dashboard-kpi__head">
                                <span class="dashboard-kpi__mini-icon bg-label-primary">
                                    <i class="icon-base bx bx-archive" aria-hidden="true"></i>
                                </span>
                                <span class="dashboard-kpi__label">Total digitized records</span>
                                <a href="{{ route('reports.index', ['status' => 'submitted']) }}" class="btn btn-xs btn-label-primary px-2" title="Open the report of submitted records">
                                    <span>View report</span>
                                    <i class="icon-base bx bx-right-arrow-alt ms-1" aria-hidden="true"></i>
                                </a>
                            </div>

                            <div class="dashboard-kpi__group">
                                <span class="dashboard-kpi__number">
                                    <strong class="dashboard-kpi__value" data-dashboard-count>{{ number_format($headline['records']) }}</strong>
                                    <span>all time</span>
                                </span>

                                @if ($archiveTypes->isNotEmpty())
                                    {{-- One bar split by document type, then each type with its count and share. --}}
                                    <div class="dashboard-archive__bar" aria-hidden="true">
                                        @foreach ($archiveTypes as $type)
                                            <span style="flex-grow: {{ $type['total'] }}; --dashboard-type-color: {{ $type['color'] }}"
                                                  title="{{ $type['name'] }}: {{ number_format($type['total']) }}"></span>
                                        @endforeach
                                    </div>
                                    <ul class="dashboard-archive__types" aria-label="Records by document type">
                                        @foreach ($archiveTypes as $type)
                                            <li>
                                                <span class="dashboard-legend__dot" style="--dashboard-type-color: {{ $type['color'] }}" aria-hidden="true"></span>
                                                <span class="dashboard-archive__name" title="{{ $type['name'] }}">{{ $type['name'] }}</span>
                                                <strong>{{ number_format($type['total']) }}</strong>
                                                <span class="dashboard-archive__share">{{ $percent($archiveShare($type['total'])) }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p class="dashboard-kpi__meta">Nothing has been archived yet.</p>
                                @endif
                            </div>
                        </div>

                        <div class="dashboard-kpi__divider"></div>

                        <div class="dashboard-kpi-subcard">
                            <div class="dashboard-kpi__head">
                                <span class="dashboard-kpi__mini-icon bg-label-success">
                                    <i class="icon-base bx bx-user-check" aria-hidden="true"></i>
                                </span>
                                <span class="dashboard-kpi__label">Active accounts</span>
                                <a href="{{ route('users.index') }}" class="btn btn-xs btn-label-success px-2" title="Open User Accounts">
                                    <span>Manage users</span>
                                    <i class="icon-base bx bx-right-arrow-alt ms-1" aria-hidden="true"></i>
                                </a>
                            </div>

                            <div class="dashboard-kpi__group">
                                <div class="dashboard-kpi__figure">
                                    <strong class="dashboard-kpi__value" data-dashboard-count>{{ number_format($accountActive) }}</strong>
                                    <span class="dashboard-kpi__health-label">
                                        <strong class="dashboard-kpi__health-rate">{{ $percent($accountActiveRate) }}</strong> active
                                    </span>
                                </div>
                                <div class="dashboard-kpi__health-details">
                                    <span>{{ number_format($accountTotal) }} total</span>
                                    <span>
                                        <i aria-hidden="true"></i>
                                        {{ number_format($accountInactive) }} inactive
                                    </span>
                                </div>
                                <div class="dashboard-kpi__health-track" aria-hidden="true"
                                     style="--dashboard-kpi-health: {{ number_format($accountActiveRate, 1, '.', '') }}%">
                                    <span></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
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
