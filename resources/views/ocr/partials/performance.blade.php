@php
    $performanceProfiles = collect($modelPerformance['models'] ?? []);
    $performanceMetrics = collect($modelPerformance['metrics'] ?? []);
    $selectedPerformance = $performanceProfiles->firstWhere('key', $modelPerformance['selected'] ?? null)
        ?? $performanceProfiles->first();
    $selectedHasData = (bool) ($selectedPerformance['has_data'] ?? false);
@endphp

<section class="card ocr-performance-card h-100"
         aria-labelledby="ocr-performance-title">
    <div class="card-header border-bottom">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3 min-w-0">
                <span class="ocr-section-icon bg-label-primary" aria-hidden="true">
                    <i class="icon-base bx bx-bar-chart-alt-2"></i>
                </span>
                <div class="min-w-0">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <h2 class="h5 card-title mb-0" id="ocr-performance-title">OCR model performance</h2>
                        <span class="badge bg-label-{{ ($selectedPerformance['source'] ?? null) === 'evaluation' ? 'primary' : 'secondary' }}" id="ocr-performance-source">
                            {{ $selectedPerformance['source_label'] ?? 'No data' }}
                        </span>
                    </div>
                    <p class="text-muted small mb-0 mt-1" id="ocr-performance-evidence">
                        {{ $selectedPerformance['evidence'] ?? 'Locked test-set evaluation (CER, WER, Exact Match) comparing fine-tuned weights against baseline.' }}
                    </p>
                </div>
            </div>

            <div class="ocr-inspected-indicator d-flex align-items-center gap-2 flex-shrink-0">
                <span class="text-muted small">Inspecting:</span>
                <span class="badge bg-label-primary font-monospace fw-semibold" id="ocr-inspected-model-name">
                    {{ $selectedPerformance['display_label'] ?? $selectedPerformance['label'] ?? 'Selected model' }}
                </span>
            </div>
        </div>
    </div>

    <div class="card-body d-flex flex-column">
        <div class="ocr-performance-visual flex-grow-1">
            {{-- Quick Comparison KPI Delta Strip --}}
            <div class="ocr-performance-kpis w-100 mb-3 {{ $selectedHasData ? '' : 'd-none' }}" id="ocr-performance-kpis">
                <div class="row g-2">
                    <div class="col-4">
                        <div class="ocr-kpi-card" data-kpi="character_accuracy">
                            <div class="ocr-kpi-label text-muted">Characters (1 - CER)</div>
                            <div class="d-flex flex-wrap align-items-baseline gap-1.5 mt-1">
                                <span class="ocr-kpi-val" data-kpi-val="character_accuracy">—</span>
                                <span class="badge bg-label-success ocr-kpi-delta" data-kpi-delta="character_accuracy">—</span>
                            </div>
                            <div class="ocr-kpi-sub" data-kpi-sub="character_accuracy">vs Base</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="ocr-kpi-card" data-kpi="word_accuracy">
                            <div class="ocr-kpi-label text-muted">Words (1 - WER)</div>
                            <div class="d-flex flex-wrap align-items-baseline gap-1.5 mt-1">
                                <span class="ocr-kpi-val" data-kpi-val="word_accuracy">—</span>
                                <span class="badge bg-label-success ocr-kpi-delta" data-kpi-delta="word_accuracy">—</span>
                            </div>
                            <div class="ocr-kpi-sub" data-kpi-sub="word_accuracy">vs Base</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="ocr-kpi-card" data-kpi="exact_match">
                            <div class="ocr-kpi-label text-muted">Exact Match</div>
                            <div class="d-flex flex-wrap align-items-baseline gap-1.5 mt-1">
                                <span class="ocr-kpi-val" data-kpi-val="exact_match">—</span>
                                <span class="badge bg-label-success ocr-kpi-delta" data-kpi-delta="exact_match">—</span>
                            </div>
                            <div class="ocr-kpi-sub" data-kpi-sub="exact_match">vs Base</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Visualization Controls Toolbar (Top legend removed; only bottom ApexCharts legend is retained) --}}
            <div class="ocr-performance-toolbar d-flex flex-wrap align-items-center justify-content-end gap-3 w-100 mb-3 {{ $selectedHasData ? '' : 'd-none' }}" id="ocr-performance-toolbar">
                <div class="form-check form-switch mb-0" id="ocr-compare-toggle-wrap">
                    <input class="form-check-input" type="checkbox" id="ocr-compare-toggle" checked>
                    <label class="form-check-label small fw-medium" for="ocr-compare-toggle">Overlay Base</label>
                </div>
                <div class="btn-group btn-group-sm" role="group" aria-label="Visualization mode">
                    <button type="button" class="btn btn-primary" id="ocr-btn-view-bars" title="Head-to-head bar comparison (linear & undistorted)">
                        <i class="icon-base bx bx-bar-chart-alt-2 me-1"></i>Side-by-Side
                    </button>
                    <button type="button" class="btn btn-label-secondary" id="ocr-btn-view-radar" title="Radar polygon footprint">
                        <i class="icon-base bx bx-radar me-1"></i>Radar
                    </button>
                </div>
            </div>

            {{-- Chart Container --}}
            <div id="ocr-model-radar"
                 class="ocr-performance-chart {{ $selectedHasData ? '' : 'd-none' }}"
                 role="img"
                 aria-label="{{ $selectedHasData ? 'Performance comparison for '.($selectedPerformance['display_label'] ?? $selectedPerformance['label']) : 'No model performance data available' }}">
            </div>

            {{-- Empty State with CLI Guidance --}}
            <div class="ocr-performance-empty {{ $selectedHasData ? 'd-none' : '' }}"
                 id="ocr-performance-empty" role="status">
                <span class="ocr-empty-icon" aria-hidden="true">
                    <i class="icon-base bx bx-line-chart-down"></i>
                </span>
                <h3 class="h6 mb-1" id="ocr-performance-empty-title">No benchmark data yet</h3>
                <p class="text-muted small mb-2" id="ocr-performance-empty-copy">
                    {{ $selectedPerformance['display_label'] ?? $selectedPerformance['label'] ?? 'This model' }} has no valid locked-test benchmark report.
                </p>
                <div class="ocr-empty-command-box font-monospace small bg-label-secondary py-1.5 px-3 rounded text-break" id="ocr-empty-command">
                    php artisan ocr:evaluate {{ $selectedPerformance['key'] ?? 'model_key' }}
                </div>
            </div>
        </div>
    </div>

    <div class="card-footer py-2.5">
        <p class="text-muted small mb-0">
            <i class="icon-base bx bx-info-circle icon-sm me-1" aria-hidden="true"></i>
            Scores are evaluated on identical locked test splits without teacher forcing. Error reduction reflects relative improvement over the pre-trained baseline.
        </p>
    </div>
</section>

<script id="ocr-model-performance-data" type="application/json">@json($modelPerformance)</script>
