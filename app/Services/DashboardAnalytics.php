<?php

namespace App\Services;

use App\Enums\ChangeRequestStatus;
use App\Enums\RecordStatus;
use App\Enums\RoleSlug;
use App\Models\AuditLog;
use App\Models\ChangeRequest;
use App\Models\CivilRecord;
use App\Models\DocumentTemplate;
use App\Models\DocumentTypeDefinition;
use App\Models\OcrModel;
use App\Models\OcrSetting;
use App\Models\RecordField;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only metrics used by the role-aware landing page.
 *
 * The dashboard deliberately reports digitisation activity, not civil-event
 * incidence. A record's submitted_at says when CRMS archived it, not when a birth,
 * marriage, or death happened.
 */
class DashboardAnalytics
{
    public const DEFAULT_PERIOD = '30';

    /** @var array<string, string> */
    public const PERIODS = [
        '30' => 'Last 30 days',
        '90' => 'Last 90 days',
        '365' => 'Last 12 months',
        'custom' => 'Custom dates',
    ];

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function scope(array $filters): array
    {
        $timezone = (string) config('crms.reporting_timezone', 'Asia/Manila');
        $period = (string) ($filters['period'] ?? self::DEFAULT_PERIOD);
        $today = CarbonImmutable::now($timezone);

        if ($period === 'custom') {
            $start = CarbonImmutable::parse((string) $filters['from'], $timezone)->startOfDay();
            $end = CarbonImmutable::parse((string) $filters['to'], $timezone)->endOfDay();
        } else {
            $days = (int) $period;
            $end = $today->endOfDay();
            $start = $today->subDays($days - 1)->startOfDay();
        }

        $duration = $start->diffInSeconds($end) + 1;
        $previousEnd = $start->subSecond();
        $previousStart = $previousEnd->subSeconds($duration - 1);

        return [
            'period' => $period,
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'start' => $start,
            'end' => $end,
            'db_start' => $start->utc(),
            'db_end' => $end->utc(),
            'previous_db_start' => $previousStart->utc(),
            'previous_db_end' => $previousEnd->utc(),
            'document_type' => $filters['document_type'] ?? null,
            'ocr_model' => $filters['ocr_model'] ?? null,
            'timezone' => $timezone,
            'label' => $start->format('j M Y').' – '.$end->format('j M Y'),
        ];
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    public function oversight(array $scope): array
    {
        $records = $this->records($scope);
        $quality = $this->ocrQuality($scope);

        return [
            'headline' => $this->headline($scope, $quality),
            'trend' => $this->recordTrend($scope),
            'by_document_type' => $this->recordsByDocumentType($records),
            'ocr_quality' => $quality,
            'throughput' => $this->submissionsByAccount($records),
            'governance' => $this->governanceTrend($scope),
            'recent_records' => (clone $records)
                ->with(['documentTypeDefinition', 'submitter'])
                ->latest('submitted_at')
                ->limit(5)
                ->get(),
            'accounts' => $this->accountHealth(),
        ];
    }

    /**
     * Super Admin-only technical governance data. No live OCR request happens
     * here; reachability is fetched by the browser after the page is usable.
     *
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    public function system(array $scope): array
    {
        $types = DocumentTypeDefinition::query()
            ->withCount([
                'templates as active_templates_count' => fn (Builder $query) => $query->where('is_active', true),
            ])
            ->orderByDesc('is_system')
            ->orderBy('id')
            ->get();

        $activeModel = OcrModel::active();

        return [
            'document_types' => $types,
            'ready_types' => $types->where('active_templates_count', 1)->count(),
            'total_types' => $types->count(),
            'template_issues' => $types->where('active_templates_count', '!=', 1)->count(),
            'draft_templates' => DocumentTemplate::where('is_active', false)->count(),
            'template_performance' => $this->templatePerformance($scope),
            'active_model' => $activeModel,
            'threshold' => OcrSetting::threshold(),
            'staff_may_choose_model' => OcrSetting::staffMayChooseModel(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function staff(User $user): array
    {
        $timezone = (string) config('crms.reporting_timezone', 'Asia/Manila');
        $monthStart = CarbonImmutable::now($timezone)->startOfMonth()->utc();

        return [
            'submitted_this_month' => CivilRecord::query()
                ->where('submitted_by', $user->getKey())
                ->where('status', RecordStatus::Submitted->value)
                ->where('submitted_at', '>=', $monthStart)
                ->count(),
            'pending_change_requests' => ChangeRequest::query()
                ->where('requested_by', $user->getKey())
                ->where('status', ChangeRequestStatus::Pending->value)
                ->count(),
            'recent_records' => CivilRecord::query()
                ->where('submitted_by', $user->getKey())
                ->where('status', RecordStatus::Submitted->value)
                ->with('documentTypeDefinition')
                ->latest('submitted_at')
                ->limit(5)
                ->get(),
        ];
    }

    /**
     * @return array{document_types: Collection<int, DocumentTypeDefinition>, ocr_models: Collection<int, array{key: string, label: string}>}
     */
    public function filterOptions(): array
    {
        $registered = OcrModel::query()->pluck('label', 'key');

        $models = CivilRecord::query()
            ->whereNotNull('ocr_model_key')
            ->distinct()
            ->orderBy('ocr_model_key')
            ->pluck('ocr_model_key')
            ->merge($registered->keys())
            ->unique()
            ->sort()
            ->values()
            ->map(fn (string $key) => [
                'key' => $key,
                'label' => (string) ($registered->get($key) ?: $key),
            ]);

        return [
            'document_types' => DocumentTypeDefinition::ordered(),
            'ocr_models' => $models,
        ];
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  array<string, int|float|null>  $quality
     * @return array<string, mixed>
     */
    private function headline(array $scope, array $quality): array
    {
        $current = $this->records($scope)->count();
        $previousScope = [
            ...$scope,
            'db_start' => $scope['previous_db_start'],
            'db_end' => $scope['previous_db_end'],
        ];
        $previous = $this->records($previousScope)->count();

        $pending = ChangeRequest::query()
            ->where('status', ChangeRequestStatus::Pending->value)
            ->when($scope['document_type'], fn (Builder $query, string $key) => $query->whereHas(
                'record.documentTypeDefinition',
                fn (Builder $typeQuery) => $typeQuery->where('key', $key),
            ))
            ->when($scope['ocr_model'], fn (Builder $query, string $key) => $query->whereHas(
                'record',
                fn (Builder $recordQuery) => $recordQuery->where('ocr_model_key', $key),
            ));

        return [
            'records' => $current,
            'previous_records' => $previous,
            'records_delta' => $previous > 0
                ? round(($current - $previous) / $previous * 100, 1)
                : null,
            'pending_requests' => (clone $pending)->count(),
            'oldest_pending_at' => (clone $pending)->min('created_at'),
            'threshold_pass_rate' => $quality['threshold_pass_rate'],
            'confidence_fields' => $quality['confidence_fields'],
        ];
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function records(array $scope): Builder
    {
        return CivilRecord::query()
            ->where('status', RecordStatus::Submitted->value)
            ->whereBetween('submitted_at', [$scope['db_start'], $scope['db_end']])
            ->when($scope['document_type'], fn (Builder $query, string $key) => $query->whereHas(
                'documentTypeDefinition',
                fn (Builder $typeQuery) => $typeQuery->where('key', $key),
            ))
            ->when($scope['ocr_model'], fn (Builder $query, string $key) => $query->where('ocr_model_key', $key));
    }

    /**
     * @param  Builder<CivilRecord>  $records
     * @return Collection<int, array{type: DocumentTypeDefinition, total: int, share: float}>
     */
    private function recordsByDocumentType(Builder $records): Collection
    {
        $counts = (clone $records)
            ->select('document_type_id')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('document_type_id')
            ->pluck('total', 'document_type_id');

        $total = (int) $counts->sum();

        return DocumentTypeDefinition::ordered()
            ->map(fn (DocumentTypeDefinition $type) => [
                'type' => $type,
                'total' => (int) ($counts[$type->getKey()] ?? 0),
                'share' => $total > 0
                    ? round((int) ($counts[$type->getKey()] ?? 0) / $total * 100, 1)
                    : 0.0,
            ])
            ->sortByDesc('total')
            ->values();
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array{mode: string, labels: list<string>, totals: list<int>}
     */
    private function recordTrend(array $scope): array
    {
        $daily = $scope['start']->diffInDays($scope['end']) <= 90;
        $format = $daily ? 'Y-m-d' : 'Y-m';
        $labelFormat = $daily ? 'j M' : 'M Y';
        $cursor = $scope['start']->startOf($daily ? 'day' : 'month');
        $last = $scope['end']->startOf($daily ? 'day' : 'month');
        $step = $daily ? 'addDay' : 'addMonth';
        $buckets = [];

        while ($cursor <= $last) {
            $buckets[$cursor->format($format)] = [
                'label' => $cursor->format($labelFormat),
                'total' => 0,
            ];
            $cursor = $cursor->{$step}();
        }

        foreach ($this->records($scope)->select(['id', 'submitted_at'])->cursor() as $record) {
            $key = $record->submitted_at->setTimezone($scope['timezone'])->format($format);
            if (isset($buckets[$key])) {
                $buckets[$key]['total']++;
            }
        }

        return [
            'mode' => $daily ? 'day' : 'month',
            'labels' => array_column($buckets, 'label'),
            'totals' => array_column($buckets, 'total'),
        ];
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array<string, int|float|null>
     */
    private function ocrQuality(array $scope): array
    {
        $threshold = OcrSetting::threshold();
        $recordIds = $this->records($scope)->select('records.id');

        $row = RecordField::query()
            ->whereIn('record_id', $recordIds)
            ->selectRaw('COUNT(ocr_confidence) as confidence_fields')
            ->selectRaw('AVG(ocr_confidence) as average_confidence')
            ->selectRaw('COALESCE(SUM(CASE WHEN ocr_confidence < ? THEN 1 ELSE 0 END), 0) as below_threshold', [$threshold])
            ->selectRaw('COUNT(ocr_text) as comparable_fields')
            ->selectRaw("COALESCE(SUM(CASE WHEN ocr_text IS NOT NULL AND TRIM(COALESCE(ocr_text, '')) <> TRIM(COALESCE(verified_value, '')) THEN 1 ELSE 0 END), 0) as corrected_fields")
            ->first();

        $confidenceFields = (int) ($row?->confidence_fields ?? 0);
        $below = (int) ($row?->below_threshold ?? 0);
        $comparable = (int) ($row?->comparable_fields ?? 0);
        $corrected = (int) ($row?->corrected_fields ?? 0);

        return [
            'threshold' => $threshold,
            'confidence_fields' => $confidenceFields,
            'average_confidence' => $row?->average_confidence === null
                ? null
                : round((float) $row->average_confidence, 1),
            'below_threshold' => $below,
            'threshold_pass_rate' => $confidenceFields > 0
                ? round(($confidenceFields - $below) / $confidenceFields * 100, 1)
                : null,
            'comparable_fields' => $comparable,
            'corrected_fields' => $corrected,
            'correction_rate' => $comparable > 0 ? round($corrected / $comparable * 100, 1) : null,
        ];
    }

    /**
     * @param  Builder<CivilRecord>  $records
     * @return Collection<int, array{name: string, role: string, total: int, relative: float}>
     */
    private function submissionsByAccount(Builder $records): Collection
    {
        $rows = (clone $records)
            ->select('submitted_by')
            ->selectRaw('COUNT(*) as total')
            ->whereNotNull('submitted_by')
            ->groupBy('submitted_by')
            ->orderByDesc('total')
            ->limit(10)
            ->with('submitter.role')
            ->get();

        $highest = max(1, (int) $rows->max('total'));

        return $rows->map(fn (CivilRecord $row) => [
            'name' => $row->submitter?->name ?? 'Removed account',
            'role' => $row->submitter?->roleSlug()?->label() ?? RoleSlug::Staff->label(),
            'total' => (int) $row->total,
            'relative' => round((int) $row->total / $highest * 100, 1),
        ]);
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array{labels: list<string>, series: list<array{name: string, data: list<int>}>}
     */
    private function governanceTrend(array $scope): array
    {
        $daily = $scope['start']->diffInDays($scope['end']) <= 90;
        $format = $daily ? 'Y-m-d' : 'Y-m';
        $labelFormat = $daily ? 'j M' : 'M Y';
        $cursor = $scope['start']->startOf($daily ? 'day' : 'month');
        $last = $scope['end']->startOf($daily ? 'day' : 'month');
        $step = $daily ? 'addDay' : 'addMonth';
        $categories = ['Records', 'Requests', 'Accounts', 'Templates', 'OCR', 'Authentication', 'Reports', 'Other'];
        $buckets = [];

        while ($cursor <= $last) {
            $buckets[$cursor->format($format)] = [
                'label' => $cursor->format($labelFormat),
                'counts' => array_fill_keys($categories, 0),
            ];
            $cursor = $cursor->{$step}();
        }

        foreach (AuditLog::query()
            ->whereBetween('created_at', [$scope['db_start'], $scope['db_end']])
            ->select(['id', 'action', 'created_at'])
            ->orderBy('id')
            ->cursor() as $entry) {
            $key = $entry->created_at->setTimezone($scope['timezone'])->format($format);
            if (isset($buckets[$key])) {
                $buckets[$key]['counts'][$this->auditCategory($entry->action)]++;
            }
        }

        return [
            'labels' => array_column($buckets, 'label'),
            'series' => collect($categories)
                ->map(fn (string $category) => [
                    'name' => $category,
                    'data' => array_map(
                        fn (array $bucket) => $bucket['counts'][$category],
                        array_values($buckets),
                    ),
                ])
                ->filter(fn (array $series) => array_sum($series['data']) > 0)
                ->values()
                ->all(),
        ];
    }

    private function auditCategory(string $action): string
    {
        return match (true) {
            str_starts_with($action, 'record.') => 'Records',
            str_starts_with($action, 'change_request.') => 'Requests',
            str_starts_with($action, 'user.') => 'Accounts',
            str_starts_with($action, 'template.'), str_starts_with($action, 'document_type.') => 'Templates',
            str_starts_with($action, 'ocr_'), str_starts_with($action, 'ocr_model.') => 'OCR',
            str_starts_with($action, 'auth.') => 'Authentication',
            str_starts_with($action, 'report.') => 'Reports',
            default => 'Other',
        };
    }

    /**
     * @return array<string, int>
     */
    private function accountHealth(): array
    {
        return [
            'active' => User::where('is_active', true)->count(),
            'inactive' => User::where('is_active', false)->count(),
            'password_change_required' => User::where('is_active', true)
                ->where('must_change_password', true)
                ->count(),
            'never_logged_in' => User::where('is_active', true)
                ->whereNull('last_login_at')
                ->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return Collection<int, array<string, mixed>>
     */
    private function templatePerformance(array $scope): Collection
    {
        $records = $this->records($scope);
        $recordIds = (clone $records)->select('records.id');
        $usage = (clone $records)
            ->whereNotNull('document_template_id')
            ->select('document_template_id')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('document_template_id')
            ->pluck('total', 'document_template_id');

        $threshold = OcrSetting::threshold();
        $quality = DB::table('record_fields')
            ->join('records', 'records.id', '=', 'record_fields.record_id')
            ->whereIn('record_fields.record_id', $recordIds)
            ->whereNotNull('records.document_template_id')
            ->select('records.document_template_id')
            ->selectRaw('AVG(record_fields.ocr_confidence) as average_confidence')
            ->selectRaw('COUNT(record_fields.ocr_confidence) as confidence_fields')
            ->selectRaw('COALESCE(SUM(CASE WHEN record_fields.ocr_confidence < ? THEN 1 ELSE 0 END), 0) as below_threshold', [$threshold])
            ->selectRaw('COUNT(record_fields.ocr_text) as comparable_fields')
            ->selectRaw("COALESCE(SUM(CASE WHEN record_fields.ocr_text IS NOT NULL AND TRIM(COALESCE(record_fields.ocr_text, '')) <> TRIM(COALESCE(record_fields.verified_value, '')) THEN 1 ELSE 0 END), 0) as corrected_fields")
            ->groupBy('records.document_template_id')
            ->get()
            ->keyBy('document_template_id');

        return DocumentTemplate::query()
            ->where('is_active', true)
            ->with(['documentTypeDefinition', 'fields:id,document_template_id,person_group'])
            ->orderBy('document_type_id')
            ->get()
            ->map(function (DocumentTemplate $template) use ($usage, $quality) {
                $row = $quality->get($template->getKey());
                $confidenceFields = (int) ($row?->confidence_fields ?? 0);
                $below = (int) ($row?->below_threshold ?? 0);
                $comparable = (int) ($row?->comparable_fields ?? 0);
                $corrected = (int) ($row?->corrected_fields ?? 0);

                return [
                    'template' => $template,
                    'records' => (int) ($usage[$template->getKey()] ?? 0),
                    'fields' => $template->fields->count(),
                    'person_groups' => $template->fields->pluck('person_group')->filter()->unique()->count(),
                    'average_confidence' => $row?->average_confidence === null
                        ? null
                        : round((float) $row->average_confidence, 1),
                    'below_rate' => $confidenceFields > 0 ? round($below / $confidenceFields * 100, 1) : null,
                    'edit_rate' => $comparable > 0 ? round($corrected / $comparable * 100, 1) : null,
                ];
            });
    }
}
