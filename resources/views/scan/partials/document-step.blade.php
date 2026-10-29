{{--
    Step 1 of a new document: the type and the file, in one card. The type list
    and the drop area share the card's inner edges.

    Each type in the list is a link that opens the workspace for it, so nothing
    is switched in place: before a file is chosen there is nothing to lose.
    resources/js/document-type-picker.js adds the filter and the keyboard and
    drop handling.

    Expects:
      $documentTypes  DocumentTypeDefinition list (active types, with records_count)
      $templates      the active template per type key, or null
      $currentType    the type being scanned, or null on New Document
      $health         OcrClient::health()
      $activeModel    OcrModel|null
--}}
@php
    $online = $health['reachable'];
    $modelName = $activeModel ? ($activeModel->label ?: $activeModel->key) : null;
    // Types that can be scanned first; the rest stay listed, disabled.
    $types = $documentTypes->sortBy(fn ($type) => $templates[$type->value] ? 0 : 1);
    $hasUnavailable = $documentTypes->contains(fn ($type) => $templates[$type->value] === null);
    $searchable = $documentTypes->count() > 6;

    $meta = function ($type) use ($templates): string {
        $template = $templates[$type->value];
        if ($template === null) {
            return 'No published template';
        }

        $parts = [];
        // The layout's own name only when it says something the type does not.
        if (strcasecmp(trim($template->name), trim($type->label())) !== 0) {
            $parts[] = $template->name;
        }
        if ($type->records_count > 0) {
            $parts[] = number_format($type->records_count).' '.str('record')->plural($type->records_count);
        }

        return implode(' · ', $parts);
    };
@endphp

