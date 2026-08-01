@extends('layouts.app')

@section('title', 'Audit Log')

@section('content')
    <x-page-header title="Audit Log"
                   subtitle="Every state change, with actor, target, and before/after values." />

    <div class="alert alert-info d-flex align-items-center mb-4" role="alert">
        <i class="icon-base bx bx-lock-alt icon-md me-2"></i>
        <div>
            This trail is append-only. Entries cannot be edited or removed by anyone,
            including a Super Admin.
        </div>
    </div>

    {{-- Filters --}}
    <x-card class="mb-4">
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
                                @selected(($filters['actor'] ?? null) == $actor->id)>
                            {{ $actor->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label for="action" class="form-label">Action</label>
                <select id="action" name="action" class="form-select">
                    <option value="">All actions</option>
                    @foreach ($actions as $action)
                        <option value="{{ $action }}"
                                @selected(($filters['action'] ?? null) === $action)>
                            {{ $action }}
                        </option>
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

    {{-- Log table --}}
    <x-card title="Entries"
            :subtitle="$entries->total() . ' entr' . ($entries->total() === 1 ? 'y' : 'ies') . ' recorded'">
        @if ($entries->isEmpty())
            <x-empty-state icon="bx-history" title="No entries match"
                           message="Adjust the filters, or widen the date range." />
        @else
            <div class="table-responsive">
                {{--
                    Note: table-hover is intentionally omitted here. The expand rows
                    must not light up on hover, and a plain table reads fine for a log.
                --}}
                <table class="table align-middle">
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
                                $actorName = $entry->actor_name ?? $entry->user?->name ?? 'System';
                                $actorRole = $entry->actor_role
                                    ? \App\Enums\RoleSlug::tryFrom($entry->actor_role)?->label()
                                    : null;
                                $target   = $entry->auditable_type ? class_basename($entry->auditable_type) : null;
                                $diffKeys = array_keys(array_merge($entry->old_values ?? [], $entry->new_values ?? []));
                                $diffId   = 'audit-diff-' . $entry->getKey();

                                $renderValue = fn ($value) => match (true) {
                                    $value === null   => null,
                                    is_bool($value)   => $value ? 'true' : 'false',
                                    is_scalar($value) => (string) $value,
                                    default           => json_encode($value, JSON_UNESCAPED_UNICODE),
                                };
                            @endphp

                            {{-- Data row --}}
                            <tr class="audit-data-row" id="row-{{ $entry->getKey() }}">
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
                                <td><code class="text-body">{{ $entry->action }}</code></td>
                                <td>
                                    @if ($target)
                                        <span class="fw-medium">{{ $target }}</span>
                                        <small class="text-muted">#{{ $entry->auditable_id }}</small>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                    @if ($entry->description)
                                        <div><small class="text-muted">{{ $entry->description }}</small></div>
                                    @endif
                                </td>
                                <td class="text-muted small">{{ $entry->ip_address ?? '—' }}</td>
                                <td class="text-end">
                                    @if ($diffKeys !== [])
                                        {{--
                                            Uses a plain JS toggle instead of data-bs-toggle="collapse"
                                            because Bootstrap's height animation misfires inside table
                                            rows, causing the panel to flash open then immediately close.
                                        --}}
                                        <button type="button"
                                                class="btn btn-sm btn-outline-secondary audit-diff-toggle"
                                                data-diff-target="{{ $diffId }}"
                                                title="View {{ count($diffKeys) }} changed field(s)">
                                            <i class="icon-base bx bx-git-compare icon-sm me-1"></i>
                                            {{ count($diffKeys) }}
                                        </button>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>

                            @if ($diffKeys !== [])
                                <tr class="audit-expand-row" id="{{ $diffId }}-row">
                                    <td class="p-0" colspan="6" style="border-top:0">
                                        {{-- Slide wrapper. max-height is animated by JS. --}}
                                        <div class="audit-diff-panel" id="{{ $diffId }}" style="max-height:0;overflow:hidden;">
                                            <div class="audit-diff-inner mx-1 mb-2 rounded-2 border">
                                                {{-- Panel header --}}
                                                <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom"
                                                     style="background:rgba(105,108,255,.06)">
                                                    <i class="icon-base bx bx-git-compare icon-sm text-primary"></i>
                                                    <span class="small fw-semibold text-primary">
                                                        {{ count($diffKeys) }} changed {{ Str::plural('field', count($diffKeys)) }}
                                                    </span>
                                                    <span class="ms-auto small text-muted">
                                                        {{ $entry->action }}
                                                    </span>
                                                </div>

                                                {{-- Diff table --}}
                                                <div class="table-responsive">
                                                    <table class="table table-sm mb-0" style="border-collapse:separate">
                                                        <thead>
                                                            <tr style="background:rgba(0,0,0,.02)">
                                                                <th class="ps-3 text-uppercase fw-semibold small text-muted border-0"
                                                                    style="width:26%;letter-spacing:.04em">Field</th>
                                                                <th class="text-uppercase fw-semibold small border-0"
                                                                    style="width:37%;letter-spacing:.04em;color:#ea5455">Before</th>
                                                                <th class="text-uppercase fw-semibold small border-0"
                                                                    style="width:37%;letter-spacing:.04em;color:#28c76f">After</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            @foreach ($diffKeys as $key)
                                                                @php
                                                                    $before  = $renderValue(data_get($entry->old_values, $key));
                                                                    $after   = $renderValue(data_get($entry->new_values, $key));
                                                                    $changed = $before !== $after;
                                                                @endphp
                                                                <tr class="{{ $loop->even ? '' : '' }}"
                                                                    style="{{ $loop->last ? '' : 'border-bottom:1px solid rgba(0,0,0,.05)' }}">
                                                                    <td class="ps-3 align-top py-2">
                                                                        <span class="badge bg-label-secondary font-monospace fw-normal"
                                                                              style="font-size:.72rem">{{ $key }}</span>
                                                                    </td>
                                                                    <td class="align-top py-2 pe-3">
                                                                        @if ($before !== null)
                                                                            <span class="small {{ $changed ? 'text-danger' : 'text-muted' }}">
                                                                                {{ $before === '[redacted]' ? '••••••••' : $before }}
                                                                            </span>
                                                                        @else
                                                                            <span class="text-muted small">—</span>
                                                                        @endif
                                                                    </td>
                                                                    <td class="align-top py-2 pe-3">
                                                                        @if ($after !== null)
                                                                            <span class="small {{ $changed ? 'text-success fw-medium' : 'text-muted' }}">
                                                                                {{ $after === '[redacted]' ? '••••••••' : $after }}
                                                                            </span>
                                                                        @else
                                                                            <span class="text-muted small">—</span>
                                                                        @endif
                                                                    </td>
                                                                </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                </div>
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

@push('scripts')
<script>
    // Smooth slide for audit diff panels.
    //
    // Bootstrap collapse animates height but misfires inside <table> because tables
    // force a layout pass mid-animation, making Bootstrap measure 0 and snap shut.
    //
    // Fix: the expand <tr> is always in the DOM (no display:none). The panel inside
    // animates max-height from 0 to scrollHeight. CSS transition handles the easing,
    // JS only sets the value.
    document.addEventListener('DOMContentLoaded', function () {
        // Wire the CSS transition on each panel after render so it doesn't flash
        // on page load (adding it via CSS would run before max-height:0 applies).
        document.querySelectorAll('.audit-diff-panel').forEach(function (panel) {
            panel.style.transition = 'max-height 0.28s cubic-bezier(0.4, 0, 0.2, 1)';
        });

        document.querySelectorAll('.audit-diff-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var panelId = btn.getAttribute('data-diff-target');
                var panel   = document.getElementById(panelId);
                if (!panel) return;

                var isOpen = panel.style.maxHeight !== '0px' && panel.style.maxHeight !== '';

                if (isOpen) {
                    // Slide up: set an explicit px value first (required for the
                    // transition to have a start point), then drop to 0.
                    panel.style.maxHeight = panel.scrollHeight + 'px';
                    // Force a reflow so the browser registers the explicit height
                    // before we set the closing value.
                    panel.getBoundingClientRect();
                    panel.style.maxHeight = '0px';
                    btn.classList.remove('active');
                    btn.setAttribute('aria-expanded', 'false');
                } else {
                    // Slide down: animate to the natural height, then clear the
                    // inline value so the panel can reflow if content changes.
                    panel.style.maxHeight = panel.scrollHeight + 'px';
                    panel.addEventListener('transitionend', function onEnd() {
                        panel.removeEventListener('transitionend', onEnd);
                        // Only clear if still open (user may have clicked again
                        // before the transition finished).
                        if (panel.style.maxHeight !== '0px') {
                            panel.style.maxHeight = 'none';
                        }
                    });
                    btn.classList.add('active');
                    btn.setAttribute('aria-expanded', 'true');
                }
            });
        });
    });
</script>
@endpush
