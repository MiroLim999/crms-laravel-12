<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Role-aware landing page.
 *
 * Staff see their work queue, Admin and Super Admin see oversight figures. The
 * full analytics dashboard is a separate, gated feature.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('dashboard', [
            'user' => $user,
            'oversight' => $user->hasOversight() ? $this->oversightStats() : null,
            'recentActivity' => $user->hasOversight()
                ? AuditLog::latest('created_at')->limit(8)->get()
                : AuditLog::where('user_id', $user->getKey())->latest('created_at')->limit(8)->get(),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function oversightStats(): array
    {
        return [
            'active_users' => User::where('is_active', true)->count(),
            'pending_first_login' => User::where('must_change_password', true)->count(),
            'audit_entries' => AuditLog::count(),
        ];
    }
}
