<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\DashboardAnalytics;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Role-aware landing page.
 *
 * Staff see their own work summary. Admin and Super Admin see the consolidated
 * oversight dashboard; Super Admin also receives OCR and template governance.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardAnalytics $analytics) {}

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $scope = null;
        $overview = null;
        $system = null;
        $filterOptions = null;

        if ($user->hasOversight()) {
            $validated = $request->validate([
                'period' => ['nullable', Rule::in(array_keys(DashboardAnalytics::PERIODS))],
                'from' => ['nullable', 'required_if:period,custom', 'date'],
                'to' => ['nullable', 'required_if:period,custom', 'date', 'after_or_equal:from'],
                'document_type' => ['nullable', 'string', 'exists:document_types,key'],
                // Historical record keys remain meaningful after a model is renamed
                // or deleted, so this intentionally does not require a registry row.
                'ocr_model' => ['nullable', 'string', 'max:255'],
            ]);

            $scope = $this->analytics->scope($validated);
            $overview = $this->analytics->oversight($scope);
            $filterOptions = $this->analytics->filterOptions();

            if ($user->isSuperAdmin()) {
                $system = $this->analytics->system($scope);
            }
        }

        return view('dashboard', [
            'user' => $user,
            'periodOptions' => DashboardAnalytics::PERIODS,
            'scope' => $scope,
            'analytics' => $overview,
            'system' => $system,
            'filterOptions' => $filterOptions,
            'staffOverview' => $user->hasOversight() ? null : $this->analytics->staff($user),
            'recentActivity' => $user->hasOversight()
                ? AuditLog::latest('created_at')->limit(6)->get()
                : AuditLog::where('user_id', $user->getKey())->latest('created_at')->limit(6)->get(),
        ]);
    }
}
