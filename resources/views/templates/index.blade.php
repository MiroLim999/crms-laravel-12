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
                    <i class="icon-base bx bx-folder-plus icon-sm me-2" aria-hidden="true"></i>
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
                                <i class="icon-base bx {{ $type->icon() }}"></i>
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
                            @unless ($type->is_system)
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#renameDocumentType{{ $type->getKey() }}"
                                        aria-label="Rename {{ $type->label() }}">
                                    <i class="icon-base bx bx-edit-alt icon-sm" aria-hidden="true"></i>
                                    Rename
                                </button>
                            @endunless
                            <button type="button" class="template-library-toggle"
                                    data-template-layout-toggle data-target="{{ $collapseId }}"
                                    data-storage-key="template-layouts:{{ $type->key }}"
                                    aria-expanded="{{ $expanded ? 'true' : 'false' }}"
                                    aria-controls="{{ $collapseId }}">
                                <span>{{ $expanded ? 'Hide' : 'Show' }} layouts</span>
                                <i class="icon-base bx bx-chevron-down" aria-hidden="true"></i>
                            </button>
                        </div>
                    </header>

                    <div class="template-library-layouts" id="{{ $collapseId }}" @if (! $expanded) hidden @endif>
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
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            </div>
                        @endif
                    </div>
                </section>

                @unless ($type->is_system)
                    <div class="modal fade" id="renameDocumentType{{ $type->getKey() }}" tabindex="-1"
                         aria-labelledby="renameDocumentTypeLabel{{ $type->getKey() }}" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered modal-sm">
                            <div class="modal-content">
                                <form method="POST" action="{{ route('templates.document-types.update', $type) }}">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="document_type_form" value="rename-{{ $type->getKey() }}">
                                    <div class="modal-header">
                                        <div>
                                            <div class="text-uppercase text-primary small fw-semibold mb-1">Document type</div>
                                            <h2 class="modal-title h5" id="renameDocumentTypeLabel{{ $type->getKey() }}">Rename type</h2>
                                        </div>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <label for="renameDocumentTypeName{{ $type->getKey() }}" class="form-label">Name</label>
                                        <input type="text" maxlength="120" required
                                               class="form-control {{ old('document_type_form') === 'rename-'.$type->getKey() && $errors->has('document_type_name') ? 'is-invalid' : '' }}"
                                               id="renameDocumentTypeName{{ $type->getKey() }}"
                                               name="document_type_name"
                                               value="{{ old('document_type_form') === 'rename-'.$type->getKey() ? old('document_type_name') : $type->name }}">
                                        @if (old('document_type_form') === 'rename-'.$type->getKey())
                                            @error('document_type_name')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        @endif
                                        <div class="form-text">Layouts and saved records stay connected after renaming.</div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-primary">Save name</button>
                                    </div>
                                </form>
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
                        <div class="form-text">You can rename custom document types later.</div>
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

            const applyState = (expanded, remember = true) => {
                target.hidden = !expanded;
                toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                label.textContent = expanded ? 'Hide layouts' : 'Show layouts';

                if (remember) {
                    try {
                        window.localStorage.setItem(toggle.dataset.storageKey, expanded ? 'open' : 'closed');
                    } catch (_) {
                        // Storage can be unavailable in strict privacy modes; toggling still works.
                    }
                }
            };

            try {
                const saved = window.localStorage.getItem(toggle.dataset.storageKey);
                if (saved !== null) applyState(saved === 'open', false);
            } catch (_) {
                applyState(toggle.getAttribute('aria-expanded') === 'true', false);
            }

            toggle.addEventListener('click', () => {
                applyState(toggle.getAttribute('aria-expanded') !== 'true');
            });
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
