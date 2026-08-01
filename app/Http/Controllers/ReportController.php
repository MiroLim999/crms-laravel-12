<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Enums\RecordStatus;
use App\Enums\RoleSlug;
use App\Models\CivilRecord;
use App\Models\RecordField;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Registry reporting for Admin and Super Admin.
 *
 * Reads and exports; it never writes a record value. The only write it makes is
 * the audit entry for the export itself, because who pulled registry data out of
 * the system, and with which filters, is part of the trail.
 */
class ReportController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        $records = $this->query($filters)
            ->with(['fields', 'submitter', 'creator'])
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('reports.index', [
            'filters' => $filters,
            'records' => $records,
            'summary' => $this->summary($filters),
            'documentTypes' => DocumentType::cases(),
            'dataEntryUsers' => $this->dataEntryUsers(),
        ]);
    }

    /**
     * Stream the matching records as CSV.
     *
     * Rows are written straight to the output buffer and the query is chunked, so
     * a report covering the whole archive costs a constant amount of memory
     * instead of materialising every row first.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);

        /*
         * Logged before the stream opens. A download that fails halfway still
         * happened as far as the trail is concerned, and the alternative - logging
         * inside the callback - runs after headers are sent, where a failure would
         * be invisible.
         */
        $this->auditLogger->log(
            'report.generated',
            new: array_filter($filters, fn ($value) => $value !== null),
            description: 'Exported a registry report as CSV.',
            actor: $request->user(),
        );

        $filename = 'crms-records-'.Carbon::now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($filters) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Record ID', 'Registry number', 'Document type', 'Status', 'Primary value',
                'Created at', 'Created by', 'Submitted at', 'Submitted by',
                'OCR model', 'Fields', 'Average confidence',
            ]);

            $this->query($filters)
                ->with(['fields', 'submitter', 'creator'])
                ->chunkById(200, function (Collection $chunk) use ($handle) {
                    foreach ($chunk as $record) {
                        fputcsv($handle, $this->row($record));
                        flush();
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            // Reports are per-request and role-scoped; never let one sit in a cache.
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /**
     * @return list<string|int|null>
     */
    private function row(CivilRecord $record): array
    {
        $confidences = $record->fields
            ->pluck('ocr_confidence')
            ->filter(fn ($value) => $value !== null);

        return [
            $record->getKey(),
            $record->registry_number,
            $record->doc_type->label(),
            $record->status->label(),
            $record->title(),
            $record->created_at?->toDateTimeString(),
            $record->creator?->name,
            $record->submitted_at?->toDateTimeString(),
            $record->submitter?->name,
            $record->ocr_model_key,
            $record->fields->count(),
            $confidences->isEmpty() ? null : round($confidences->avg(), 1),
        ];
    }

    /**
     * Validated filter set, shared by the on-screen table and the export so the
     * two can never disagree about what "matching" means.
     *
     * @return array{from: string|null, to: string|null, doc_type: string|null, status: string|null, submitted_by: int|null}
     */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'doc_type' => ['nullable', 'string', 'in:'.implode(',', array_column(DocumentType::cases(), 'value'))],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(RecordStatus::cases(), 'value'))],
            'submitted_by' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        return [
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'doc_type' => $validated['doc_type'] ?? null,
            'status' => $validated['status'] ?? null,
            'submitted_by' => isset($validated['submitted_by']) ? (int) $validated['submitted_by'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function query(array $filters): Builder
    {
        return CivilRecord::query()
            ->when($filters['from'], fn (Builder $q, $from) => $q->where(
                'records.created_at',
                '>=',
                Carbon::parse($from)->startOfDay(),
            ))
            ->when($filters['to'], fn (Builder $q, $to) => $q->where(
                'records.created_at',
                '<=',
                Carbon::parse($to)->endOfDay(),
            ))
            ->when($filters['doc_type'], fn (Builder $q, $type) => $q->where('doc_type', $type))
            ->when($filters['status'], fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['submitted_by'], fn (Builder $q, $id) => $q->where('submitted_by', $id));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, int|float|null>
     */
    private function summary(array $filters): array
    {
        $matching = $this->query($filters);

        return [
            'total' => $matching->clone()->count(),
            'submitted' => $matching->clone()->where('status', RecordStatus::Submitted->value)->count(),
            'drafts' => $matching->clone()->where('status', RecordStatus::Draft->value)->count(),
            'average_confidence' => $this->averageConfidence($filters),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function averageConfidence(array $filters): ?float
    {
        $average = RecordField::query()
            ->whereNotNull('ocr_confidence')
            ->whereIn('record_id', $this->query($filters)->select('records.id'))
            ->avg('ocr_confidence');

        return $average === null ? null : round((float) $average, 1);
    }

    /**
     * Accounts that can appear as a submitter. Admin never can, so listing every
     * user would only offer choices that match nothing.
     *
     * @return Collection<int, User>
     */
    private function dataEntryUsers(): Collection
    {
        return User::query()
            ->whereHas('role', fn ($q) => $q->whereIn('slug', [
                RoleSlug::Staff->value,
                RoleSlug::SuperAdmin->value,
            ]))
            ->orderBy('name')
            ->get(['id', 'name', 'role_id']);
    }
}
