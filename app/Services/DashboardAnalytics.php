<?php

namespace App\Services;

use App\Enums\ChangeRequestStatus;
use App\Enums\RecordStatus;
use App\Enums\RoleSlug;
use App\Models\ChangeRequest;
use App\Models\CivilRecord;
use App\Models\DocumentTypeDefinition;
use App\Models\OcrSetting;
use App\Models\RecordField;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read-only metrics used by the role-aware landing page.
 *
 * The dashboard deliberately reports digitisation activity, not civil-event
 * incidence. A record's submitted_at says when CRMS archived it, not when a birth,
 * marriage, or death happened.
 */
class DashboardAnalytics
{
    /**
     * @return array<string, mixed>
     */
    public function scope(): array
    {
        $timezone = (string) config('crms.reporting_timezone', 'Asia/Manila');
        $today = CarbonImmutable::now($timezone);
        // Calendar buckets prevent a literal 365-day range from spilling into
        // a thirteenth partial month on the trend chart.
        $end = $today->endOfDay();
        $start = $today->startOfMonth()->subMonths(11);

        return [
            'period' => '365',
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'start' => $start,
            'end' => $end,
            'db_start' => $start->utc(),
            'db_end' => $end->utc(),
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
            'headline' => $this->headline($scope),
            'trend' => $this->recordTrend($scope),
            'by_document_type' => $this->recordsByDocumentType($records),
            'ocr_quality' => $quality,
            'throughput' => $this->submissionsByAccount($records),
            'accounts' => $this->accountHealth(),
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
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private function headline(array $scope): array
    {
        $current = $this->records($scope)->count();
        $total = CivilRecord::query()
            ->where('status', RecordStatus::Submitted->value)
            ->count();

        return [
            'records' => $total,
            'period_records' => $current,
        ];
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function records(array $scope): Builder
    {
        return CivilRecord::query()
            ->where('status', RecordStatus::Submitted->value)
            ->whereBetween('submitted_at', [$scope['db_start'], $scope['db_end']]);
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
     * @return array{mode: string, labels: list<string>, totals: list<int>, series: list<array{name: string, data: list<int>}>, growth_rate: float|null}
     */
    private function recordTrend(array $scope): array
    {
        $daily = $scope['start']->diffInDays($scope['end']) <= 90;
        $format = $daily ? 'Y-m-d' : 'Y-m';
        $labelFormat = $daily ? 'j M' : 'M Y';
        $cursor = $scope['start']->startOf($daily ? 'day' : 'month');
        $last = $scope['end']->startOf($daily ? 'day' : 'month');
        $step = $daily ? 'addDay' : 'addMonth';
        $types = DocumentTypeDefinition::ordered()->values();
        $typeIds = $types->modelKeys();
        $buckets = [];

        while ($cursor <= $last) {
            $buckets[$cursor->format($format)] = [
                'label' => $cursor->format($labelFormat),
                'total' => 0,
                'types' => array_fill_keys($typeIds, 0),
            ];
            $cursor = $cursor->{$step}();
        }

        foreach ($this->records($scope)->select(['id', 'document_type_id', 'submitted_at'])->cursor() as $record) {
            $key = $record->submitted_at->setTimezone($scope['timezone'])->format($format);
            if (isset($buckets[$key])) {
                $buckets[$key]['total']++;

                if (isset($buckets[$key]['types'][$record->document_type_id])) {
                    $buckets[$key]['types'][$record->document_type_id]++;
                }
            }
        }

        $values = array_values($buckets);
        $totals = array_column($values, 'total');
        $latest = (int) ($totals[array_key_last($totals)] ?? 0);
        $previous = count($totals) > 1 ? (int) $totals[count($totals) - 2] : 0;

        return [
            'mode' => $daily ? 'day' : 'month',
            'labels' => array_column($buckets, 'label'),
            'totals' => $totals,
            'series' => $types->map(fn (DocumentTypeDefinition $type) => [
                'name' => $type->shortLabel(),
                'data' => array_map(
                    fn (array $bucket) => (int) ($bucket['types'][$type->getKey()] ?? 0),
                    $values,
                ),
            ])->all(),
            'growth_rate' => $previous > 0
                ? round(($latest - $previous) / $previous * 100, 1)
                : null,
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
     * @return Collection<int, array{name: string, role: string, total: int}>
     */
    private function submissionsByAccount(Builder $records): Collection
    {
        $rows = (clone $records)
            ->select('submitted_by')
            ->selectRaw('COUNT(*) as total')
            ->whereNotNull('submitted_by')
            ->groupBy('submitted_by')
            ->orderByDesc('total')
            ->limit(6)
            ->with('submitter.role')
            ->get();

        return $rows->map(fn (CivilRecord $row) => [
            'name' => $row->submitter?->name ?? 'Removed account',
            'role' => $row->submitter?->roleSlug()?->label() ?? RoleSlug::Staff->label(),
            'total' => (int) $row->total,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function accountHealth(): array
    {
        return [
            'active' => User::where('is_active', true)->count(),
            'inactive' => User::where('is_active', false)->count(),
        ];
    }

}
