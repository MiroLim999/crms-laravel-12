<?php

namespace App\Http\Controllers;

use App\Enums\ChangeRequestStatus;
use App\Enums\DocumentType;
use App\Enums\RecordStatus;
use App\Enums\RoleSlug;
use App\Models\ChangeRequest;
use App\Models\CivilRecord;
use App\Models\OcrSetting;
use App\Models\RecordField;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Oversight figures for Admin and Super Admin.
 *
 * Read-only by construction: it aggregates and never touches a record value.
 * Admin reaching this page must not gain a write path to data - see
 * .kiro/steering/product.md on separation of duties.
 */
class AnalyticsController extends Controller
{
    /** How far back the trend chart looks. */
    private const TREND_MONTHS = 12;

    public function index(): View
    {
        return view('analytics.index', [
            'stats' => $this->headlineStats(),
            'byDocumentType' => $this->recordsByDocumentType(),
            'byMonth' => $this->recordsByMonth(),
            'ocrQuality' => $this->ocrQuality(),
            'throughput' => $this->staffThroughput(),
            'threshold' => OcrSetting::threshold(),
        ]);
    }

    /**
     * @return array<string, int|float|null>
     */
    private function headlineStats(): array
    {
        return [
            'total_records' => CivilRecord::count(),
            'submitted_this_month' => CivilRecord::where('status', RecordStatus::Submitted->value)
                ->where('submitted_at', '>=', Carbon::now()->startOfMonth())
                ->count(),
            'pending_change_requests' => ChangeRequest::where('status', ChangeRequestStatus::Pending->value)
                ->count(),
            'average_confidence' => $this->averageConfidence(),
        ];
    }

    /**
     * Every document type appears, including ones with no records yet, so the
     * breakdown reads the same from one month to the next.
     *
     * @return Collection<int, array{type: DocumentType, total: int, share: float}>
     */
    private function recordsByDocumentType(): Collection
    {
        $counts = CivilRecord::query()
            ->select('doc_type')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('doc_type')
            ->pluck('total', 'doc_type');

        $total = max(1, (int) $counts->sum());

        return collect(DocumentType::cases())
            ->map(fn (DocumentType $type) => [
                'type' => $type,
                'total' => (int) ($counts[$type->value] ?? 0),
                'share' => round(((int) ($counts[$type->value] ?? 0)) / $total * 100, 1),
            ])
            ->sortByDesc('total')
            ->values();
    }

    /**
     * Digitisation volume per calendar month.
     *
     * Buckets are pre-filled from the month list rather than the query result, so
     * a quiet month shows as zero instead of vanishing from the axis.
     *
     * @return Collection<int, array{label: string, key: string, total: int}>
     */
    private function recordsByMonth(): Collection
    {
        $from = Carbon::now()->startOfMonth()->subMonths(self::TREND_MONTHS - 1);

        $counts = CivilRecord::query()
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as period")
            ->selectRaw('COUNT(*) as total')
            ->where('created_at', '>=', $from)
            ->groupBy('period')
            ->pluck('total', 'period');

        return collect(range(0, self::TREND_MONTHS - 1))
            ->map(function (int $offset) use ($from, $counts) {
                $month = $from->copy()->addMonths($offset);

                return [
                    'key' => $month->format('Y-m'),
                    'label' => $month->format('M Y'),
                    'total' => (int) ($counts[$month->format('Y-m')] ?? 0),
                ];
            });
    }

    /**
     * Signals of how the OCR model is doing on real documents.
     *
     * Neither figure is an accuracy metric. Confidence is the model's certainty in
     * its own output, and the correction rate only says how often a human changed
     * what the model read - a corrected field may have been right, and an
     * uncorrected one may have been wrong and missed. Treat both as prompts to
     * look closer, and label them that way in the view.
     *
     * @return array<string, int|float|null>
     */
    private function ocrQuality(): array
    {
        $threshold = OcrSetting::threshold();

        // Only fields the model actually read can be compared against a human.
        $comparable = RecordField::whereNotNull('ocr_text')->count();

        $corrected = RecordField::query()
            ->whereNotNull('ocr_text')
            ->whereRaw("TRIM(COALESCE(ocr_text, '')) <> TRIM(COALESCE(verified_value, ''))")
            ->count();

        return [
            'average_confidence' => $this->averageConfidence(),
            'comparable_fields' => $comparable,
            'corrected_fields' => $corrected,
            'correction_rate' => $comparable > 0 ? round($corrected / $comparable * 100, 1) : null,
            'below_threshold' => RecordField::where('ocr_confidence', '<', $threshold)->count(),
        ];
    }

    /**
     * Submissions per data-entry account.
     *
     * Counted by submitter rather than creator: a record only becomes registry
     * data when someone verifies and submits it, and that is the work worth
     * measuring.
     *
     * @return Collection<int, array{name: string, role: string, total: int, share: float}>
     */
    private function staffThroughput(): Collection
    {
        $rows = CivilRecord::query()
            ->select('submitted_by')
            ->selectRaw('COUNT(*) as total')
            ->whereNotNull('submitted_by')
            ->groupBy('submitted_by')
            ->orderByDesc('total')
            ->with('submitter.role')
            ->get();

        $busiest = max(1, (int) $rows->max('total'));

        return $rows->map(fn (CivilRecord $row) => [
            'name' => $row->submitter?->name ?? 'Removed account',
            'role' => $row->submitter?->roleSlug()?->label() ?? RoleSlug::Staff->label(),
            'total' => (int) $row->total,
            // Relative to the busiest submitter, so the bars stay readable.
            'share' => round((int) $row->total / $busiest * 100, 1),
        ]);
    }

    private function averageConfidence(): ?float
    {
        $average = RecordField::whereNotNull('ocr_confidence')->avg('ocr_confidence');

        return $average === null ? null : round((float) $average, 1);
    }
}
