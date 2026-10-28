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
        {{-- Same columns as the body below, so the picker sits above the Models panel. --}}
        <div class="ocr-performance-head">
            <div class="d-flex align-items-center gap-3 min-w-0">
                <span class="ocr-section-icon bg-label-primary" aria-hidden="true">
                    <i class="icon-base bx bx-radar"></i>
                </span>
                <div class="min-w-0">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <h2 class="h5 card-title mb-0" id="ocr-performance-title">OCR model performance</h2>
                        <span class="badge bg-label-{{ $sourceTone }}" id="ocr-performance-source">
                            {{ $selectedPerformance['source_label'] ?? 'No data' }}
                        </span>
                        @include('ocr.partials.engine')
                    </div>
                    <p class="text-muted small mb-0 mt-1">
                        Locked test-set evaluation (CER, WER, Exact Match) comparing fine-tuned weights against baseline.
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
                            {{ $profile['display_label'] ?? $profile['label'] }}{{ $profile['is_active'] ? ' (active)' : '' }}
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
            {{-- Left: Visual Comparison & KPI Scorecards --}}
            <div class="ocr-performance-visual">
                {{-- Quick Comparison KPI Delta Strip --}}
                <div class="ocr-performance-kpis w-100 mb-3 {{ $selectedHasData ? '' : 'd-none' }}" id="ocr-performance-kpis">
                    <div class="row g-2">
                        <div class="col-4">
                            <div class="ocr-kpi-card" data-kpi="character_accuracy">
                                <div class="ocr-kpi-label text-muted">Characters</div>
                                <div class="d-flex flex-wrap align-items-baseline gap-1.5 mt-1">
                                    <span class="ocr-kpi-val" data-kpi-val="character_accuracy">—</span>
                                    <span class="badge bg-label-success ocr-kpi-delta" data-kpi-delta="character_accuracy">—</span>
                                </div>
                                <div class="ocr-kpi-sub" data-kpi-sub="character_accuracy">vs Base</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="ocr-kpi-card" data-kpi="word_accuracy">
                                <div class="ocr-kpi-label text-muted">Words</div>
                                <div class="d-flex flex-wrap align-items-baseline gap-1.5 mt-1">
                                    <span class="ocr-kpi-val" data-kpi-val="word_accuracy">—</span>
                                    <span class="badge bg-label-success ocr-kpi-delta" data-kpi-delta="word_accuracy">—</span>
                                </div>
                                <div class="ocr-kpi-sub" data-kpi-sub="word_accuracy">vs Base</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="ocr-kpi-card" data-kpi="exact_match">
                                <div class="ocr-kpi-label text-muted">Exact match</div>
                                <div class="d-flex flex-wrap align-items-baseline gap-1.5 mt-1">
                                    <span class="ocr-kpi-val" data-kpi-val="exact_match">—</span>
                                    <span class="badge bg-label-success ocr-kpi-delta" data-kpi-delta="exact_match">—</span>
                                </div>
                                <div class="ocr-kpi-sub" data-kpi-sub="exact_match">vs Base</div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Visualization Controls Toolbar --}}
                <div class="ocr-performance-toolbar d-flex flex-wrap align-items-center justify-content-between gap-2 w-100 mb-3 {{ $selectedHasData ? '' : 'd-none' }}" id="ocr-performance-toolbar">
                    <div class="ocr-toolbar-legend d-flex flex-wrap align-items-center gap-2">
                        <span class="badge bg-label-primary px-2.5 py-1.5 d-inline-flex align-items-center gap-1" id="ocr-toolbar-model-pill">
                            <span class="badge-dot bg-primary me-1"></span>
                            <span id="ocr-toolbar-model-name">{{ $selectedPerformance['display_label'] ?? $selectedPerformance['label'] ?? 'Selected' }}</span>
                        </span>
                        <span class="badge bg-label-warning px-2.5 py-1.5 d-inline-flex align-items-center gap-1" id="ocr-toolbar-base-pill">
                            <span class="badge-dot bg-warning me-1"></span>
                            <span>TrOCR Base (Baseline)</span>
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
                            <button type="button" class="btn btn-label-secondary" id="ocr-btn-view-bars" title="Head-to-head bar comparison">
                                <i class="icon-base bx bx-bar-chart-alt-2 me-1"></i>Side-by-Side
                            </button>
                        </div>
                    </div>
                </div>

                {{-- Chart Container --}}
                <div id="ocr-model-radar"
                     class="ocr-performance-chart {{ $selectedHasData ? '' : 'd-none' }}"
                     role="img"
                     aria-label="{{ $selectedHasData ? 'Performance comparison for '.($selectedPerformance['display_label'] ?? $selectedPerformance['label']) : 'No model performance data available' }}">
                </div>

                {{-- Empty State --}}
                <div class="ocr-performance-empty {{ $selectedHasData ? 'd-none' : '' }}"
                     id="ocr-performance-empty" role="status">
                    <span class="ocr-empty-icon" aria-hidden="true">
                        <i class="icon-base bx bx-line-chart-down"></i>
                    </span>
                    <h3 class="h6 mb-1">No performance data yet</h3>
                    <p class="text-muted small mb-0" id="ocr-performance-empty-copy">
                        @if ($selectedPerformance)
                            {{ $selectedPerformance['display_label'] ?? $selectedPerformance['label'] }} has no valid locked-test benchmark report.
                        @else
                            Add a model before reviewing its performance.
                        @endif
                    </p>
                </div>
            </div>

            {{-- Right: the models, with the actions that apply to them --}}
            @include('ocr.partials.models')
        </div>
    </div>

    <div class="card-footer">
        <p class="text-muted small mb-0">
            <i class="icon-base bx bx-info-circle icon-sm me-1" aria-hidden="true"></i>
            Scores are evaluated on identical locked test splits without teacher forcing. Error reduction reflects relative improvement over the pre-trained baseline.
        </p>
    </div>
</section>

<script id="ocr-model-performance-data" type="application/json">@json($modelPerformance)</script>
