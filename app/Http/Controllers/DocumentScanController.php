<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Enums\RecordStatus;
use App\Models\CivilRecord;
use App\Models\DocumentTemplate;
use App\Models\OcrModel;
use App\Services\AuditLogger;
use App\Services\Ocr\OcrClient;
use App\Services\Ocr\OcrServiceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The digitisation flow: upload a scan, mark the fields, run OCR, verify, submit.
 *
 * Staff and Super Admin only. Admin has no route into this controller at all -
 * data entry is not an oversight function.
 *
 * Cropping happens in the browser, matching the prototype, because the crops come
 * straight off the rendered canvas at full resolution. The server never needs the
 * image library that would otherwise be required.
 */
class DocumentScanController extends Controller
{
    public function __construct(
        private readonly OcrClient $ocr,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Step 1: pick a certificate type and upload a scan.
     */
    public function create(): View
    {
        $health = $this->ocr->health();

        $templates = collect(DocumentType::cases())
            ->mapWithKeys(fn (DocumentType $type) => [
                $type->value => DocumentTemplate::activeFor($type),
            ]);

        return view('scan.create', [
            'documentTypes' => DocumentType::cases(),
            'templates' => $templates,
            'health' => $health,
            'activeModel' => OcrModel::active(),
        ]);
    }

    /**
     * Step 2: the marking and verification workspace.
     *
     * The scan itself stays in the browser until submission, so an abandoned
     * session leaves nothing behind on disk.
     */
    public function workspace(Request $request): View|RedirectResponse
    {
        $type = DocumentType::tryFrom((string) $request->query('type'));

        if ($type === null) {
            return redirect()->route('documents.create');
        }

        $template = DocumentTemplate::activeFor($type);

        if ($template === null) {
            return redirect()->route('documents.create')->with(
                'error',
                "No active template for {$type->label()}. A Super Admin must publish one first.",
            );
        }

        return view('scan.workspace', [
            'docType' => $type,
            'template' => $template,
            'boxes' => $template->fields->map->toBox()->values(),
            'activeModel' => OcrModel::active(),
            'threshold' => (float) config('crms.confidence_review_threshold'),
        ]);
    }

    /**
     * Run OCR over the cropped fields.
     *
     * Proxied rather than called from the browser: the OCR service has no auth of
     * its own, so the capability check has to happen here.
     */
    public function recognise(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fields' => ['required', 'array', 'min:1'],
            'fields.*.name' => ['required', 'string', 'max:120'],
            'fields.*.image' => ['required', 'string'],
        ]);

        $active = OcrModel::active();

        try {
            $result = $this->ocr->recognise($validated['fields'], $active?->key);
        } catch (OcrServiceException $e) {
            // A clear failure, not a stack trace, and nothing persisted.
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json([
            'results' => $result['results'],
            'model' => $result['model'],
            'modelKey' => $result['modelKey'],
            'threshold' => (float) config('crms.confidence_review_threshold'),
        ]);
    }

    /**
     * Step 3: persist the verified record and lock it.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'doc_type' => ['required', Rule::enum(DocumentType::class)],
            'document_template_id' => ['required', 'exists:document_templates,id'],
            'registry_number' => ['nullable', 'string', 'max:64'],
            'ocr_model_key' => ['nullable', 'string', 'max:255'],
            'scan' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg,webp,bmp,tiff', 'max:20480'],
            'fields' => ['required', 'array', 'min:1'],
            'fields.*.name' => ['required', 'string', 'max:120'],
            'fields.*.ocr_text' => ['nullable', 'string', 'max:2000'],
            'fields.*.ocr_confidence' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fields.*.verified_value' => ['nullable', 'string', 'max:2000'],
            'fields.*.x' => ['required', 'numeric', 'min:0', 'max:1'],
            'fields.*.y' => ['required', 'numeric', 'min:0', 'max:1'],
            'fields.*.width' => ['required', 'numeric', 'min:0', 'max:1'],
            'fields.*.height' => ['required', 'numeric', 'min:0', 'max:1'],
        ]);

        $record = DB::transaction(function () use ($request, $validated) {
            $path = $request->file('scan')->store('scans', 'local');

            $record = CivilRecord::create([
                'doc_type' => $validated['doc_type'],
                'document_template_id' => $validated['document_template_id'],
                'registry_number' => $validated['registry_number'] ?? null,
                'status' => RecordStatus::Submitted,
                'scan_path' => $path,
                'scan_mime' => $request->file('scan')->getMimeType(),
                'ocr_model_key' => $validated['ocr_model_key'] ?? null,
                'created_by' => $request->user()->getKey(),
                'submitted_by' => $request->user()->getKey(),
                'submitted_at' => now(),
            ]);

            foreach (array_values($validated['fields']) as $index => $field) {
                $record->fields()->create([
                    'name' => $field['name'],
                    'ocr_text' => $field['ocr_text'] ?? null,
                    'ocr_confidence' => $field['ocr_confidence'] ?? null,
                    'verified_value' => $field['verified_value'] ?? null,
                    'x' => $field['x'],
                    'y' => $field['y'],
                    'width' => $field['width'],
                    'height' => $field['height'],
                    'sort_order' => $index,
                ]);
            }

            $record->load('fields');

            $corrected = $record->fields->filter->wasCorrected()->count();

            $this->audit->log(
                'record.submitted',
                $record,
                new: [
                    'doc_type' => $validated['doc_type'],
                    'registry_number' => $validated['registry_number'] ?? null,
                    'field_count' => $record->fields->count(),
                    'corrected_fields' => $corrected,
                    'ocr_model' => $validated['ocr_model_key'] ?? null,
                ],
                description: "Submitted and locked a {$record->doc_type->shortLabel()} record.",
            );

            return $record;
        });

        return redirect()
            ->route('records.show', $record)
            ->with('success', 'Record submitted and locked. Further changes need a change request.');
    }
}
