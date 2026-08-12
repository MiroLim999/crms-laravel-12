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
                        Recognition quality for one model at a time.
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
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                    <div class="min-w-0">
                        <div class="text-muted small">Selected model</div>
                        <div class="fw-semibold text-truncate" id="ocr-performance-model-name">
                            {{ $selectedPerformance['label'] ?? 'No model selected' }}
                        </div>
                    </div>
                    <span class="badge bg-label-success {{ ($selectedPerformance['is_active'] ?? false) ? '' : 'd-none' }}"
                          id="ocr-performance-active">Active</span>
                </div>

                <dl class="ocr-performance-metrics mb-3">
                    @foreach ($performanceMetrics as $metric)
                        @php($score = $selectedPerformance['scores'][$metric['key']] ?? null)
                        <div class="ocr-performance-metric">
                            <dt>{{ $metric['label'] }}</dt>
                            <dd data-performance-score="{{ $metric['key'] }}">
                                {{ $score === null ? '—' : number_format($score, 1).'%' }}
                            </dd>
                            <span>{{ $metric['description'] }}</span>
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