@once
    @push('styles')
        {{-- Choosing another type loads the page again: cross-fade it instead of cutting.
             "@@" is Blade's escape for a literal "@". --}}
        <style>
            @@view-transition { navigation: auto; }
            ::view-transition-old(root), ::view-transition-new(root) { animation-duration: .2s; }
            @@media (prefers-reduced-motion: reduce) { @@view-transition { navigation: none; } }
        </style>
    @endpush
    @push('scripts')
        @vite('resources/js/document-type-picker.js')
    @endpush
@endonce

<section id="step-upload" class="document-step-panel">
    <div class="document-upload-wrap">
        @unless ($online)
            <div class="alert alert-danger document-upload-alert" role="alert">
                <i class="icon-base bx bx-plug" aria-hidden="true"></i>
                <div>
                    <strong>OCR service unavailable.</strong>
                    You can align the fields, but nothing is read until it is back.
                    @if ($health['error'])
                        <span class="d-block small">{{ $health['error'] }}</span>
                    @endif
                </div>
            </div>
        @endunless

        @if (! $activeModel)
            <div class="alert alert-warning document-upload-alert" role="alert">
                <i class="icon-base bx bx-error" aria-hidden="true"></i>
                <div>No OCR model is active. A Super Admin needs to activate one before scans are read.</div>
            </div>
        @endif

        <div class="card document-upload-card">
            <div class="document-type-bar">
                <div class="document-type-bar__head">
                    <span class="document-type-bar__label" id="documentTypeLabel">Document type</span>
                    <span class="scan-type-status" role="status">
                        <span class="scan-type-status__item">
                            <span class="scan-type-status__dot {{ $online ? 'is-online' : 'is-offline' }}" aria-hidden="true"></span>
                            OCR {{ $online ? 'online' : 'offline' }}
                        </span>
                        @if ($modelName)
                            <span class="scan-type-status__item text-muted" title="Active OCR model">{{ $modelName }}</span>
                        @endif
                    </span>
                </div>

                <div class="dropdown document-type-select" data-document-type-select>
                    <button type="button" id="documentTypeToggle"
                            class="btn btn-outline-secondary dropdown-toggle hide-arrow document-type-select__toggle"
                            data-bs-toggle="dropdown" data-bs-offset="0,6" aria-expanded="false"
                            aria-labelledby="documentTypeLabel documentTypeToggle">
                        @if ($currentType)
                            <span class="document-type-option__icon" aria-hidden="true">
                                <i class="icon-base bx {{ $currentType->icon() }}"></i>
                            </span>
                            <span class="document-type-select__value">{{ $currentType->label() }}</span>
                        @else
                            <span class="document-type-option__icon is-empty" aria-hidden="true">
                                <i class="icon-base bx bx-file"></i>
                            </span>
                            <span class="document-type-select__value is-placeholder">Select a document type</span>
                        @endif
                        <i class="icon-base bx bx-chevron-down document-type-select__chevron" aria-hidden="true"></i>
                    </button>

                    <div class="dropdown-menu document-type-select__menu">
                        @if ($searchable)
                            <div class="document-type-select__search">
                                <i class="icon-base bx bx-search" aria-hidden="true"></i>
                                <input type="search" class="form-control form-control-sm" placeholder="Find a document type"
                                       aria-label="Find a document type" autocomplete="off" data-document-type-filter>
                            </div>
                        @endif

                        <div class="document-type-select__list">
                            @forelse ($types as $type)
                                @php
                                    $selected = $currentType?->value === $type->value;
                                    $detail = $meta($type);
                                @endphp
                                @if ($templates[$type->value])
                                    <a href="{{ route('documents.workspace', ['type' => $type->value]) }}"
                                       class="dropdown-item document-type-option {{ $selected ? 'is-selected' : '' }}"
                                       data-document-type-option @if ($selected) aria-current="true" @endif>
                                        <span class="document-type-option__icon" aria-hidden="true">
                                            <i class="icon-base bx {{ $type->icon() }}"></i>
                                        </span>
                                        <span class="document-type-option__copy">
                                            <span class="document-type-option__name">{{ $type->label() }}</span>
                                            @if ($detail !== '')
                                                <span class="document-type-option__meta">{{ $detail }}</span>
                                            @endif
                                        </span>
                                        @if ($selected)
                                            <i class="icon-base bx bx-check document-type-option__check" aria-hidden="true"></i>
                                        @endif
                                    </a>
                                @else
                                    <span class="dropdown-item document-type-option disabled" aria-disabled="true"
                                          data-document-type-option>
                                        <span class="document-type-option__icon" aria-hidden="true">
                                            <i class="icon-base bx {{ $type->icon() }}"></i>
                                        </span>
                                        <span class="document-type-option__copy">
                                            <span class="document-type-option__name">{{ $type->label() }}</span>
                                            <span class="document-type-option__meta">{{ $detail }}</span>
                                        </span>
                                    </span>
                                @endif
                            @empty
                                <div class="document-type-select__empty">No document types yet.</div>
                            @endforelse

                            <div class="document-type-select__empty d-none" data-document-type-empty>No matching document type.</div>
                        </div>

                        @can('templates.manage')
                            @if ($hasUnavailable || $documentTypes->isEmpty())
                                <div class="dropdown-divider"></div>
                                <a href="{{ route('templates.index') }}" class="dropdown-item document-type-select__manage">
                                    <i class="icon-base bx bx-layout" aria-hidden="true"></i>
                                    Manage templates
                                </a>
                            @endif
                        @endcan
                    </div>
                </div>
            </div>

            @if ($currentType)
                <label for="scanFile" class="document-dropzone" id="documentDropzone" tabindex="0">
                    <span class="document-dropzone__icon" aria-hidden="true">
                        <i class="icon-base bx bx-cloud-upload"></i>
                    </span>
                    <span class="document-dropzone__title">Drop the scan here</span>
                    <span class="btn btn-primary document-dropzone__button">Choose file</span>
                    <span class="document-dropzone__formats" aria-label="Accepted formats">
                        <span>PDF</span><span>PNG</span><span>JPG</span><span>WEBP</span><span>BMP</span>
                    </span>
                </label>
                <input type="file" id="scanFile" class="visually-hidden"
                       accept="application/pdf,image/png,image/jpeg,image/webp,image/bmp">
            @else
                {{-- Not a file input yet: clicking or dropping here opens the type list. --}}
                <div class="document-dropzone is-disabled" id="documentDropzone" aria-disabled="true" data-requires-type>
                    <span class="document-dropzone__icon" aria-hidden="true">
                        <i class="icon-base bx bx-cloud-upload"></i>
                    </span>
                    <span class="document-dropzone__title">Choose a document type first</span>
                    <span class="document-dropzone__formats" aria-label="Accepted formats">
                        <span>PDF</span><span>PNG</span><span>JPG</span><span>WEBP</span><span>BMP</span>
                    </span>
                </div>
            @endif

            <p class="document-upload-note">Up to 20 MB · Only the first page of a PDF is read</p>
        </div>
    </div>
</section>
