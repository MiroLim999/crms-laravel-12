@php($markerFields = $markerFields ?? $record->fields)

<x-card class="record-scan-card" bodyClass="p-0" title="Original scan"
        subtitle="Select a value to locate its source.">
    @if (!str_contains((string) $record->scan_mime, 'pdf'))
        <x-slot:actions>
            <div class="btn-group btn-group-sm" role="group" aria-label="Scan zoom controls">
                <button type="button" class="btn btn-outline-secondary" data-scan-zoom-out aria-label="Zoom out">−</button>
                <button type="button" class="btn btn-outline-secondary record-scan-zoom-label" data-scan-zoom-reset>100%</button>
                <button type="button" class="btn btn-outline-secondary" data-scan-zoom-in aria-label="Zoom in">+</button>
            </div>
        </x-slot:actions>

        <div class="record-scan-viewport" data-scan-viewport>
            <div class="record-scan-stage" data-scan-stage>
                <img src="{{ route('records.scan', $record) }}"
                     alt="Original scanned certificate" data-scan-image>
                <div class="record-scan-overlay" aria-label="Captured field positions">
                    @foreach ($markerFields as $field)
                        @if ($field->x !== null && $field->y !== null && $field->width !== null && $field->height !== null)
                            <button type="button" class="btn btn-outline-warning record-scan-marker"
                                    data-scan-marker="{{ $field->getKey() }}"
                                    aria-label="{{ $field->name }}"
                                    style="--field-x: {{ (float) $field->x * 100 }}%; --field-y: {{ (float) $field->y * 100 }}%; --field-w: {{ (float) $field->width * 100 }}%; --field-h: {{ (float) $field->height * 100 }}%;"></button>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>
        <div class="record-scan-card__footer">
            <span><i class="icon-base bx bx-target-lock" aria-hidden="true"></i> Selected field</span>
            <a href="{{ route('records.scan', $record) }}" target="_blank" rel="noopener">Open full size</a>
        </div>
    @else
        <div class="p-3">
            <a href="{{ route('records.scan', $record) }}" target="_blank" rel="noopener"
               class="btn btn-outline-secondary w-100">
                <i class="icon-base bx bx-file icon-sm me-1"></i> Open scanned PDF
            </a>
        </div>
    @endif
</x-card>
