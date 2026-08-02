{{--
    Fine-tuning tab.

    Defaults come from train_trocr.py via /training_defaults, so the numbers live in
    one place. Each field carries a one-line explanation: a Super Admin should be
    able to train without understanding every hyperparameter.
--}}
@php
    $trainable = $datasets['datasets']->filter(fn ($d) => $d['trainable']);
    $usableModels = $overview['models']->filter(fn ($m) => $m['on_disk']);
    $busy = $activeJob !== null;
@endphp

<x-card title="Fine-tune a model"
        subtitle="Trains TrOCR on one of your datasets and saves the result as a new model.">
    @if ($trainable->isEmpty())
        <x-empty-state icon="bx-folder" title="No dataset is ready to train on"
                       message="Upload a dataset and let it pass validation first. Training on a broken manifest fails hours into the run." />
    @else
        <form method="POST" action="{{ route('ocr.jobs.train') }}" id="training-form">
            @csrf

            <div class="row g-4">
                <div class="col-md-6">
                    <label for="train-dataset" class="form-label">Dataset</label>
                    <select class="form-select" id="train-dataset" name="dataset" required>
                        @foreach ($trainable as $dataset)
                            <option value="{{ $dataset['name'] }}">
                                {{ $dataset['name'] }}
                                ({{ number_format($dataset['usable_train']) }} train /
                                {{ number_format($dataset['val']) }} val)
                            </option>
                        @endforeach
                    </select>
                    <div class="form-text">Only datasets that passed validation are listed.</div>
                </div>

                <div class="col-md-6">
                    <label for="train-base" class="form-label">Starting point</label>
                    <select class="form-select" id="train-base" name="base_model">
                        <option value="base">TrOCR base (not fine-tuned)</option>
                        @foreach ($usableModels->reject(fn ($m) => $m['is_base']) as $model)
                            <option value="{{ $model['key'] }}">
                                Continue from {{ $model['label'] }}
                            </option>
                        @endforeach
                    </select>
                    <div class="form-text">
                        Start from the upstream base model, or continue training one of yours.
                    </div>
                </div>

                <div class="col-md-6">
                    <label for="train-output" class="form-label">Output model name</label>
                    <input type="text" class="form-control" id="train-output" name="output_name"
                           value="{{ old('output_name', $defaults['output_name']) }}"
                           maxlength="64" required>
                    <div class="form-text">
                        Saved to <code>ml/models/&lt;name&gt;/</code>. Must not already exist.
                    </div>
                </div>

                <div class="col-md-3">
                    <label for="train-epochs" class="form-label">Epochs</label>
                    <input type="number" class="form-control" id="train-epochs" name="epochs"
                           min="1" max="200" value="{{ old('epochs', $defaults['epochs']) }}" required>
                    <div class="form-text">Passes over the training set.</div>
                </div>

                <div class="col-md-3">
                    <label for="train-batch" class="form-label">Batch size</label>
                    <input type="number" class="form-control" id="train-batch" name="batch_size"
                           min="1" max="128" value="{{ old('batch_size', $defaults['batch_size']) }}" required>
                    <div class="form-text">Lower this if the GPU runs out of memory.</div>
                </div>

                <div class="col-md-3">
                    <label for="train-lr" class="form-label">Learning rate</label>
                    <input type="number" class="form-control" id="train-lr" name="learning_rate"
                           step="0.00001" min="0.0000001" max="0.1"
                           value="{{ old('learning_rate', $defaults['learning_rate']) }}" required>
                    <div class="form-text">How big each weight update is. Leave as-is unless tuning.</div>
                </div>

                <div class="col-md-3">
                    <label for="train-label-length" class="form-label">Max label length</label>
                    <input type="number" class="form-control" id="train-label-length"
                           name="max_label_length" min="1" max="512"
                           value="{{ old('max_label_length', $defaults['max_label_length']) }}" required>
                    <div class="form-text">Characters kept per label; longer ones are truncated.</div>
                </div>

                <div class="col-md-3">
                    <label for="train-workers" class="form-label">Data loader workers</label>
                    <input type="number" class="form-control" id="train-workers" name="num_workers"
                           min="0" max="16" value="{{ old('num_workers', $defaults['num_workers']) }}" required>
                    <div class="form-text">Threads reading images. 0 is safest on Windows.</div>
                </div>

                <div class="col-md-3">
                    <label for="train-subset" class="form-label">Train subset</label>
                    <input type="number" class="form-control" id="train-subset" name="train_subset"
                           min="1" value="{{ old('train_subset', $defaults['train_subset']) }}"
                           placeholder="all">
                    <div class="form-text">Cap the training rows used. Blank uses all of them.</div>
                </div>

                <div class="col-md-3">
                    <label for="val-subset" class="form-label">Validation subset</label>
                    <input type="number" class="form-control" id="val-subset" name="val_subset"
                           min="1" value="{{ old('val_subset', $defaults['val_subset']) }}"
                           placeholder="all">
                    <div class="form-text">Same, for the validation split.</div>
                </div>
            </div>

            <div class="alert alert-warning mt-4 d-flex align-items-start">
                <i class="icon-base bx bx-error icon-md me-2"></i>
                <div>
                    <strong>This takes hours and holds the GPU.</strong>
                    Staff document scanning will be noticeably slower for the whole run,
                    and only one GPU job can run at a time. A checkpoint is about
                    1.3&nbsp;GB, so free disk is checked before the run starts.
                </div>
            </div>

            <button type="submit" class="btn btn-primary" @disabled($busy || ! $engine['reachable'])>
                <i class="icon-base bx bx-play icon-sm me-1"></i>
                Start fine-tuning
            </button>

            @if ($busy)
                <span class="text-muted small ms-2">
                    A job is already running. Wait for it, or cancel it above.
                </span>
            @elseif (! $engine['reachable'])
                <span class="text-muted small ms-2">Start the OCR service first.</span>
            @endif
        </form>
    @endif
</x-card>
