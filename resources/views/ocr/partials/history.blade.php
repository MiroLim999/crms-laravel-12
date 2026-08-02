{{--
    Run history, shared by the Fine-tuning and Evaluation tabs.

    Mirrored from the service into ml_jobs, which is the durable record: the service
    only keeps a live job in memory, so once a run ends this table is all there is.

    Rows expand to show the log tail. Bootstrap's collapse does not work inside a
    <table> - it measures height mid-layout, gets 0, and snaps shut - so the panel is
    animated by hand in ocr-workspace.js, the same approach as audit/index.
--}}
<x-card title="Run history" subtitle="Every fine-tuning and evaluation run, most recent first."
        class="mt-4">
    @if ($history->isEmpty())
        <x-empty-state icon="bx-history" title="No runs yet"
                       message="Fine-tuning and evaluation runs are recorded here." />
    @else
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th style="width: 2.5rem;"></th>
                        <th>Run</th>
                        <th>Dataset</th>
                        <th>Result</th>
                        <th>Duration</th>
                        <th>Started by</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($history as $job)
                        <tr>
                            <td>
                                <button type="button"
                                        class="btn btn-sm btn-icon btn-text-secondary rounded-pill"
                                        data-role="expand-run"
                                        data-target="run-{{ $job->getKey() }}"
                                        aria-expanded="false"
                                        aria-controls="run-{{ $job->getKey() }}"
                                        aria-label="Show log for run {{ $job->job_id }}">
                                    <i class="icon-base bx bx-chevron-right"></i>
                                </button>
                            </td>
                            <td>
                                <div class="fw-medium">
                                    <i class="icon-base bx {{ $job->type->icon() }} icon-sm me-1"></i>
                                    {{ $job->type->label() }}
                                </div>
                                <code class="small text-muted">{{ $job->job_id }}</code>
                                <span class="badge {{ $job->status->badgeClass() }} ms-1">
                                    {{ $job->status->label() }}
                                </span>
                            </td>
                            <td class="small">
                                {{ $job->dataset ?? '—' }}
                                @if ($job->output_name)
                                    <div class="text-muted">→ {{ $job->output_name }}</div>
                                @elseif ($job->model_key)
                                    <div class="text-muted">{{ $job->model_key }}</div>
                                @endif
                            </td>
                            <td class="small">
                                @if ($job->error)
                                    <span class="text-danger">{{ Str::limit($job->error, 80) }}</span>
                                @else
                                    {{ $job->headlineMetric() ?? '—' }}
                                @endif
                            </td>
                            <td class="small">{{ $job->duration() ?? '—' }}</td>
                            <td class="small">
                                {{ $job->trigger?->name ?? '—' }}
                                <div class="text-muted">{{ $job->created_at->diffForHumans() }}</div>
                            </td>
                        </tr>
                        <tr class="run-detail" id="run-{{ $job->getKey() }}">
                            <td colspan="6" class="p-0 border-0">
                                {{-- max-height is animated by JS; overflow hidden keeps it closed. --}}
                                <div class="run-detail-inner" style="max-height: 0; overflow: hidden;">
                                    <div class="p-3 bg-lighter">
                                        <div class="row g-3 small">
                                            <div class="col-md-6">
                                                <strong class="d-block mb-1">Configuration</strong>
                                                <pre class="mb-0 small">{{ json_encode($job->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                            </div>
                                            <div class="col-md-6">
                                                <strong class="d-block mb-1">Metrics</strong>
                                                <pre class="mb-0 small">{{ $job->metrics ? json_encode($job->metrics, JSON_PRETTY_PRINT) : 'none recorded' }}</pre>
                                            </div>
                                        </div>

                                        @if ($job->log)
                                            <strong class="d-block mt-3 mb-1 small">Log</strong>
                                            <pre class="mb-0 small" style="max-height: 220px; overflow-y: auto;">{{ implode("\n", $job->log) }}</pre>
                                        @endif
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-card>
