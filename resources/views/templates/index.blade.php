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
            </div>
        </div>
    </x-page-header>

    <div class="template-library-note" role="note">
        <i class="icon-base bx bx-info-circle" aria-hidden="true"></i>
        <div>
            <strong>Published layouts are the Staff defaults.</strong>
            <span>Drafts remain private until you publish them. Publishing a layout replaces the current one for that certificate type.</span>
        </div>
    </div>

    <div class="row g-4">
        @foreach ($documentTypes as $type)
            @php
                $group = $templates[$type->value] ?? collect();
                $published = $group->firstWhere('is_active', true);
                $draftCount = $group->where('is_active', false)->count();
            @endphp

            <div class="col-12">
                <section class="card template-library-card" aria-labelledby="template-type-{{ $type->value }}">
                    <header class="template-library-card__header">
                        <div class="template-library-card__identity">
                            <span class="template-library-card__icon" aria-hidden="true">
                                <i class="icon-base bx {{ $type->icon() }}"></i>
                            </span>
                            <div>
                                <h2 class="h5 mb-1" id="template-type-{{ $type->value }}">{{ $type->label() }}</h2>
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
                        </div>
                    </header>

                    @if ($group->isEmpty())
                        <div class="template-library-empty">
                            <span>No layouts have been created for this certificate type.</span>
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
                </section>
            </div>
        @endforeach
    </div>
@endsection
