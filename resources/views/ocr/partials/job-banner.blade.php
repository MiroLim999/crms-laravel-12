{{--
    The running GPU job, shown above the tabs because it affects all of them.

    Progress is polled, not pushed: a run reports at epoch and step boundaries, so a
    couple of seconds of latency costs nothing and there is no socket to maintain.
--}}
<div id="job-banner" @class(['card', 'mb-4', 'border-primary', 'd-none' => $activeJob === null])
     @if ($activeJob) data-job-id="{{ $activeJob->getKey() }}" @endif>
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
            <div>
                <h6 class="mb-1">
                    <span class="spinner-border spinner-border-sm text-primary me-2" role="status"
                          id="job-spinner" aria-hidden="true"></span>
                    <span id="job-title">{{ $activeJob?->type->label() ?? 'GPU job' }} in progress</span>
                    <span class="badge {{ $activeJob?->status->badgeClass() ?? 'bg-label-info' }} ms-2"
                          id="job-status">{{ $activeJob?->status->label() ?? 'Running' }}</span>
                </h6>
                <small class="text-muted">
                    <span id="job-stage">{{ $activeJob?->stage() }}</span>
                    <span id="job-detail">
                        @if ($activeJob?->dataset)
                            · dataset <code>{{ $activeJob->dataset }}</code>
                        @endif
                        @if ($activeJob?->output_name)
                            · output <code>{{ $activeJob->output_name }}</code>
                        @endif
                    </span>
                    · elapsed <span id="job-duration">{{ $activeJob?->duration() }}</span>
                </small>
            </div>

            @if ($activeJob)
                <button type="button" class="btn btn-sm btn-outline-danger"
                        data-bs-toggle="modal" data-bs-target="#cancelJobModal"
                        data-job-id="{{ $activeJob->getKey() }}"
                        data-job-type="{{ $activeJob->type->label() }}"
                        data-job-output="{{ $activeJob->output_name }}">
                    <i class="icon-base bx bx-x icon-sm me-1"></i> Cancel run
                </button>
            @endif
        </div>

        <div class="progress mb-2" style="height: 8px;">
            <div class="progress-bar progress-bar-striped progress-bar-animated" id="job-bar"
                 role="progressbar"
                 style="width: {{ $activeJob?->percent() ?? 0 }}%"
                 aria-valuenow="{{ $activeJob?->percent() ?? 0 }}"
                 aria-valuemin="0" aria-valuemax="100"
                 aria-label="Job progress"></div>
        </div>

        <div class="d-flex flex-wrap gap-3 small text-muted" id="job-numbers">
            <span>Epoch <strong id="job-epoch">{{ data_get($activeJob?->progress, 'epoch', 0) }}</strong>
                / <span id="job-total-epochs">{{ data_get($activeJob?->progress, 'total_epochs', '?') }}</span></span>
            <span>Step <strong id="job-step">{{ data_get($activeJob?->progress, 'step', 0) }}</strong>
                / <span id="job-total-steps">{{ data_get($activeJob?->progress, 'total_steps', '?') }}</span></span>
            <span>Loss <strong id="job-loss">{{ data_get($activeJob?->progress, 'loss', '—') }}</strong></span>
            <span><strong id="job-percent">{{ $activeJob?->percent() ?? 0 }}</strong>%</span>
        </div>

        <div class="mt-3">
            <button class="btn btn-sm btn-outline-secondary" type="button"
                    data-bs-toggle="collapse" data-bs-target="#jobLog" aria-expanded="false">
                <i class="icon-base bx bx-terminal icon-sm me-1"></i> Log
            </button>
            <div class="collapse mt-2" id="jobLog">
                <pre class="bg-lighter rounded p-3 mb-0 small" id="job-log"
                     style="max-height: 260px; overflow-y: auto;">{{ implode("\n", $activeJob?->log ?? []) }}</pre>
            </div>
        </div>

        <div class="alert alert-warning mt-3 mb-0 py-2 small d-flex align-items-center">
            <i class="icon-base bx bx-error icon-sm me-2"></i>
            <div>
                Training, evaluation, and Staff scanning share one GPU. Document scanning
                will be slower until this run finishes.
            </div>
        </div>
    </div>
</div>
