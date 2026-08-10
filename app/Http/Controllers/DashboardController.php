<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\DashboardAnalytics;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Role-aware landing page.
 *
 * Staff see their own work summary. Admin and Super Admin see the consolidated
 * oversight dashboard.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardAnalytics $analytics) {}

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $scope = null;
        $overview = null;

        if ($user->hasOversight()) {
            // Every CRM chart uses one stable 12-month window so the KPIs remain
            // immediately comparable without a filter form competing for space.
            $scope = $this->analytics->scope();
            $overview = $this->analytics->oversight($scope);
        }

        return view('dashboard', [
            'user' => $user,
            'scope' => $scope,
            'analytics' => $overview,
            'system' => null,
            'staffOverview' => $user->hasOversight() ? null : $this->analytics->staff($user),
            'recentActivity' => $user->hasOversight()
                ? collect()
                : AuditLog::where('user_id', $user->getKey())->latest('created_at')->limit(6)->get(),
        ]);
    }
}
