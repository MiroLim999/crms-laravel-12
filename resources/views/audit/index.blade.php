@extends('layouts.app')

@section('title', 'Audit Log')

@section('content')
    <x-page-header title="Audit Log"
                   subtitle="Every state change, with actor, target, and before/after values." />

    {{-- Stated plainly on the page: there is no edit or delete control anywhere
         here, and the model rejects both. --}}
    <div class="alert alert-info d-flex align-items-center" role="alert">
        <i class="icon-base bx bx-lock-alt icon-md me-2"></i>
        <div>
            This trail is append-only. Entries cannot be edited or removed by anyone,
            including a Super Admin.
        </div>
    </div>

    <x-card>
        <form method="GET" action="{{ route('audit.index') }}" class="row g-3">
            <div class="col-md-4">
                <label for="q" class="form-label">Search</label>
                <input type="search" id="q" name="q" value="{{ $filters['q'] ?? '' }}"
                       class="form-control" placeholder="Description or actor name">
            </div>
            <div class="col-md-4">
                <label for="actor" class="form-label">Actor</label>
                <select id="actor" name="actor" class="form-select">
                    <option value="">Anyone</option>
                    @foreach ($actors as $actor)
                        <option value="{{ $actor->id }}"
                                @selected(($filters['actor'] ?? null) == $actor->id)>{{ $actor->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label for="action" class="form-label">Action</label>
                <select id="action" name="action" class="form-select">
                    <option value="">All actions</option>
                    @foreach ($actions as $action)
                        <option value="{{ $action }}"
                                @selected(($filters['action'] ?? null) === $action)>{{ $action }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label for="from" class="form-label">From</label>
                <input type="date" id="from" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control">
            </div>
            <div class="col-md-3">
                <label for="to" class="form-label">To</label>
                <input type="date" id="to" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control">
            </div>
            <div class="col-md-6 d-flex align-items-end gap-2">
                <button class="btn btn-outline-secondary" type="submit">
                    <i class="icon-base bx bx-filter-alt icon-sm me-1"></i> Filter
                </button>
                <a href="{{ route('audit.index') }}" class="btn btn-text-secondary">Reset</a>
            </div>
        </form>
    </x-card>

    <x-card class="mt-4" title="Entries"
            :subtitle="$entries->total() . ' entr' . ($entries->total() === 1 ? 'y' : 'ies') . ' recorded'">
        @if ($entries->isEmpty())
            <x-empty-state icon="bx-history" title="No entries match"
                           message="Adjust the filters, or widen the date range." />
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Actor</th>
                            <th>Action</th>
                            <th>Target</th>
                            <th>IP</th>
                            <th class="text-end">Changes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            @php
                                // The denormalised actor_name is preferred: it still
                                // reads correctly after an account is renamed or removed.
                                $actorName = $entry->actor_name ?? $entry->user?->name ?? 'System';
                                $actorRole = $entry->actor_role
                                    ? \App\Enums\RoleSlug::tryFrom($entry->actor_role)?->label()
                                    : null;
                                $target = $entry->auditable_type ? class_basename($entry->auditable_type) : null;
                                $diffKeys = array_keys(array_merge($entry->old_values ?? [], $entry->new_values ?? []));
                                $diffId = 'audit-diff-'.$entry->getKey();
                            @endphp

                            <tr>
                                <td class="text-nowrap">
                                    {{ $entry->created_at?->format('j M Y H:i') }}
                                    <div><small class="text-muted">{{ $entry->created_at?->diffForHumans() }}</small></div>
                                </td>
                                <td>
                                    <div class="fw-medium">{{ $actorName }}</div>
                                    @if ($actorRole)
                                        <span class="badge bg-label-secondary">{{ $actorRole }}</span>
                                    @endif
                                </td>
                                <td><code>{{ $entry->action }}</code></td>
                                <td>
                                    @if ($target)
                                        {{ $target }}
                                        <small class="text-muted">#{{ $entry->auditable_id }}</small>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                    @if ($entry->description)
                                        <div><small class="text-muted">{{ $entry->description }}</small></div>
                                    @endif
                                </td>
                                <td class="text-muted">{{ $entry->ip_address ?? '—' }}</td>
                                <td class="text-end">
                                    @if ($diffKeys !== [])
                                        <button class="btn btn-sm btn-outline-secondary" type="button"
                                                data-bs-toggle="collapse" data-bs-target="#{{ $diffId }}"
                                                aria-expanded="false" aria-controls="{{ $diffId }}">
                                            <i class="icon-base bx bx-git-compare icon-sm me-1"></i>
                                            {{ count($diffKeys) }}
                                        </button>
                                    @else
                                        <span class="text-muted small">—</span>
                                    @endif
                                </td>
                            </tr>

                            @if ($diffKeys !== [])
                                <tr>
                                    <td colspan="6" class="p-0 border-0">
                                        <div class="collapse" id="{{ $diffId }}">
                                            <div class="p-3 bg-lighter">
                                                <table class="table table-sm mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th>Field</th>
                                                            <th>Before</th>
                                                            <th>After</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach ($diffKeys as $key)
                                                            @php
                                                                $before = data_get($entry->old_values, $key);
                                                                $after = data_get($entry->new_values, $key);
                                                                $render = fn ($value) => match (true) {
                                                                    $value === null => '—',
                                                                    is_bool($value) => $value ? 'true' : 'false',
                                                                    is_scalar($value) => (string) $value,
                                                                    default => json_encode($value),
                                                                };
                                                            @endphp
                                                            <tr>
                                                                <td class="text-muted">{{ $key }}</td>
                                                                <td><del class="text-danger">{{ $render($before) }}</del></td>
                                                                <td><span class="text-success">{{ $render($after) }}</span></td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{ $entries->links() }}
        @endif
    </x-card>
@endsection
