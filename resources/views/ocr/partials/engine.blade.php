{{--
    Service status - read only.

    There are no Start and Stop buttons. Spawning and killing an OS process from a
    web request is a lot of blast radius for a convenience, and a service killed
    from a browser tab mid-scan fails in ways nobody can see. The command is shown
    instead, so it can be copied and run where its output is visible.
--}}
<div class="card mb-4" id="engine-card">
    <div class="card-body">
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

            <span class="text-muted small">
                Review threshold: <strong>{{ rtrim(rtrim(number_format($threshold, 2), '0'), '.') }}%</strong>
                confidence
            </span>
        </div>

        <div class="mt-3 pt-3 border-top small">
            <span class="text-muted">Run the service with:</span>
            <code>{{ $engine['command'] }}</code>
            <span class="text-muted ms-2">&rarr; {{ $engine['url'] }}</span>

            @unless ($engine['reachable'])
                <div class="mt-2 text-danger">{{ $engine['error'] }}</div>
            @endunless
        </div>
    </div>
</div>

@unless ($engine['reachable'])
    <div class="alert alert-warning d-flex align-items-start" role="alert">
        <i class="icon-base bx bx-error icon-md me-2"></i>
        <div>
            <strong>The OCR service is not running.</strong>
            Staff cannot read handwriting, and models cannot be installed, renamed, or
            deleted until it answers. Run the command above from the repo root; this
            page notices on its own once it is up.
        </div>
    </div>
@endunless
