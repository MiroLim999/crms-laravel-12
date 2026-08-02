{{--
    Evaluation tab.

    This is the only place real quality figures come from: CER, WER, and exact match
    measured against ground-truth labels. Confidence, which the Predict tab and the
    scanning workspace show, is the model's certainty in its own output and is never
    presented as accuracy.
--}}
@php
    $withSplits = $datasets['datasets']->filter(fn ($d) => $d['on_disk'] && $d['total'] > 0);
    $usableModels = $overview['models']->filter(fn ($m) => $m['on_disk']);
    $busy = $activeJob !== null;
@endphp

<x-card title="Evaluate a model"
        subtitle="Runs a model over a labelled split and measures it against the ground truth.">
    @if ($withSplits->isEmpty() || $usableModels->isEmpty())
        <x-empty-state icon="bx-bar-chart-alt-2" title="Nothing to evaluate yet"
                       message="An evaluation needs both a model on disk and a dataset with labelled images." />
    @else
        <form method="POST" action="{{ route('ocr.jobs.evaluate') }}">
            @csrf

            <div class="row g-4">
                <div class="col-md-4">
                    <label for="eval-model" class="form-label">Model</label>
                    <select class="form-select" id="eval-model" name="model" required>
                        @foreach ($usableModels as $model)
                            <option value="{{ $model['key'] }}" @selected($model['is_active'])>
                                {{ $model['label'] }}{{ $model['is_active'] ? ' (active)' : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-4">
                    <label for="eval-dataset" class="form-label">Dataset</label>
                    <select class="form-select" id="eval-dataset" name="dataset" required>
                        @foreach ($withSplits as $dataset)
                            <option value="{{ $dataset['name'] }}">{{ $dataset['name'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <label for="eval-split" class="form-label">Split</label>
                    <select class="form-select" id="eval-split" name="split" required>
                        <option value="test" selected>test</option>
                        <option value="val">val</option>
                        <option value="train">train</option>
                    </select>
                    <div class="form-text">Use <code>test</code> for an honest figure.</div>
                </div>

                <div class="col-md-2">
                    <label for="eval-limit" class="form-label">Sample cap</label>
                    <input type="number" class="form-control" id="eval-limit" name="limit"
                           min="1" placeholder="all">
                    <div class="form-text">Blank evaluates the whole split.</div>
                </div>
            </div>

            <div class="alert alert-warning mt-4 mb-3 py-2 small d-flex align-items-start">
                <i class="icon-base bx bx-error icon-sm me-2"></i>
                <div>
                    Takes minutes and uses the GPU, so document scanning will be slower
                    while it runs. Only one GPU job at a time.
                </div>
            </div>

            <button type="submit" class="btn btn-primary" @disabled($busy || ! $engine['reachable'])>
                <i class="icon-base bx bx-play icon-sm me-1"></i> Run evaluation
            </button>

            @if ($busy)
                <span class="text-muted small ms-2">A job is already running.</span>
            @elseif (! $engine['reachable'])
                <span class="text-muted small ms-2">Start the OCR service first.</span>
            @endif
        </form>

        <p class="small text-muted mb-0 mt-3">
            Results are written onto the model's row on the Models tab, and a chart is
            saved below. Evaluating does not promote anything - that stays an explicit
            step.
        </p>
    @endif
</x-card>

{{-- Charts written by the evaluation runs, streamed through a gated route. --}}
<div class="row g-4 mt-2">
    @foreach ($charts as $variant => $files)
        <div class="col-lg-6">
            <x-card :title="ucfirst($variant).' model charts'"
                    :subtitle="'ml/evaluation-metrics/'.$variant.'/'">
                @forelse ($files->take(3) as $chart)
                    <figure class="mb-3">
                        <img src="{{ $chart['url'] }}" alt="{{ $variant }} evaluation chart"
                             class="img-fluid rounded border" loading="lazy">
                        <figcaption class="small text-muted mt-1">
                            {{ $chart['name'] }} · {{ $chart['modified']->diffForHumans() }}
                        </figcaption>
                    </figure>
                @empty
                    <x-empty-state icon="bx-bar-chart" title="No charts yet"
                                   message="Run an evaluation against a {{ $variant === 'base' ? 'base' : 'fine-tuned' }} model to generate one." />
                @endforelse
            </x-card>
        </div>
    @endforeach
</div>
