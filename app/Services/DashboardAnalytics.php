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
use Carbon\CarbonInterface;
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
    public const DEFAULT_PERIOD = '365';

    /**
     * The panels that each carry their own reporting period. Their names
     * prefix the query string: trend_period, trend_from, trend_to, and so on.
     *
     * @var list<string>
     */
    public const PANELS = ['trend', 'ocr', 'staff'];

    /** @var array<string, string> */
    public const PERIODS = [
        '30' => 'Last 30 days',
        '90' => 'Last 90 days',
        '365' => 'Last 12 months',
        'custom' => 'Custom dates',
    ];

    /** The earliest day a custom period may start; CRMS archived nothing before it. */
    public const EARLIEST_DATE = '2000-01-01';

    /**
     * Document type colours, in the types' fixed order. A type keeps its colour
     * on every chart because the colour follows the type, never its rank in one
     * chart. This order keeps neighbouring colours apart for colour-blind readers
     * (checked against the white card for protanopia and deuteranopia); slot 1
     * is the theme's primary blue.
     *
     * @var list<string>
     */
    private const TYPE_COLORS = [
        '#2563eb', '#eb6834', '#1baf7a', '#eda100',
        '#e87ba4', '#008300', '#6250d6', '#e34948',
    ];

    /** Past eight types the rest share one grey entry; a ninth hue would not stay distinct. */
    private const OTHER_TYPES_COLOR = '#8a8d93';

    /** Periods up to this many days are charted day by day, longer ones by month. */
    private const DAILY_LIMIT_DAYS = 90;

    /**
     * The reporting period in the reporting timezone, and the earlier period of
     * the same length it is compared with.
     *
     * @param  array{period?: string|null, from?: string|null, to?: string|null}  $filters  validated by the controller
     * @return array<string, mixed>
     */
    public function scope(array $filters = []): array
    {
        $timezone = (string) config('crms.reporting_timezone', 'Asia/Manila');
        $today = CarbonImmutable::now($timezone);
        $period = (string) ($filters['period'] ?? self::DEFAULT_PERIOD);

        if (! array_key_exists($period, self::PERIODS)
            || ($period === 'custom' && (empty($filters['from']) || empty($filters['to'])))) {
            $period = self::DEFAULT_PERIOD;
        }

        if ($period === 'custom') {
            $start = CarbonImmutable::parse((string) $filters['from'], $timezone)->startOfDay();
            $end = CarbonImmutable::parse((string) $filters['to'], $timezone)->endOfDay();
        } elseif ($period === '365') {
            // Calendar months, this one so far and the eleven before it, so the
            // trend chart never shows a thirteenth, partial month.
            $start = $today->startOfMonth()->subMonths(11);
            $end = $today->endOfDay();
        } else {
            $start = $today->subDays((int) $period - 1)->startOfDay();
            $end = $today->endOfDay();
        }

        $days = (int) round($start->diffInDays($end));

        // Measured against a period of the same length, so a month that is
        // still running is never compared with a finished one.
        if ($period === '365') {
            $previousStart = $start->subYearNoOverflow();
            $previousEnd = $end->subYearNoOverflow();
            $comparison = 'the same dates last year';
        } else {
            $previousStart = $start->subDays($days);
            $previousEnd = $start->subSecond();
            $comparison = $days === 1 ? 'the previous day' : "the previous {$days} days";
        }

        return [
            'period' => $period,
            'period_label' => self::PERIODS[$period],
            // A card badge: the preset's name, or the dates themselves.
            'badge' => $period === 'custom' ? $this->rangeLabel($start, $end) : self::PERIODS[$period],
            // The period menu's button and the panel headers, where room is short.
            'short_badge' => $period === 'custom' ? $this->shortRangeLabel($start, $end) : self::PERIODS[$period],
            'short_label' => $this->shortRangeLabel($start, $end),
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'start' => $start,
            'end' => $end,
            'days' => $days,
            'db_start' => $start->utc(),
            'db_end' => $end->utc(),
            'previous_db_start' => $previousStart->utc(),
            'previous_db_end' => $previousEnd->utc(),
            'previous_label' => $this->rangeLabel($previousStart, $previousEnd),
            'comparison' => $comparison,
            'timezone' => $timezone,
            'label' => $this->rangeLabel($start, $end),
        ];
    }

    /**
     * Every panel that depends on a period has its own, so each is given its
     * own scope: trend, ocr and staff.
     *
     * @param  array{trend: array<string, mixed>, ocr: array<string, mixed>, staff: array<string, mixed>}  $scopes
     * @return array<string, mixed>
     */
    public function oversight(array $scopes): array
    {
        $entries = $this->typeEntries(DocumentTypeDefinition::ordered()->values());

        return [
            'headline' => $this->headline($scopes['trend']),
            'archive_types' => $this->archiveByType($entries),
            'trend' => $this->recordTrend($scopes['trend'], $entries),
            'ocr_quality' => $this->ocrQuality($scopes['ocr']),
            'throughput' => $this->submissionsByAccount($this->records($scopes['staff'])),
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
     * The all-time and this-month totals, and the trend panel's period
     * measured against the one before it.
     *
     * @param  array<string, mixed>  $scope  the trend panel's
     * @return array<string, int|float|null>
     */
    private function headline(array $scope): array
    {
        $current = $this->records($scope)->count();
        $previous = $this->submittedBetween($scope['previous_db_start'], $scope['previous_db_end'])->count();
        $timezone = (string) config('crms.reporting_timezone', 'Asia/Manila');

        return [
            'records' => CivilRecord::query()
                ->where('status', RecordStatus::Submitted->value)
                ->count(),
            'month_records' => $this->submittedBetween(
                CarbonImmutable::now($timezone)->startOfMonth()->utc(),
                CarbonImmutable::now($timezone)->endOfDay()->utc(),
            )->count(),
            'period_records' => $current,
            'previous_records' => $previous,
            'change' => $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null,
        ];
    }

    /**
     * What the archive holds: submitted records by document type, all time,
     * largest first. Each type keeps its colour from the trend chart; types
     * past the palette, or no longer listed, share the grey "Other types".
     *
     * @param  array<int, array{key: string, name: string, color: string, type: DocumentTypeDefinition}>  $entries
     * @return list<array{name: string, color: string, total: int}>
     */
    private function archiveByType(array $entries): array
    {
        $counts = CivilRecord::query()
            ->where('status', RecordStatus::Submitted->value)
            ->selectRaw('document_type_id, COUNT(*) as total')
            ->groupBy('document_type_id')
            ->pluck('total', 'document_type_id');

        $groups = [];
        $add = function (array $entry, int $total) use (&$groups): void {
            $groups[$entry['key']] ??= ['name' => $entry['name'], 'color' => $entry['color'], 'total' => 0];
            $groups[$entry['key']]['total'] += $total;
        };

        foreach ($entries as $typeId => $entry) {
            if (isset($counts[$typeId])) {
                $add($entry, (int) $counts[$typeId]);
            }
        }

        $unlisted = (int) $counts->except(array_keys($entries))->sum();
        if ($unlisted > 0) {
            $add($this->otherEntry(), $unlisted);
        }

        // The sort is stable, so equal counts keep the type list's order.
        return collect($groups)->sortByDesc('total')->values()->all();
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function records(array $scope): Builder
    {
        return $this->submittedBetween($scope['db_start'], $scope['db_end']);
    }

    private function submittedBetween(CarbonInterface $from, CarbonInterface $to): Builder
    {
        return CivilRecord::query()
            ->where('status', RecordStatus::Submitted->value)
            ->whereBetween('submitted_at', [$from, $to]);
    }

    /**
     * How each document type is drawn: its colour and the chart entry it
     * belongs to. Up to eight types get a colour each; past that the first
     * seven keep theirs and the rest share one grey "Other types" entry.
     *
     * @param  Collection<int, DocumentTypeDefinition>  $types  in their fixed order
     * @return array<int, array{key: string, name: string, color: string, type: DocumentTypeDefinition}>
     */
    private function typeEntries(Collection $types): array
    {
        $palette = count(self::TYPE_COLORS);
        $ownColours = $types->count() > $palette ? $palette - 1 : $palette;
        $entries = [];

        foreach ($types->values() as $index => $type) {
            $entries[$type->getKey()] = ($index < $ownColours
                ? ['key' => 'type-'.$type->getKey(), 'name' => $type->shortLabel(), 'color' => self::TYPE_COLORS[$index]]
                : $this->otherEntry()) + ['type' => $type];
        }

        return $entries;
    }

    /**
     * @return array{key: string, name: string, color: string}
     */
    private function otherEntry(): array
    {
        return ['key' => 'other', 'name' => 'Other types', 'color' => self::OTHER_TYPES_COLOR];
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  array<int, array{key: string, name: string, color: string, type: DocumentTypeDefinition}>  $entries
     * @return array{mode: string, labels: list<string>, titles: list<string>, totals: list<int>, series: list<array{name: string, color: string, data: list<int>}>, starts_at: CarbonImmutable|null}
     */
    private function recordTrend(array $scope, array $entries): array
    {
        $daily = $scope['days'] <= self::DAILY_LIMIT_DAYS;
        $unit = $daily ? 'day' : 'month';
        $format = $daily ? 'Y-m-d' : 'Y-m';
        $labelFormat = $daily ? 'j M' : 'M Y';
        $step = $daily ? 'addDay' : 'addMonth';
        $cursor = $scope['start']->startOf($unit);
        $last = $scope['end']->startOf($unit);

        // Nothing was archived before the first record, so the chart starts at
        // its day or month instead of drawing a run of zeros.
        $startsAt = null;
        $first = CivilRecord::query()
            ->where('status', RecordStatus::Submitted->value)
            ->min('submitted_at');

        if ($first !== null) {
            $firstLocal = CarbonImmutable::parse($first, 'UTC')->setTimezone($scope['timezone']);

            if ($firstLocal->startOf($unit) > $cursor) {
                $cursor = $firstLocal->startOf($unit);
                $startsAt = $firstLocal;
            }
        }

        $buckets = [];

        while ($cursor <= $last) {
            $buckets[$cursor->format($format)] = [
                'label' => $cursor->format($labelFormat),
                // The tooltip's fuller name for the same day or month.
                'title' => $cursor->format($daily ? 'D, j M Y' : 'F Y'),
                'total' => 0,
                'groups' => [],
            ];
            $cursor = $cursor->{$step}();
        }

        foreach ($this->records($scope)->select(['id', 'document_type_id', 'submitted_at'])->cursor() as $record) {
            $key = $record->submitted_at->setTimezone($scope['timezone'])->format($format);

            if (isset($buckets[$key])) {
                $group = ($entries[$record->document_type_id] ?? $this->otherEntry())['key'];
                $buckets[$key]['total']++;
                $buckets[$key]['groups'][$group] = ($buckets[$key]['groups'][$group] ?? 0) + 1;
            }
        }

        $values = array_values($buckets);

        $series = collect($entries)
            ->push($this->otherEntry())
            ->unique('key')
            ->map(fn (array $entry) => [
                'name' => $entry['name'],
                'color' => $entry['color'],
                'data' => array_map(fn (array $bucket) => (int) ($bucket['groups'][$entry['key']] ?? 0), $values),
            ])
            // A type with nothing in this period would only add a flat line at zero.
            ->filter(fn (array $series) => array_sum($series['data']) > 0)
            ->values()
            ->all();

        return [
            'mode' => $unit,
            'labels' => array_column($values, 'label'),
            'titles' => array_column($values, 'title'),
            'totals' => array_column($values, 'total'),
            'series' => $series,
            'starts_at' => $values === [] ? null : $startsAt,
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

        // A correction is staff changing the OCR reading while verifying. An
        // approved change request rewrites verified_value afterwards, so the
        // value as submitted is the first approved request's "before" value,
        // and only failing that the current one.
        $submittedValue = 'COALESCE((SELECT cri.current_value FROM change_request_items AS cri'
            .' INNER JOIN change_requests AS cr ON cr.id = cri.change_request_id'
            .' WHERE cri.record_field_id = record_fields.id AND cr.status = ?'
            .' ORDER BY cr.reviewed_at, cr.id LIMIT 1), record_fields.verified_value)';
        $corrected = $this->differs('TRIM(record_fields.ocr_text)', "TRIM(COALESCE({$submittedValue}, ''))");

        $row = RecordField::query()
            ->whereIn('record_id', $recordIds)
            ->selectRaw('COUNT(ocr_confidence) as confidence_fields')
            ->selectRaw('AVG(ocr_confidence) as average_confidence')
            ->selectRaw('COALESCE(SUM(CASE WHEN ocr_confidence < ? THEN 1 ELSE 0 END), 0) as below_threshold', [$threshold])
            ->selectRaw('COUNT(ocr_text) as comparable_fields')
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN ocr_text IS NOT NULL AND {$corrected} THEN 1 ELSE 0 END), 0) as corrected_fields",
                [ChangeRequestStatus::Approved->value],
            )
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
     * SQL that is true when two text expressions differ, letter case included,
     * as RecordField::wasCorrected() compares them. MySQL's default collation
     * would call "juan" and "Juan" equal.
     */
    private function differs(string $left, string $right): string
    {
        $driver = RecordField::query()->getConnection()->getDriverName();

        return in_array($driver, ['mysql', 'mariadb'], true)
            ? "CAST({$left} AS BINARY) <> CAST({$right} AS BINARY)"
            : "{$left} <> {$right}";
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
            ->orderBy('submitted_by')
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

    private function rangeLabel(CarbonInterface $start, CarbonInterface $end): string
    {
        return $start->isSameDay($end)
            ? $start->format('j M Y')
            : $start->format('j M Y').' – '.$end->format('j M Y');
    }

    /**
     * The same range, saying a shared month or year once: "1–2 Oct 2026",
     * "14 Sep – 13 Oct 2026", "1 Nov 2025 – 13 Oct 2026".
     */
    private function shortRangeLabel(CarbonInterface $start, CarbonInterface $end): string
    {
        return match (true) {
            $start->isSameDay($end) => $start->format('j M Y'),
            $start->isSameMonth($end) => $start->format('j').'–'.$end->format('j M Y'),
            $start->isSameYear($end) => $start->format('j M').' – '.$end->format('j M Y'),
            default => $this->rangeLabel($start, $end),
        };
    }
}
