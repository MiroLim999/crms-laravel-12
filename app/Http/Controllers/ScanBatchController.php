<?php

namespace App\Http\Controllers;

use App\Models\DocumentPage;
use App\Models\DocumentTemplate;
use App\Models\DocumentTypeDefinition;
use App\Models\ScanBatch;
use App\Models\ScanBatchDraft;
use App\Models\ScanBatchPage;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Batch File Upload, kept on the server while it is worked on: its queue (each
 * page's state, alignment and flags) and each record's draft, so a reload, a
 * closed tab or a crash loses no more than the page being worked on.
 *
 * Only the batch's owner sees it (404 for anyone else). Every change carries
 * the batch's version and the tab it comes from; one from an older copy (a
 * second tab, or a tab another one took the batch over from) is refused with
 * 409, so two tabs never overwrite each other.
 */
class ScanBatchController extends Controller
{
    /** The most pages one change may send (a register of a few hundred pages, in parts). */
    private const MAX_PAGES_PER_CHANGE = 1000;

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'doc_type' => ['required', 'string', 'exists:document_types,key'],
            'document_template_id' => ['required', 'integer', 'exists:document_templates,id'],
            'page_two_template_id' => ['nullable', 'integer', 'exists:document_templates,id'],
            'batch_key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/', 'unique:scan_batches,batch_key'],
            'tab' => ['required', 'string', 'max:64'],
        ]);

        $batch = ScanBatch::create([
            'user_id' => $request->user()->getKey(),
            'doc_type' => $validated['doc_type'],
            'document_template_id' => $validated['document_template_id'],
            'page_two_template_id' => $validated['page_two_template_id'] ?? null,
            'batch_key' => $validated['batch_key'],
            'status' => ScanBatch::STATUS_OPEN,
            'version' => 0,
            'tab' => $validated['tab'],
            'seen_at' => now(),
        ]);

        return response()->json($this->payload($batch), 201);
    }

    /** The batch with its pages and drafts, to resume it. */
    public function show(Request $request, ScanBatch $batch): JsonResponse
    {
        $this->authorizeBatch($request, $batch);

        return response()->json($this->payload($batch->load(['pages', 'drafts', 'template']), full: true));
    }

    /** This tab takes the batch over: another tab's changes are refused from now on. */
    public function claim(Request $request, ScanBatch $batch): JsonResponse
    {
        $this->authorizeBatch($request, $batch);
        $validated = $request->validate(['tab' => ['required', 'string', 'max:64']]);
        if (! $batch->isOpen()) {
            return $this->closed($batch);
        }

        $batch->forceFill([
            'tab' => $validated['tab'],
            'version' => $batch->version + 1,
            'seen_at' => now(),
        ])->save();

        return response()->json($this->payload($batch));
    }

    /**
     * The tab left the batch (Keep for later, or the tab closed): it no longer
     * counts as open there, so opening it again soon does not warn about
     * another tab. Only the tab that has it can let it go; nothing else changes.
     */
    public function release(Request $request, ScanBatch $batch): JsonResponse
    {
        $this->authorizeBatch($request, $batch);
        $validated = $request->validate(['tab' => ['required', 'string', 'max:64']]);
        if ($batch->isOpen() && $batch->tab === $validated['tab']) {
            $batch->forceFill(['seen_at' => null])->save();
        }

        return response()->json(['version' => $batch->version]);
    }

    /** The tab is still working on it (nothing changes but when it was last heard from). */
    public function ping(Request $request, ScanBatch $batch): JsonResponse
    {
        $this->authorizeBatch($request, $batch);
        $validated = $this->validateChange($request);

        return DB::transaction(function () use ($batch, $validated) {
            $locked = ScanBatch::query()->lockForUpdate()->findOrFail($batch->getKey());
            if ($refused = $this->refused($locked, $validated)) {
                return $refused;
            }
            $locked->forceFill(['seen_at' => now()])->save();

            return response()->json(['version' => $locked->version]);
        });
    }

    /** Pages added or changed (state, alignment, flags, order), and pages removed. */
    public function updatePages(Request $request, ScanBatch $batch): JsonResponse
    {
        $this->authorizeBatch($request, $batch);
        $validated = $this->validateChange($request, [
            'pages' => ['present', 'array', 'max:'.self::MAX_PAGES_PER_CHANGE],
            'pages.*.key' => ['required', 'string', 'max:64'],
            'pages.*.position' => ['required', 'integer', 'min:0'],
            'pages.*.source_name' => ['required', 'string', 'max:255'],
            'pages.*.source_hash' => ['nullable', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'pages.*.page_no' => ['required', 'integer', 'min:1'],
            'pages.*.page_total' => ['required', 'integer', 'min:1'],
            'pages.*.record_key' => ['required', 'string', 'max:120'],
            'pages.*.status' => ['required', 'string', Rule::in(ScanBatchPage::STATUSES)],
            'pages.*.document_page_id' => ['nullable', 'integer'],
            'pages.*.workspace_scan_path' => ['nullable', 'string', 'max:500', 'regex:#^documents/workspace/[^.]#', 'not_regex:/\.\./'],
            'pages.*.alignment' => ['nullable', 'array'],
            'pages.*.flags' => ['nullable', 'array'],
            'pages.*.error' => ['nullable', 'string', 'max:2000'],
            'removed' => ['sometimes', 'array', 'max:'.self::MAX_PAGES_PER_CHANGE],
            'removed.*' => ['string', 'max:64'],
        ]);

        // A page on the server is linked only if it is the user's own (and still there).
        $pageIds = collect($validated['pages'])->pluck('document_page_id')->filter()->map(fn ($id) => (int) $id)->unique();
        $ownPageIds = DocumentPage::query()
            ->whereIn('id', $pageIds)
            ->where('created_by', $request->user()->getKey())
            ->pluck('id')
            ->all();

        return DB::transaction(function () use ($batch, $validated, $ownPageIds) {
            $locked = ScanBatch::query()->lockForUpdate()->findOrFail($batch->getKey());
            if ($refused = $this->refused($locked, $validated)) {
                return $refused;
            }

            foreach ($validated['pages'] as $page) {
                $documentPageId = isset($page['document_page_id']) ? (int) $page['document_page_id'] : null;
                ScanBatchPage::updateOrCreate(
                    ['scan_batch_id' => $locked->getKey(), 'client_key' => $page['key']],
                    [
                        'position' => (int) $page['position'],
                        'source_name' => $page['source_name'],
                        'source_hash' => $page['source_hash'] ?? null,
                        'page_no' => (int) $page['page_no'],
                        'page_total' => (int) $page['page_total'],
                        'record_key' => $page['record_key'],
                        'status' => $page['status'],
                        'document_page_id' => in_array($documentPageId, $ownPageIds, true) ? $documentPageId : null,
                        'workspace_scan_path' => $page['workspace_scan_path'] ?? null,
                        'alignment' => $page['alignment'] ?? null,
                        'flags' => $page['flags'] ?? null,
                        'error' => $page['error'] ?? null,
                    ],
                );
            }
            if (! empty($validated['removed'])) {
                $locked->pages()->whereIn('client_key', $validated['removed'])->whereNull('record_id')->delete();
            }

            $locked->forceFill(['version' => $locked->version + 1, 'seen_at' => now()])->save();

            return response()->json(['version' => $locked->version]);
        });
    }

    /** One record's draft (null: none, as once it is saved or left out). */
    public function saveDraft(Request $request, ScanBatch $batch): JsonResponse
    {
        $this->authorizeBatch($request, $batch);
        $validated = $this->validateChange($request, [
            'record_key' => ['required', 'string', 'max:120'],
            'draft' => ['nullable', 'array'],
        ]);

        return DB::transaction(function () use ($batch, $validated) {
            $locked = ScanBatch::query()->lockForUpdate()->findOrFail($batch->getKey());
            if ($refused = $this->refused($locked, $validated)) {
                return $refused;
            }

            if ($validated['draft'] === null) {
                $locked->drafts()->where('record_key', $validated['record_key'])->delete();
            } else {
                ScanBatchDraft::updateOrCreate(
                    ['scan_batch_id' => $locked->getKey(), 'record_key' => $validated['record_key']],
                    ['draft' => $validated['draft']],
                );
            }
            $locked->forceFill(['version' => $locked->version + 1, 'seen_at' => now()])->save();

            return response()->json(['version' => $locked->version]);
        });
    }

    /** Every record is saved or left out: the batch is finished. */
    public function done(Request $request, ScanBatch $batch): JsonResponse
    {
        $this->authorizeBatch($request, $batch);
        $validated = $this->validateChange($request);

        return DB::transaction(function () use ($batch, $validated) {
            $locked = ScanBatch::query()->lockForUpdate()->findOrFail($batch->getKey());
            if ($refused = $this->refused($locked, $validated)) {
                return $refused;
            }
            $locked->forceFill([
                'status' => ScanBatch::STATUS_DONE,
                'version' => $locked->version + 1,
                'seen_at' => now(),
            ])->save();

            return response()->json(['version' => $locked->version, 'status' => $locked->status]);
        });
    }

    /**
     * Discard: the batch is given up. Its pages are let go by the next prune.
     * From any tab, since it ends the batch for all of them.
     */
    public function abandon(Request $request, ScanBatch $batch, AuditLogger $audit): JsonResponse
    {
        $this->authorizeBatch($request, $batch);

        if ($batch->isOpen()) {
            $batch->forceFill([
                'status' => ScanBatch::STATUS_ABANDONED,
                'version' => $batch->version + 1,
            ])->save();

            $audit->log('scan.discarded', $batch, description: 'Scan batch discarded before all its records were saved.');
        }

        return response()->json(['version' => $batch->version, 'status' => $batch->status]);
    }

    /**
     * The user's open batches, newest first, as the workspace offers them to
     * resume: how many records they have, and how many are saved.
     *
     * @return list<array<string, mixed>>
     */
    public static function openBatchesFor(int $userId): array
    {
        $batches = ScanBatch::query()
            ->where('user_id', $userId)
            ->where('status', ScanBatch::STATUS_OPEN)
            ->with(['pages:id,scan_batch_id,record_key,status,flags', 'drafts:id,scan_batch_id,record_key,draft'])
            ->latest('updated_at')
            ->get();
        $labels = DocumentTypeDefinition::query()
            ->whereIn('key', $batches->pluck('doc_type')->unique())
            ->get()
            ->mapWithKeys(fn (DocumentTypeDefinition $type) => [$type->key => $type->label()]);

        return $batches
            ->map(function (ScanBatch $batch) use ($labels) {
                $records = $batch->pages->groupBy('record_key');

                return [
                    'id' => $batch->getKey(),
                    'docType' => $batch->doc_type,
                    'typeLabel' => $labels[$batch->doc_type] ?? $batch->doc_type,
                    'records' => $records->count(),
                    'saved' => $records->filter(fn ($pages) => $pages->every(fn ($page) => $page->status === 'imported'))->count(),
                    'pages' => $batch->pages->count(),
                    // Pages sent back by Re-align and not queued again yet.
                    'realigning' => $batch->pages->filter(fn ($page) => $page->status === 'pending' && ! empty($page->flags['realign']))->count(),
                    'leftOn' => self::leftOn($batch),
                    'updatedAt' => $batch->updated_at?->toIso8601String(),
                    'workspaceUrl' => route('documents.workspace', ['type' => $batch->doc_type]),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The step the batch was left on (the page open then, which Resume opens
     * again): Align, Verify or Review & Export. Null when not known.
     */
    private static function leftOn(ScanBatch $batch): ?string
    {
        $page = $batch->pages->first(fn ($page) => ! empty($page->flags['lastOpen'])
            && ! in_array($page->status, ['imported', 'rejected'], true));
        if ($page === null) {
            return null;
        }
        if (in_array($page->status, ['pending', 'saved', 'failed'], true)) {
            return 'Align';
        }
        if ($page->status !== 'ready') {
            return null;
        }
        $draft = $batch->drafts->firstWhere('record_key', $page->record_key)?->draft;

        return ($draft['step'] ?? null) === 'review' ? 'Review & Export' : 'Verify';
    }

    /**
     * @param  array<string, array<int, mixed>>  $rules
     * @return array<string, mixed>
     */
    private function validateChange(Request $request, array $rules = []): array
    {
        return $request->validate([
            'version' => ['required', 'integer', 'min:0'],
            'tab' => ['required', 'string', 'max:64'],
            ...$rules,
        ]);
    }

    /**
     * Why a change is refused, or null: the batch is no longer open, or the
     * change comes from an older copy of it (another tab took it over).
     *
     * @param  array<string, mixed>  $validated
     */
    private function refused(ScanBatch $batch, array $validated): ?JsonResponse
    {
        if (! $batch->isOpen()) {
            return $this->closed($batch);
        }
        if ((int) $validated['version'] !== $batch->version || ($batch->tab !== null && $batch->tab !== $validated['tab'])) {
            return response()->json([
                'message' => 'This batch was changed in another tab.',
                'reason' => $batch->tab !== $validated['tab'] ? 'taken' : 'stale',
                'version' => $batch->version,
            ], 409);
        }

        return null;
    }

    private function closed(ScanBatch $batch): JsonResponse
    {
        return response()->json([
            'message' => $batch->status === ScanBatch::STATUS_DONE ? 'This batch is finished.' : 'This batch was discarded.',
            'reason' => 'closed',
            'status' => $batch->status,
            'version' => $batch->version,
        ], 409);
    }

    private function authorizeBatch(Request $request, ScanBatch $batch): void
    {
        // 404, not 403: another user's batch does not exist for you.
        abort_unless((int) $batch->user_id === (int) $request->user()->getKey(), 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ScanBatch $batch, bool $full = false): array
    {
        $template = $batch->relationLoaded('template') ? $batch->template : DocumentTemplate::find($batch->document_template_id);
        $data = [
            'id' => $batch->getKey(),
            'key' => $batch->batch_key,
            'docType' => $batch->doc_type,
            'templateId' => $batch->document_template_id,
            'pageTwoTemplateId' => $batch->page_two_template_id,
            // The layout it was started with was deleted (or is gone).
            'templateArchived' => $template === null || $template->isArchived(),
            'status' => $batch->status,
            'version' => $batch->version,
            'tab' => $batch->tab,
            'seenAt' => $batch->seen_at?->toIso8601String(),
            'updatedAt' => $batch->updated_at?->toIso8601String(),
        ];
        if (! $full) {
            return $data;
        }

        $data['pages'] = $batch->pages->map(fn (ScanBatchPage $page) => [
            'key' => $page->client_key,
            'position' => $page->position,
            'sourceName' => $page->source_name,
            'sourceHash' => $page->source_hash,
            'pageNo' => $page->page_no,
            'pageTotal' => $page->page_total,
            'recordKey' => $page->record_key,
            'status' => $page->status,
            'documentPageId' => $page->document_page_id,
            'statusUrl' => $page->document_page_id ? route('documents.pages.show', $page->document_page_id) : null,
            'workspaceScanPath' => $page->workspace_scan_path,
            'alignment' => $page->alignment,
            'flags' => $page->flags ?? [],
            'error' => $page->error,
            'recordId' => $page->record_id,
            'recordUrl' => $page->record_id ? route('records.show', $page->record_id) : null,
        ])->values()->all();
        $data['drafts'] = $batch->drafts->map(fn (ScanBatchDraft $draft) => [
            'recordKey' => $draft->record_key,
            'draft' => $draft->draft,
        ])->values()->all();

        return $data;
    }
}
