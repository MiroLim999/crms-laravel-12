{{--
    Engine status, always visible regardless of tab.

    The Run and Stop buttons replace typing the uvicorn command into a terminal.
    The command itself is still shown, so what the button does is never a mystery
    and can be run by hand if the process needs supervising properly.
--}}
<div class="card mb-4" id="engine-card">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex flex-wrap align-items-center gap-4">
                <span class="d-inline-flex align-items-center gap-2">
                    <span class="badge rounded-circle p-1 {{ $engine['reachable'] ? 'bg-success' : 'bg-danger' }}"
                          id="engine-dot"></span>
                    <strong id="engine-state">
                        {{ $engine['reachable'] ? 'OCR service online' : 'OCR service offline' }}
                    </strong>
                </span>

                <span class="text-muted small">
                    <i class="icon-base bx bx-chip icon-sm me-1"></i>
                    Device: <code id="engine-device">{{ $engine['device'] ?: 'not-loaded' }}</code>
                </span>

                <span class="text-muted small" id="engine-pid-wrap"
                      @class(['d-none' => $engine['pid'] === null])>
                    PID <code id="engine-pid">{{ $engine['pid'] }}</code>
                </span>

                {{--
                    Answering but not ours: started from a terminal, or left over from a
                    crash that lost the pidfile. Stop still works - it adopts whatever
                    holds the port - so this is information, not a warning.
                --}}
                @if ($engine['reachable'] && ! $engine['owned'])
                    <span class="badge bg-label-warning" id="engine-untracked">
                        <i class="icon-base bx bx-info-circle icon-sm me-1"></i>
                        Started outside the app
                        @if ($engine['listener_pid'])
                            (PID {{ $engine['listener_pid'] }})
                        @endif
                    </span>
                @endif

                <span class="text-muted small">
                    Review threshold: <strong>{{ $threshold }}%</strong> confidence
                </span>
            </div>

            {{--
                Both controls stay visible at all times and are disabled with a reason
                rather than hidden. Hiding them left one combination - answering but
                untracked - showing no buttons at all, which read as broken. A Super
                Admin should always be able to see what the controls are, even when one
                of them does not currently apply.
            --}}
            <div class="d-flex align-items-center gap-2">
                @if ($engine['managed'])
                    <form method="POST" action="{{ route('ocr.engine.start') }}" id="engine-start-form">
                        @csrf
                        <input type="hidden" name="tab" value="{{ $tab }}">
                        <button class="btn btn-success" type="submit" id="engine-start-btn"
                                @disabled($engine['reachable'])
                                title="{{ $engine['reachable']
                                    ? 'Already running - stop it first if you want to restart.'
                                    : 'Start the OCR service.' }}">
                            <i class="icon-base bx bx-play icon-sm me-1"></i> Run service
                        </button>
                    </form>

                    <button type="button" class="btn btn-outline-danger"
                            data-bs-toggle="modal" data-bs-target="#stopEngineModal"
                            id="engine-stop-btn"
                            @disabled(! $engine['stoppable'])
                            title="{{ $engine['stoppable']
                                ? ($engine['owned']
                                    ? 'Stop the service.'
                                    : 'Stop whatever is serving on port '.parse_url($engine['url'], PHP_URL_PORT).'.')
                                : 'Nothing is running to stop.' }}">
                        <i class="icon-base bx bx-stop icon-sm me-1"></i> Stop
                    </button>
                @else
                    <span class="badge bg-label-secondary">
                        Process control disabled (OCR_MANAGED=false)
                    </span>
                @endif
            </div>
        </div>

        {{-- The equivalent manual command, and why it might not have started. --}}
        <div class="mt-3 pt-3 border-top small">
            <span class="text-muted">Equivalent command:</span>
            <code>{{ $engine['command'] }}</code>
            <span class="text-muted ms-2">→ {{ $engine['url'] }}</span>

            @unless ($engine['reachable'])
                <div class="mt-2" id="engine-log-wrap">
                    @if ($engineLog !== [])
                        <button class="btn btn-sm btn-outline-secondary" type="button"
                                data-bs-toggle="collapse" data-bs-target="#engineLog">
                            <i class="icon-base bx bx-terminal icon-sm me-1"></i> Last start-up output
                        </button>
                        <div class="collapse mt-2" id="engineLog">
                            <pre class="bg-lighter rounded p-3 mb-0 small">{{ implode("\n", $engineLog) }}</pre>
                        </div>
                    @else
                        <span class="text-danger">{{ $engine['error'] }}</span>
                    @endif
                </div>
            @endunless

            @if ($engine['reachable'] && ! $engine['owned'])
                <div class="mt-2 text-muted">
                    This service was not started from here, so there is no clean shutdown to
                    perform — <em>Stop</em> will end whichever process holds the port. If it
                    is running in a terminal you are watching, that window will go quiet.
                </div>
            @endif
        </div>
    </div>
</div>

@unless ($engine['reachable'])
    <div class="alert alert-warning d-flex align-items-start" role="alert">
        <i class="icon-base bx bx-error icon-md me-2"></i>
        <div>
            <strong>The OCR service is not running.</strong>
            Staff cannot scan documents, and nothing on this page will work until it
            is started. Use <em>Run service</em> above.
        </div>
    </div>
@endunless
