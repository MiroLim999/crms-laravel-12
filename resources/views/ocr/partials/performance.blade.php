@php
    $performanceProfiles = collect($modelPerformance['models'] ?? []);
    $performanceMetrics = collect($modelPerformance['metrics'] ?? []);
    $selectedPerformance = $performanceProfiles->firstWhere('key', $modelPerformance['selected'] ?? null)
        ?? $performanceProfiles->first();
    $selectedHasData = (bool) ($selectedPerformance['has_data'] ?? false);
    $selectedSource = $selectedPerformance['source'] ?? null;
    $sourceTone = match ($selectedSource) {
        'evaluation' => 'primary',
        default => 'secondary',
    };
@endphp

<section class="card ocr-performance-card mb-4"
         aria-labelledby="ocr-performance-title">
    <div class="card-header border-bottom">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="ocr-section-icon bg-label-primary" aria-hidden="true">
                    <i class="icon-base bx bx-radar"></i>
                </span>
                <div>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <h2 class="h5 card-title mb-0" id="ocr-performance-title">OCR model performance</h2>
                        <span class="badge bg-label-{{ $sourceTone }}" id="ocr-performance-source">
                            {{ $selectedPerformance['source_label'] ?? 'No data' }}
                        </span>
                    </div>
                    <p class="text-muted small mb-0 mt-1">
                        Compare fine-tuned recognition quality against pre-trained base model.
                    </p>
                </div>
            </div>

            <div class="ocr-performance-picker">
                <label class="form-label small mb-1" for="ocr-performance-model">Model</label>
                <select class="form-select form-select-sm" id="ocr-performance-model"
                        @disabled($performanceProfiles->count() < 2)>
                    @forelse ($performanceProfiles as $profile)
                        <option value="{{ $profile['key'] }}"
                                @selected(($selectedPerformance['key'] ?? null) === $profile['key'])>
                            {{ $profile['label'] }}{{ $profile['is_active'] ? ' (active)' : '' }}
                        </option>
                    @empty
                        <option value="">No models available</option>
                    @endforelse
                </select>
            </div>
        </div>
    </div>

    <div class="card-body">
        <div class="ocr-performance-layout">
            <div class="ocr-performance-visual">
                {{-- Comparison Controls Toolbar --}}
                <div class="ocr-performance-toolbar d-flex flex-wrap align-items-center justify-content-between gap-2 w-100 mb-3" id="ocr-performance-toolbar">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="badge bg-label-primary px-3 py-2 d-inline-flex align-items-center gap-1" id="ocr-toolbar-model-pill">
                            <span class="badge-dot bg-primary me-1"></span>
                            <span id="ocr-toolbar-model-name">{{ $selectedPerformance['label'] ?? 'Selected' }}</span>
                            <span class="text-muted ms-1">(Active)</span>
                        </span>
                        <span class="badge bg-label-warning px-3 py-2 d-inline-flex align-items-center gap-1" id="ocr-toolbar-base-pill">
                            <span class="badge-dot bg-warning me-1"></span>
                            <span>TrOCR Base</span>
                            <span class="text-muted ms-1">(Baseline)</span>
                        </span>
                    </div>

                    <div class="d-flex align-items-center gap-3 ms-auto">
                        <div class="form-check form-switch mb-0" id="ocr-compare-toggle-wrap">
                            <input class="form-check-input" type="checkbox" id="ocr-compare-toggle" checked>
                            <label class="form-check-label small fw-medium" for="ocr-compare-toggle">Overlay Base</label>
                        </div>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Visualization mode">
                            <button type="button" class="btn btn-primary" id="ocr-btn-view-radar" title="Radar polygon footprint">
                                <i class="icon-base bx bx-radar me-1"></i>Radar
                            </button>
                            <button type="button" class="btn btn-label-secondary" id="ocr-btn-view-bars" title="Head-to-head comparison bars">
                                <i class="icon-base bx bx-bar-chart-alt-2 me-1"></i>Side-by-Side
                            </button>
                        </div>
                    </div>
                </div>

                {{-- Quick Comparison KPI Delta Strip --}}
                <div class="ocr-performance-kpis w-100 mb-3" id="ocr-performance-kpis">
                    <div class="row g-2">
                        <div class="col-4">
                            <div class="ocr-kpi-card" data-kpi="character_accuracy">
                                <div class="ocr-kpi-label text-muted">Characters</div>
                                <div class="d-flex flex-wrap align-items-baseline gap-1 mt-1">
                                    <span class="ocr-kpi-val" data-kpi-val="character_accuracy">—</span>
                                    <span class="badge bg-label-success ocr-kpi-delta" data-kpi-delta="character_accuracy">—</span>
                                </div>
                                <div class="ocr-kpi-sub" data-kpi-sub="character_accuracy">vs Base</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="ocr-kpi-card" data-kpi="word_accuracy">
                                <div class="ocr-kpi-label text-muted">Words</div>
                                <div class="d-flex flex-wrap align-items-baseline gap-1 mt-1">
                                    <span class="ocr-kpi-val" data-kpi-val="word_accuracy">—</span>
                                    <span class="badge bg-label-success ocr-kpi-delta" data-kpi-delta="word_accuracy">—</span>
                                </div>
                                <div class="ocr-kpi-sub" data-kpi-sub="word_accuracy">vs Base</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="ocr-kpi-card" data-kpi="exact_match">
                                <div class="ocr-kpi-label text-muted">Exact match</div>
                                <div class="d-flex flex-wrap align-items-baseline gap-1 mt-1">
                                    <span class="ocr-kpi-val" data-kpi-val="exact_match">—</span>
                                    <span class="badge bg-label-success ocr-kpi-delta" data-kpi-delta="exact_match">—</span>
                                </div>
                                <div class="ocr-kpi-sub" data-kpi-sub="exact_match">vs Base</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="ocr-model-radar"
                     class="ocr-performance-chart {{ $selectedHasData ? '' : 'd-none' }}"
                     role="img"
                     aria-label="{{ $selectedHasData ? 'Performance radar for '.$selectedPerformance['label'] : 'No model performance data available' }}">
                </div>

                <div class="ocr-performance-empty {{ $selectedHasData ? 'd-none' : '' }}"
                     id="ocr-performance-empty" role="status">
                    <span class="ocr-empty-icon" aria-hidden="true">
                        <i class="icon-base bx bx-line-chart-down"></i>
                    </span>
                    <h3 class="h6 mb-1">No performance data yet</h3>
                    <p class="text-muted small mb-0" id="ocr-performance-empty-copy">
                        @if ($selectedPerformance)
                            {{ $selectedPerformance['label'] }} has no valid locked-test benchmark report.
                        @else
                            Add a model before reviewing its performance.
                        @endif
                    </p>
                </div>
            </div>

            <div class="ocr-performance-summary" aria-live="polite">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 pb-2 border-bottom">
                    <div class="min-w-0">
                        <div class="text-muted small text-uppercase fw-medium">Active Evaluation</div>
                        <div class="ocr-performance-model-name text-truncate" id="ocr-performance-model-name">
                            {{ $selectedPerformance['label'] ?? 'No model selected' }}
                        </div>
                    </div>
                    <span class="badge bg-label-success {{ ($selectedPerformance['is_active'] ?? false) ? '' : 'd-none' }}"
                          id="ocr-performance-active">Active</span>
                </div>

                <dl class="ocr-performance-metrics mb-3">
                    @foreach ($performanceMetrics as $metric)
                        @php($score = $selectedPerformance['scores'][$metric['key']] ?? null)
                        <div class="ocr-performance-metric" data-performance-metric="{{ $metric['key'] }}">
                            <div class="d-flex align-items-center justify-content-between gap-2">
                                <dt class="mb-0">
                                    <span class="ocr-performance-metric__key" aria-hidden="true"></span>
                                    {{ $metric['label'] }}
                                </dt>
                                <dd class="mb-0 d-flex align-items-center gap-2">
                                    <span class="fw-bold fs-6" data-performance-score="{{ $metric['key'] }}">
                                        {{ $score === null ? '—' : number_format($score, 1).'%' }}
                                    </span>
                                    <span class="badge bg-label-success d-none"
                                          data-performance-delta="{{ $metric['key'] }}"></span>
                                </dd>
                            </div>

                            <div class="ocr-metric-dual-track mt-2 mb-1">
                                <div class="ocr-track-row d-flex align-items-center gap-2">
                                    <span class="small text-muted ocr-track-label">Model</span>
                                    <div class="progress flex-grow-1 ocr-performance-metric__progress my-0">
                                        <div class="progress-bar"
                                             data-performance-bar="{{ $metric['key'] }}"
                                             style="width: {{ $score === null ? 0 : max(0, min(100, (float) $score)) }}%"></div>
                                    </div>
                                    <span class="small fw-semibold ocr-track-val" data-performance-bar-val="{{ $metric['key'] }}">
                                        {{ $score === null ? '—' : number_format($score, 1).'%' }}
                                    </span>
                                </div>
                                <div class="ocr-track-row ocr-track-base d-flex align-items-center gap-2 mt-1 d-none" data-performance-base-row="{{ $metric['key'] }}">
                                    <span class="small text-muted ocr-track-label">Base</span>
                                    <div class="progress flex-grow-1 ocr-performance-metric__progress ocr-progress-base my-0">
                                        <div class="progress-bar bg-warning"
                                             data-performance-base-bar="{{ $metric['key'] }}"
                                             style="width: 0%"></div>
                                    </div>
                                    <span class="small text-muted ocr-track-val" data-performance-base-val="{{ $metric['key'] }}">
                                        —
                                    </span>
                                </div>
                            </div>

                            <span class="text-muted small mt-1">{{ $metric['description'] }}</span>
                        </div>
                    @endforeach
                </dl>

                <div class="ocr-performance-evidence">
                    <i class="icon-base bx bx-data icon-sm" aria-hidden="true"></i>
                    <span id="ocr-performance-evidence">
                        {{ $selectedPerformance['evidence'] ?? 'No evidence available.' }}
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="card-footer">
        <p class="text-muted small mb-0">
            <i class="icon-base bx bx-info-circle icon-sm me-1" aria-hidden="true"></i>
            Scores use a 0–100 scale and come only from the evaluation report packaged with the model.
            Compare models only when they use the same locked test dataset and evaluation settings.
        </p>
    </div>
</section>

<script id="ocr-model-performance-data" type="application/json">@json($modelPerformance)</script>
