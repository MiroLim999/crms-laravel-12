{{--
    Read-only service state. CRMS deliberately does not start or stop this OS
    process. The launch command remains visible in every state so a Super Admin can
    copy it before opening Command Prompt or PowerShell.
--}}
<section class="card ocr-engine-card {{ $engine['reachable'] ? 'is-online' : 'is-offline' }} mb-4"
         id="engine-card"
         aria-labelledby="engine-state">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="ocr-engine-icon" aria-hidden="true">
                    <i class="icon-base bx bx-plug"></i>
                    <span class="ocr-status-dot {{ $engine['reachable'] ? 'is-online' : 'is-offline' }}"
                          id="engine-dot"></span>
                </div>
                <div>
                    <h2 class="h6 mb-0" id="engine-state" aria-live="polite">
                        {{ $engine['reachable'] ? 'OCR service online' : 'OCR service offline' }}
                    </h2>
                    <p class="text-muted small mb-0 mt-1">
                        {{ $engine['reachable']
                            ? 'Scanning and model management are available.'
                            : 'Scanning and model changes are temporarily unavailable.' }}
                    </p>
                </div>
            </div>

            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="ocr-meta-pill" title="Inference device">
                    <i class="icon-base bx bx-chip icon-sm"></i>
                    <span id="engine-device">{{ $engine['device'] ?: 'not loaded' }}</span>
                </span>
                <span class="ocr-meta-pill" title="Local OCR endpoint">
                    <i class="icon-base bx bx-terminal icon-sm"></i>
                    <span class="font-monospace">{{ parse_url($engine['url'], PHP_URL_HOST) }}:{{ parse_url($engine['url'], PHP_URL_PORT) }}</span>
                </span>
            </div>
        </div>

        <div class="ocr-engine-recovery mt-3 pt-3 border-top">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <div>
                    <div class="fw-medium small">Launch command</div>
                    <div class="text-muted small">Run from the project root in Command Prompt or PowerShell.</div>
                </div>
                <button class="btn btn-sm btn-label-secondary" type="button" id="copy-engine-command">
                    <i class="icon-base bx bx-copy icon-sm me-1"></i>
                    <span>Copy command</span>
                </button>
            </div>
            <div class="ocr-command-line" id="engine-command">{{ $engine['command'] }}</div>
            @unless ($engine['reachable'])
                @if ($engine['error'])
                    <div class="text-danger small mt-2">
                        <i class="icon-base bx bx-error icon-sm me-1"></i>{{ $engine['error'] }}
                    </div>
                @endif
            @endunless
        </div>
    </div>
</section>
