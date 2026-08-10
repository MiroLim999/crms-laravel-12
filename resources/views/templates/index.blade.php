@extends('layouts.app')

@section('title', 'Template Builder')

@section('content')
    <x-page-header title="Template Builder"
                   subtitle="Design and publish the field layouts Staff receive when they scan a document.">
        <div class="dropdown">
            <button class="btn btn-primary dropdown-toggle" type="button"
                    data-bs-toggle="dropdown" aria-expanded="false">
                <i class="icon-base bx bx-plus icon-sm me-1" aria-hidden="true"></i>
                New layout
            </button>
            <div class="dropdown-menu dropdown-menu-end">
                @foreach ($documentTypes as $type)
                    <a class="dropdown-item" href="{{ route('templates.create', ['type' => $type->value]) }}">
                        <i class="icon-base bx {{ $type->icon() }} icon-sm me-2" aria-hidden="true"></i>
                        {{ $type->label() }}
                    </a>
                @endforeach
                <div class="dropdown-divider"></div>
                <button class="dropdown-item" type="button" data-bs-toggle="modal"
                        data-bs-target="#newDocumentTypeModal">
                    <i class="icon-base bx bx-folder icon-sm me-2" aria-hidden="true"></i>
                    New document type&hellip;
                </button>
            </div>
        </div>
    </x-page-header>

    <div class="template-library-note" role="note">
        <i class="icon-base bx bx-info-circle" aria-hidden="true"></i>
        <div>
            <strong>Published layouts are the Staff defaults.</strong>
            <span>Drafts remain private until you publish them. Publishing a layout replaces the current one for that document type.</span>
        </div>
    </div>

    <div class="row g-4">
        @foreach ($documentTypes as $type)
            @php
                $group = $templates[$type->getKey()] ?? collect();
                $published = $group->firstWhere('is_active', true);
                $draftCount = $group->where('is_active', false)->count();
                $expanded = request('open') === $type->key || (! request()->filled('open') && $loop->first);
                $collapseId = 'template-layouts-' . $type->getKey();
            @endphp

            <div class="col-12">
                <section class="card template-library-card" aria-labelledby="template-type-{{ $type->getKey() }}">
                    <header class="template-library-card__header">
                        <div class="template-library-card__identity">
                            <span class="template-library-card__icon" aria-hidden="true">
                                <i class="icon-base bx {{ $type->icon() }} icon-md"></i>
                            </span>
                            <div>
                                <h2 class="h5 mb-1" id="template-type-{{ $type->getKey() }}">{{ $type->label() }}</h2>
                                @if ($published)
                                    <p class="mb-0 text-muted small">
                                        Staff currently use <strong class="text-body">{{ $published->name }}</strong>.
                                    </p>
                                @else
                                    <p class="mb-0 text-danger small">No layout is published. Staff cannot scan this type yet.</p>
                                @endif
                            </div>
                        </div>

                        <div class="template-library-card__summary">
                            <span><strong>{{ $group->count() }}</strong> {{ Str::plural('layout', $group->count()) }}</span>
                            <span><strong>{{ $draftCount }}</strong> {{ Str::plural('draft', $draftCount) }}</span>
                            <a href="{{ route('templates.create', ['type' => $type->value]) }}"
                               class="btn btn-sm btn-outline-primary">
                                <i class="icon-base bx bx-plus icon-sm me-1" aria-hidden="true"></i>
                                New layout
                            </a>
                            <div class="dropdown template-library-manage">
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        data-bs-toggle="dropdown" aria-expanded="false"
                                        aria-label="Manage {{ $type->label() }}">
                                    <i class="icon-base bx bx-dots-vertical-rounded icon-sm" aria-hidden="true"></i>
                                    Manage
                                </button>
                                <div class="dropdown-menu dropdown-menu-end">
                                    <button type="button" class="dropdown-item"
                                            data-bs-toggle="modal"
                                            data-bs-target="#renameDocumentType{{ $type->getKey() }}">
                                        <i class="icon-base bx bx-edit-alt icon-sm me-2" aria-hidden="true"></i>
                                        Rename
                                    </button>
                                    @unless ($type->is_system)
                                        <div class="dropdown-divider"></div>
                                        <button type="button" class="dropdown-item text-danger"
                                                data-bs-toggle="modal"
                                                data-bs-target="#deleteDocumentType{{ $type->getKey() }}">
                                            <i class="icon-base bx bx-trash icon-sm me-2" aria-hidden="true"></i>
                                            Delete document type
                                        </button>
                                    @endunless
                                </div>
                            </div>
                            <button type="button"
                                    class="btn btn-sm btn-outline-secondary audit-diff-toggle template-library-toggle {{ $expanded ? 'active' : '' }}"
                                    data-template-layout-toggle data-target="{{ $collapseId }}"
                                    data-storage-key="template-layouts:{{ $type->key }}"
                                    aria-expanded="{{ $expanded ? 'true' : 'false' }}"
                                    aria-controls="{{ $collapseId }}"
                                    title="{{ $expanded ? 'Hide' : 'Show' }} {{ $group->count() }} {{ Str::plural('layout', $group->count()) }}">
                                <i class="icon-base bx bx-layout icon-sm me-1" aria-hidden="true"></i>
                                <span>{{ $expanded ? 'Hide' : 'Show' }} layouts</span>
                            </button>
                        </div>
                    </header>

                    <div class="template-library-layouts" id="{{ $collapseId }}"
                         style="max-height: {{ $expanded ? 'none' : '0px' }}; overflow: hidden;"
                         aria-hidden="{{ $expanded ? 'false' : 'true' }}">
                        @if ($group->isEmpty())
                            <div class="template-library-empty">
                                <span>No layouts have been created for this document type.</span>
                                <a href="{{ route('templates.create', ['type' => $type->value]) }}">
                                    Create the first layout
                                    <i class="icon-base bx bx-chevron-right" aria-hidden="true"></i>
                                </a>
                            </div>
                        @else
                            <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Layout</th>
                                        <th>Fields</th>
                                        <th>Records</th>
                                        <th>Status</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($group as $layout)
                                        <tr>
                                            <td>
                                                <div class="fw-medium text-heading">{{ $layout->name }}</div>
                                                <small class="text-muted">
                                                    {{ $layout->creator?->name ?? 'System' }}
                                                    &middot; Updated {{ $layout->updated_at->diffForHumans() }}
                                                </small>
                                                <small class="d-block text-muted mt-1">
                                                    {{ $layout->paper_size->label() }}
                                                    ({{ $layout->paperDimensionsLabel() }})
                                                    &middot; {{ $layout->orientation->label() }}
                                                </small>
                                                @if ($layout->sample_path)
                                                    <a class="d-inline-flex align-items-center gap-1 small mt-1"
                                                       href="{{ route('templates.sample', $layout) }}" target="_blank"
                                                       rel="noopener" title="Open stored sample">
                                                        <i class="icon-base bx bx-file icon-sm" aria-hidden="true"></i>
                                                        {{ $layout->sample_original_name }}
                                                    </a>
                                                @else
                                                    <small class="d-block text-muted mt-1">No sample stored</small>
                                                @endif
                                            </td>
                                            <td>{{ $layout->fields_count }}</td>
                                            <td>{{ $layout->records_count }}</td>
                                            <td>
                                                @if ($layout->is_active)
                                                    <span class="badge bg-label-success">
                                                        <i class="icon-base bx bx-check icon-sm me-1" aria-hidden="true"></i>
                                                        Published
                                                    </span>
                                                @else
                                                    <span class="badge bg-label-secondary">Draft</span>
                                                @endif
                                            </td>
                                            <td class="text-end text-nowrap">
                                                <a href="{{ route('templates.edit', $layout) }}"
                                                   class="btn btn-sm btn-outline-secondary">
                                                    Edit layout
                                                </a>

                                                @unless ($layout->is_active)
                                                    <form method="POST" action="{{ route('templates.activate', $layout) }}"
                                                          class="d-inline">
                                                        @csrf
                                                        <button class="btn btn-sm btn-outline-primary" type="submit">
                                                            Publish for Staff
                                                        </button>
                                                    </form>
                                                @endunless

                                                <button type="button" class="btn btn-sm btn-outline-danger"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#deleteLayout{{ $layout->getKey() }}">
                                                    <i class="icon-base bx bx-trash icon-sm me-1" aria-hidden="true"></i>
                                                    Delete
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            </div>
                        @endif
                    </div>
                </section>

                @foreach ($group as $layout)
                    <div class="modal fade" id="deleteLayout{{ $layout->getKey() }}" tabindex="-1"
                         aria-labelledby="deleteLayoutLabel{{ $layout->getKey() }}" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered modal-sm">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <div>
                                        <div class="text-uppercase text-danger small fw-semibold mb-1">Template Builder</div>
                                        <h2 class="modal-title h5" id="deleteLayoutLabel{{ $layout->getKey() }}">
                                            Delete {{ $layout->name }}?
                                        </h2>
                                    </div>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    @if ($layout->is_active)
                                        <div class="alert alert-warning py-2 px-3 small mb-3">
                                            This is the published Staff layout. Staff cannot start new scans for this document type until another layout is published.
                                        </div>
                                    @endif

                                    @if ($layout->records_count > 0)
                                        <p class="mb-2">
                                            {{ $layout->records_count }} existing {{ Str::plural('record', $layout->records_count) }} will remain saved, but will no longer link back to this layout.
                                        </p>
                                    @else
                                        <p class="mb-2">This layout has not been used by any saved records.</p>
                                    @endif

                                    @if ($layout->sample_path)
                                        <p class="mb-2">Its stored sample document will also be deleted.</p>
                                    @endif
                                    <p class="mb-0 text-muted small">This action cannot be undone.</p>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <form method="POST" action="{{ route('templates.destroy', $layout) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-danger">
                                            <i class="icon-base bx bx-trash icon-sm me-1" aria-hidden="true"></i>
                                            Delete layout
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach

                <div class="modal fade" id="renameDocumentType{{ $type->getKey() }}" tabindex="-1"
                     aria-labelledby="renameDocumentTypeLabel{{ $type->getKey() }}" aria-hidden="true"
                     data-document-type-rename-modal>
                    <div class="modal-dialog modal-dialog-centered modal-sm">
                        <div class="modal-content">
                            <form method="POST" action="{{ route('templates.document-types.update', $type) }}"
                                  data-document-type-rename-form>
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="document_type_form" value="rename-{{ $type->getKey() }}">
                                <div class="modal-header">
                                    <div>
                                        <div class="text-uppercase text-primary small fw-semibold mb-1">Document type</div>
                                        <h2 class="modal-title h5" id="renameDocumentTypeLabel{{ $type->getKey() }}">
                                            Rename {{ $type->label() }}
                                        </h2>
                                    </div>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <label for="renameDocumentTypeName{{ $type->getKey() }}" class="form-label">Display name</label>
                                    <input type="text" maxlength="120" required
                                           class="form-control {{ old('document_type_form') === 'rename-'.$type->getKey() && $errors->has('document_type_name') ? 'is-invalid' : '' }}"
                                           id="renameDocumentTypeName{{ $type->getKey() }}"
                                           name="document_type_name"
                                           value="{{ old('document_type_form') === 'rename-'.$type->getKey() ? old('document_type_name') : $type->name }}"
                                           data-original-name="{{ $type->name }}"
                                           data-document-type-rename-input>
                                    @if (old('document_type_form') === 'rename-'.$type->getKey())
                                        @error('document_type_name')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    @endif
                                    <div class="template-document-type-rename-note">
                                        <i class="icon-base bx bx-info-circle icon-xs" aria-hidden="true"></i>
                                        <span>This changes the label shown on this card, its layouts, and the editor. Existing records and layouts stay connected.</span>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary" data-document-type-rename-submit>Save name</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                @unless ($type->is_system)
                    <div class="modal fade" id="deleteDocumentType{{ $type->getKey() }}" tabindex="-1"
                         aria-labelledby="deleteDocumentTypeLabel{{ $type->getKey() }}" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered modal-sm">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <div>
                                        <div class="text-uppercase text-danger small fw-semibold mb-1">Document type</div>
                                        <h2 class="modal-title h5" id="deleteDocumentTypeLabel{{ $type->getKey() }}">
                                            Delete {{ $type->label() }}?
                                        </h2>
                                    </div>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    @if ($type->records_count > 0)
                                        <p class="mb-2">This document type is used by {{ $type->records_count }} saved {{ Str::plural('record', $type->records_count) }}.</p>
                                        <p class="mb-0 text-muted small">It cannot be deleted because its identity is needed for record history.</p>
                                    @else
                                        <p class="mb-2">This removes the document type and its {{ $type->templates_count }} {{ Str::plural('layout', $type->templates_count) }}.</p>
                                        <p class="mb-0 text-muted small">This action cannot be undone.</p>
                                    @endif
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                        {{ $type->records_count > 0 ? 'Close' : 'Cancel' }}
                                    </button>
                                    @if ($type->records_count === 0)
                                        <form method="POST" action="{{ route('templates.document-types.destroy', $type) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-danger">
                                                <i class="icon-base bx bx-trash icon-sm me-1" aria-hidden="true"></i>
                                                Delete document type
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @endunless
            </div>
        @endforeach
    </div>

    <div class="modal fade" id="newDocumentTypeModal" tabindex="-1"
         aria-labelledby="newDocumentTypeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <form method="POST" action="{{ route('templates.document-types.store') }}">
                    @csrf
                    <input type="hidden" name="document_type_form" value="create">
                    <div class="modal-header">
                        <div>
                            <div class="text-uppercase text-primary small fw-semibold mb-1">Template Builder</div>
                            <h2 class="modal-title h5" id="newDocumentTypeModalLabel">New document type</h2>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <label for="newDocumentTypeName" class="form-label">Document type name</label>
                        <input type="text" id="newDocumentTypeName" name="document_type_name"
                               value="{{ old('document_type_name') }}" maxlength="120" required
                               class="form-control {{ old('document_type_form', 'create') === 'create' && $errors->has('document_type_name') ? 'is-invalid' : '' }}"
                               placeholder="Example: Residency Certificate">
                        @if (old('document_type_form', 'create') === 'create')
                            @error('document_type_name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        @endif
                        <div class="form-text">Document type display names can be changed later.</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create and build layout</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('[data-template-layout-toggle]').forEach((toggle) => {
            const label = toggle.querySelector('span');
            const target = document.getElementById(toggle.dataset.target);
            if (!label || !target) return;

            const updateState = (expanded, remember = true) => {
                toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                target.setAttribute('aria-hidden', expanded ? 'false' : 'true');
                label.textContent = expanded ? 'Hide layouts' : 'Show layouts';
                toggle.classList.toggle('active', expanded);
                toggle.title = `${expanded ? 'Hide' : 'Show'} layouts`;

                if (remember) {
                    try {
                        window.localStorage.setItem(toggle.dataset.storageKey, expanded ? 'open' : 'closed');
                    } catch (_) {
                        // Storage can be unavailable in strict privacy modes; toggling still works.
                    }
                }
            };

            let expanded = toggle.getAttribute('aria-expanded') === 'true';

            try {
                const saved = window.localStorage.getItem(toggle.dataset.storageKey);
                if (saved !== null) expanded = saved === 'open';
            } catch (_) {
                // Use the server-rendered state when storage is unavailable.
            }

            target.style.maxHeight = expanded ? 'none' : '0px';
            updateState(expanded, false);

            window.requestAnimationFrame(() => {
                target.style.transition = 'max-height 0.28s cubic-bezier(0.4, 0, 0.2, 1)';
            });

            toggle.addEventListener('click', () => {
                const isExpanded = toggle.getAttribute('aria-expanded') === 'true';

                if (isExpanded) {
                    target.style.maxHeight = `${target.scrollHeight}px`;
                    target.getBoundingClientRect();
                    target.style.maxHeight = '0px';
                    updateState(false);
                    return;
                }

                target.style.maxHeight = `${target.scrollHeight}px`;
                updateState(true);
                target.addEventListener('transitionend', function onTransitionEnd(event) {
                    if (event.propertyName !== 'max-height') return;
                    target.removeEventListener('transitionend', onTransitionEnd);
                    if (toggle.getAttribute('aria-expanded') === 'true') {
                        target.style.maxHeight = 'none';
                    }
                });
            });
        });

        document.querySelectorAll('[data-document-type-rename-modal]').forEach((modal) => {
            const form = modal.querySelector('[data-document-type-rename-form]');
            const input = modal.querySelector('[data-document-type-rename-input]');
            const submit = modal.querySelector('[data-document-type-rename-submit]');
            if (!(form instanceof HTMLFormElement)
                || !(input instanceof HTMLInputElement)
                || !(submit instanceof HTMLButtonElement)) return;

            const originalName = input.dataset.originalName?.trim() ?? '';
            const refreshSubmit = () => {
                const name = input.value.trim();
                submit.disabled = name === '' || name === originalName;
            };

            input.addEventListener('input', () => {
                input.classList.remove('is-invalid');
                refreshSubmit();
            });
            modal.addEventListener('shown.bs.modal', () => {
                refreshSubmit();
                input.focus();
                input.select();
            });
            form.addEventListener('submit', (event) => {
                input.value = input.value.trim();
                if (input.value === '' || input.value === originalName) {
                    event.preventDefault();
                    refreshSubmit();
                    input.focus();
                    return;
                }
                submit.disabled = true;
                submit.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Saving&hellip;';
            });

            refreshSubmit();
        });

        @if ($errors->has('document_type_name'))
            window.addEventListener('load', () => {
                const intent = @json(old('document_type_form', 'create'));
                const modalId = intent.startsWith('rename-')
                    ? `renameDocumentType${intent.replace('rename-', '')}`
                    : 'newDocumentTypeModal';
                const modal = document.getElementById(modalId);
                if (modal) window.bootstrap?.Modal.getOrCreateInstance(modal).show();
            });
        @endif
    </script>
@endpush
