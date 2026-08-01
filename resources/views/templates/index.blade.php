@extends('layouts.app')

@section('title', 'Document Templates')

@section('content')
    <x-page-header title="Document Templates"
                   subtitle="Define which fields are captured from each certificate type.">
        <div class="dropdown">
            <button class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown">
                <i class="icon-base bx bx-plus icon-sm me-1"></i> New Template
            </button>
            <div class="dropdown-menu dropdown-menu-end">
                @foreach ($documentTypes as $type)
                    <a class="dropdown-item" href="{{ route('templates.create', ['type' => $type->value]) }}">
                        <i class="icon-base bx {{ $type->icon() }} icon-sm me-2"></i> {{ $type->label() }}
                    </a>
                @endforeach
            </div>
        </div>
    </x-page-header>

    @foreach ($documentTypes as $type)
        @php $group = $templates[$type->value] ?? collect(); @endphp

        <x-card class="mb-4" :title="$type->label()"
                :subtitle="$group->firstWhere('is_active', true) ? null : 'No active template — Staff cannot scan this type yet.'">
            @if ($group->isEmpty())
                <x-empty-state :icon="$type->icon()" title="No templates"
                               message="Create one to start capturing {{ $type->shortLabel() }} certificates.">
                    <a href="{{ route('templates.create', ['type' => $type->value]) }}" class="btn btn-sm btn-primary">
                        Create {{ $type->shortLabel() }} template
                    </a>
                </x-empty-state>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Template</th>
                                <th>Fields</th>
                                <th>Records</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($group as $template)
                                <tr>
                                    <td>
                                        <div class="fw-medium">{{ $template->name }}</div>
                                        <small class="text-muted">
                                            by {{ $template->creator?->name ?? 'System' }}
                                            · {{ $template->created_at->diffForHumans() }}
                                        </small>
                                    </td>
                                    <td>{{ $template->fields_count }}</td>
                                    <td>{{ $template->records_count }}</td>
                                    <td>
                                        @if ($template->is_active)
                                            <span class="badge bg-label-success">Active</span>
                                        @else
                                            <span class="badge bg-label-secondary">Inactive</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <a href="{{ route('templates.edit', $template) }}"
                                           class="btn btn-sm btn-outline-secondary">Edit</a>

                                        @unless ($template->is_active)
                                            <form method="POST" action="{{ route('templates.activate', $template) }}"
                                                  class="d-inline">
                                                @csrf
                                                <button class="btn btn-sm btn-outline-primary" type="submit">
                                                    Activate
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
        </x-card>
    @endforeach
@endsection
