{{--
    Predict tab: spot-checking, not measurement.

    Synchronous and capped at 50 images, because it exists to answer "can this model
    read this handwriting?" in a few seconds. For a real number use an evaluation
    run, which compares against ground truth.

    Confidence here is the model's certainty in its own output. It is a review flag,
    never a quality guarantee, and must not be presented as accuracy.
--}}
@php
    $usableModels = $overview['models']->filter(fn ($m) => $m['on_disk']);
@endphp

<x-card title="Spot-check a model"
        subtitle="Drop in a few cropped handwriting images and see what a model reads.">
    @if ($usableModels->isEmpty())
        <x-empty-state icon="bx-search-alt" title="No model available"
                       message="Add or fine-tune a model first." />
    @else
        <div class="row g-3 align-items-end mb-3">
            <div class="col-md-6">
                <label for="predict-model" class="form-label">Model</label>
                <select class="form-select" id="predict-model">
                    @foreach ($usableModels as $model)
                        <option value="{{ $model['key'] }}" @selected($model['is_active'])>
                            {{ $model['label'] }}{{ $model['is_active'] ? ' (active)' : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="dropzone-area border rounded p-4 text-center"
             id="predict-dropzone"
             data-accept="image/*"
             data-role="predict">
            <i class="icon-base bx bx-cloud-upload icon-lg text-muted d-block mb-2"></i>
            <p class="mb-1"><strong>Drag images here</strong></p>
            <p class="text-muted small mb-3">
                or <button type="button" class="btn btn-sm btn-outline-primary" data-role="browse">browse</button>
            </p>
            <input type="file" class="d-none" accept="image/*" multiple data-role="input">
            <p class="text-muted small mb-0">
                PNG, JPG, BMP, or TIFF. Up to 50 images, 20&nbsp;MB each. Files are
                uploaded in chunks before prediction starts.
            </p>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
            <button type="button" class="btn btn-primary" id="predict-run" disabled>
                <i class="icon-base bx bx-play icon-sm me-1"></i> Run prediction
            </button>
            <button type="button" class="btn btn-outline-secondary d-none" id="predict-clear">
                Clear
            </button>
            <span class="text-muted small" id="predict-status"></span>
        </div>

        <div class="mt-4 d-none" id="predict-results">
            <div class="d-flex flex-wrap gap-3 mb-2 small">
                <span>Model: <strong id="predict-result-model"></strong></span>
                <span>Images: <strong id="predict-result-count"></strong></span>
                <span>Average confidence: <strong id="predict-result-average"></strong></span>
                <span>Below threshold: <strong id="predict-result-low"></strong></span>
            </div>

            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Predicted text</th>
                            <th class="text-end">Confidence</th>
                        </tr>
                    </thead>
                    <tbody id="predict-rows"></tbody>
                </table>
            </div>

            <p class="small text-muted mb-0">
                Confidence is how certain the model is about its own output, not how
                correct it is. These images have no ground-truth labels, so nothing here
                is a measure of accuracy — run an evaluation for that.
            </p>
        </div>
    @endif
</x-card>
