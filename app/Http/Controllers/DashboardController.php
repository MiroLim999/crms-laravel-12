<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\DashboardAnalytics;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Role-aware landing page.
 *
 * Staff see their own work summary. Admin and Super Admin see the consolidated
 * oversight dashboard, where each panel has its own reporting period.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardAnalytics $analytics,
        private readonly \App\Services\Ocr\OcrModelPerformance $ocrPerformance,
    ) {}

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $scopes = null;
        $overview = null;
        $periodForms = null;
        $modelPerformance = null;

        if ($user->hasOversight()) {
            $problems = new MessageBag;
            $filters = [];
            $typed = [];

            foreach (DashboardAnalytics::PANELS as $panel) {
                [$filters[$panel], $typed[$panel]] = $this->periodFilters($request, $panel, $problems);
            }

            if ($problems->isNotEmpty()) {
                view()->share('errors', (new ViewErrorBag)->put('default', $problems));
            }

            $scopes = array_map(fn (array $panelFilters) => $this->analytics->scope($panelFilters), $filters);
            $overview = $this->analytics->oversight($scopes);
            $modelPerformance = $this->ocrPerformance->summary();

            // What each form shows: what was typed when it was rejected,
            // otherwise the period actually in use.
            $periodForms = [];
            foreach ($scopes as $panel => $scope) {
                $periodForms[$panel] = $typed[$panel] ?? [
                    'period' => $scope['period'],
                    'from' => $scope['period'] === 'custom' ? $scope['from'] : '',
                    'to' => $scope['period'] === 'custom' ? $scope['to'] : '',
                ];
            }
        }

        return view('dashboard', [
            'user' => $user,
            'periodOptions' => DashboardAnalytics::PERIODS,
            'periodForms' => $periodForms,
            'scopes' => $scopes,
            'analytics' => $overview,
            'modelPerformance' => $modelPerformance,
            'staffOverview' => $user->hasOversight() ? null : $this->analytics->staff($user),
            'recentActivity' => $user->hasOversight()
                ? collect()
                : AuditLog::where('user_id', $user->getKey())->latest('created_at')->limit(6)->get(),
        ]);
    }

    /**
     * One panel's reporting period, validated.
     *
     * It comes from the query string, so an edited URL can carry a bad one. Its
     * messages are collected under the panel's prefixed names and the default
     * period is used for that panel only, instead of the usual redirect back: a
     * typed URL has no referrer, so "back" could be another page. The panel's
     * form keeps what was typed, so it can be corrected.
     *
     * @return array{0: array<string, string>, 1: array{period: string, from: string, to: string}|null}
     */
    private function periodFilters(Request $request, string $panel, MessageBag $problems): array
    {
        $today = CarbonImmutable::now((string) config('crms.reporting_timezone', 'Asia/Manila'))->toDateString();
        $dates = ['exclude_unless:period,custom', 'required', 'date_format:Y-m-d', 'before_or_equal:'.$today];
        $input = [
            'period' => $request->query("{$panel}_period"),
            'from' => $request->query("{$panel}_from"),
            'to' => $request->query("{$panel}_to"),
        ];

        $validator = Validator::make($input, [
            'period' => ['nullable', 'string', Rule::in(array_keys(DashboardAnalytics::PERIODS))],
            'from' => [...$dates, 'after_or_equal:'.DashboardAnalytics::EARLIEST_DATE],
            'to' => [...$dates, 'after_or_equal:from'],
        ], [
            'period.*' => 'Choose one of the listed periods.',
            'from.required' => 'Choose the first day of the custom period.',
            'to.required' => 'Choose the last day of the custom period.',
            'from.date_format' => 'Enter the start date as a calendar date.',
            'to.date_format' => 'Enter the end date as a calendar date.',
            'from.before_or_equal' => 'The start date cannot be after today.',
            'to.before_or_equal' => 'The end date cannot be after today.',
            'from.after_or_equal' => 'The start date cannot be before 1 Jan 2000.',
            'to.after_or_equal' => 'The end date must be on or after the start date.',
        ]);

        if (! $validator->fails()) {
            return [$validator->validated(), null];
        }

        foreach ($validator->errors()->messages() as $field => $messages) {
            foreach ($messages as $message) {
                $problems->add("{$panel}_{$field}", $message);
            }
        }

        $typed = fn (string $field): string => is_string($input[$field]) ? $input[$field] : '';

        return [[], ['period' => $typed('period'), 'from' => $typed('from'), 'to' => $typed('to')]];
    }
}
