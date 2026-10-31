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
        {{-- Wraps as a whole: in a narrow card the pill drops below the title
             instead of squeezing the title onto two lines. --}}
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 gap-sm-3">
            <div class="d-flex align-items-center gap-3 min-w-0">
                <span class="ocr-section-icon bg-label-primary" aria-hidden="true">
                    <i class="icon-base bx bx-bar-chart-alt-2"></i>
                </span>
                <h2 class="h5 card-title mb-0 text-nowrap" id="ocr-performance-title">OCR model performance</h2>
            </div>

            <div class="ocr-inspected-indicator d-flex align-items-center gap-2 min-w-0">
                <span class="text-muted small">Inspecting:</span>
                <span class="badge bg-label-primary font-monospace fw-semibold" id="ocr-inspected-model-name">
                    {{ $selectedPerformance['display_label'] ?? $selectedPerformance['label'] ?? 'Selected model' }}
                </span>
            </div>
        </div>
    </div>

    <div class="card-body d-flex flex-column ocr-performance-body">
        <div class="ocr-performance-visual flex-grow-1">
            {{--
                One card per metric. Every number on a card is in the same unit as
                its headline (accuracy), and the badge is the gap to the base model
                in percentage points. The error rate is shown once, on the last line.
            --}}
            @php
                $kpis = [
                    'character_accuracy' => 'Characters',
                    'word_accuracy' => 'Words',
                    'exact_match' => 'Exact match',
                ];
            @endphp
            <div class="ocr-performance-kpis {{ $selectedHasData ? '' : 'd-none' }}" id="ocr-performance-kpis">
                @foreach ($kpis as $key => $label)
                    <div class="ocr-kpi-card" data-kpi="{{ $key }}">
                        <div class="ocr-kpi-label">{{ $label }}</div>
                        <div class="ocr-kpi-figure">
                            <span class="ocr-kpi-val" data-kpi-val="{{ $key }}">—</span>
                            <span class="badge bg-label-success ocr-kpi-delta d-none" data-kpi-delta="{{ $key }}">—</span>
                        </div>
                        <div class="ocr-kpi-sub" data-kpi-sub="{{ $key }}">&nbsp;</div>
                    </div>
                @endforeach
                {{-- Processing time, from real scans in this system, not from the test set. --}}
                <div class="ocr-kpi-card" title="How long TrOCR takes to read one line in real scans in this system (not on the test set)">
                    <div class="ocr-kpi-label">Reading speed</div>
                    <div class="ocr-kpi-figure">
                        <span class="ocr-kpi-val" data-reading-val>—</span>
                    </div>
                    <div class="ocr-kpi-sub" data-reading-sub>&nbsp;</div>
                </div>
            </div>

            <div class="ocr-performance-toolbar {{ $selectedHasData ? '' : 'd-none' }}" id="ocr-performance-toolbar">
                <div class="form-check form-switch mb-0" id="ocr-compare-toggle-wrap">
                    <input class="form-check-input" type="checkbox" id="ocr-compare-toggle" checked>
                    <label class="form-check-label small fw-medium" for="ocr-compare-toggle">Overlay base model</label>
                </div>
                <div class="btn-group btn-group-sm" role="group" aria-label="Chart type">
                    <button type="button" class="btn btn-label-secondary" id="ocr-btn-view-radar" aria-pressed="false"
                            title="Radar chart">
                        <i class="icon-base bx bx-radar me-1" aria-hidden="true"></i>Radar
                    </button>
                    <button type="button" class="btn btn-primary" id="ocr-btn-view-bars" aria-pressed="true"
                            title="Bar chart, side by side">
                        <i class="icon-base bx bx-bar-chart-alt-2 me-1" aria-hidden="true"></i>Side-by-side
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
                    python ml/test_finetuned.py --model {{ $selectedPerformance['key'] ?? 'model_key' }}
                </div>
            </div>
        </div>
    </div>

    <div class="card-footer py-2.5">
        {{-- Speed on real scans, not the test set; the script fills it for the model being inspected. --}}
        <p class="small mb-2" id="ocr-reading-speed" aria-live="polite">
            <i class="icon-base bx bx-time-five icon-sm me-1" aria-hidden="true"></i>
            <span data-reading-text>—</span>
        </p>
        <p class="text-muted small mb-0">
            <i class="icon-base bx bx-info-circle icon-sm me-1" aria-hidden="true"></i>
            <span data-benchmark-note>
                @if ($selectedHasData)
                    Scored on {{ number_format($selectedPerformance['sample_count']) }} test images ({{ $selectedPerformance['dataset'] }}){{ $selectedPerformance['evaluated_at'] ? ', evaluated '.$selectedPerformance['evaluated_at'] : '' }}.
                    These are test-set scores, not accuracy on real scans: see OCR Quality on the Dashboard.
                @else
                    This model has no test-set scores yet.
                @endif
            </span>
        </p>
    </div>
</section>

<script id="ocr-model-performance-data" type="application/json">@json($modelPerformance)</script>
