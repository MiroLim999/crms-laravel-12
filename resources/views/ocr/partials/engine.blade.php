{{--
    Read-only OCR service state, shown as a pill in the performance card header.
    CRMS deliberately does not start or stop this OS process. The page poller keeps
    the pill current and reloads the page when reachability changes.
--}}
@php
    $engineHint = $engine['reachable']
        ? 'Scanning and model management are available.'
        : trim('Scanning and model changes are temporarily unavailable. '.($engine['error'] ?? ''));
@endphp

<span class="ocr-service-status {{ $engine['reachable'] ? 'is-online' : 'is-offline' }}"
      id="engine-status"
      role="status"
      title="{{ $engineHint }}">
    <span class="ocr-service-dot" aria-hidden="true"></span>
    <span id="engine-state">{{ $engine['reachable'] ? 'OCR service online' : 'OCR service offline' }}</span>
</span>
