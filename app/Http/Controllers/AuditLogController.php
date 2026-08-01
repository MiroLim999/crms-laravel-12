<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Read-only view over the audit trail.
 *
 * There is deliberately no store, update, or destroy action here, and none may be
 * added: the log is append-only and the model itself throws on any attempt to
 * change or remove an entry. A rewritable log is not evidence of anything.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'actor' => ['nullable', 'integer', 'exists:users,id'],
            'action' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        $entries = AuditLog::query()
            // Every row renders the actor's role, so load it up front rather than
            // firing a query per row.
            ->with(['user.role'])
            ->when($filters['actor'] ?? null, fn (Builder $q, $id) => $q->where('user_id', $id))
            ->when($filters['action'] ?? null, fn (Builder $q, $action) => $q->where('action', $action))
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->where(
                'created_at',
                '>=',
                Carbon::parse($from)->startOfDay(),
            ))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->where(
                'created_at',
                '<=',
                Carbon::parse($to)->endOfDay(),
            ))
            ->when($filters['q'] ?? null, function (Builder $q, string $term) {
                $like = '%'.$term.'%';

                $q->where(fn (Builder $inner) => $inner->where('description', 'like', $like)
                    ->orWhere('actor_name', 'like', $like));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('audit.index', [
            'entries' => $entries,
            'filters' => $filters,
            'actions' => $this->recordedActions(),
            'actors' => $this->knownActors(),
        ]);
    }

    /**
     * Actions that actually appear in the log, so the filter never offers a value
     * that returns nothing.
     *
     * @return Collection<int, string>
     */
    private function recordedActions(): Collection
    {
        return AuditLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');
    }

    /**
     * @return Collection<int, User>
     */
    private function knownActors(): Collection
    {
        return User::query()
            ->whereHas('auditLogs')
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
