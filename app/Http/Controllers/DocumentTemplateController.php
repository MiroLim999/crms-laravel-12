<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Enums\PageOrientation;
use App\Enums\PaperSize;
use App\Models\DocumentTemplate;
use App\Models\DocumentTypeDefinition;
use App\Services\AuditLogger;
use App\Services\TemplateSampleStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Document template builder - Super Admin only.
 *
 * Templates decide which fields Staff capture per certificate type and where the
 * boxes start. Coordinates are fractions of the page so a layout works at any
 * scan resolution.
 */
class DocumentTemplateController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TemplateSampleStorage $samples,
    ) {}

    public function index(): View
    {
        return view('templates.index', [
            'templates' => DocumentTemplate::withCount(['fields', 'records'])
                ->with(['creator', 'documentTypeDefinition'])
                ->orderBy('document_type_id')
                ->orderByDesc('is_active')
                ->get()
                ->groupBy('document_type_id'),
            'documentTypes' => DocumentTypeDefinition::ordered(),
        ]);
    }

    public function create(Request $request): View
    {
        $requestedKey = (string) $request->query('type', DocumentType::Birth->value);
        $type = DocumentTypeDefinition::where('key', $requestedKey)->firstOrFail();

        return view('templates.edit', [
            'template' => null,
            'docType' => $type,
            // Start from the prototype's field boxes rather than a blank page.
            'fields' => $type->defaultFields(),
            'paperSizes' => PaperSize::cases(),
            'orientations' => PageOrientation::cases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePayload($request);
        $documentType = DocumentTypeDefinition::findOrFail($validated['document_type_id']);

        $template = DB::transaction(function () use ($validated, $request, $documentType) {
            $template = DocumentTemplate::create([
                'name' => $validated['name'],
                'doc_type' => $documentType->legacyType()->value,
                'document_type_id' => $documentType->getKey(),
                'paper_size' => $validated['paper_size'],
                'orientation' => $validated['orientation'],
                'custom_width_mm' => $validated['custom_width_mm'],
                'custom_height_mm' => $validated['custom_height_mm'],
                'description' => $validated['description'] ?? null,
                'grouping_mode' => $validated['grouping_mode'],
                'is_active' => false,
                'created_by' => $request->user()->getKey(),
            ]);

            $this->syncFields($template, $validated['fields']);

            if (($validated['sample_document'] ?? null) instanceof UploadedFile) {
                $template->forceFill(
                    $this->samples->store($template, $validated['sample_document']),
                )->save();
            }

            $this->audit->log(
                'template.created',
                $template,
                new: ['name' => $template->name, 'document_type' => $documentType->key,
                    'paper_size' => $validated['paper_size'], 'orientation' => $validated['orientation'],
                    'custom_width_mm' => $validated['custom_width_mm'],
                    'custom_height_mm' => $validated['custom_height_mm'],
                    'field_count' => count($validated['fields']),
                    'grouping_mode' => $validated['grouping_mode'],
                    'group_count' => $this->personGroupCount($validated['fields']),
                    'sample_document' => $template->sample_original_name],
                description: "Created template '{$template->name}'.",
            );

            if ($validated['publish'] ?? false) {
                $this->publishTemplate($template);
            }

            return $template;
        });

        return redirect()
            ->route('templates.edit', $template)
            ->with(
                'success',
                ($validated['publish'] ?? false)
                    ? 'Template created and published for Staff.'
                    : 'Template saved as a draft.',
            );
    }

    public function edit(DocumentTemplate $template): View
    {
        $template->load(['fields', 'documentTypeDefinition']);

        return view('templates.edit', [
            'template' => $template,
            'docType' => $template->documentTypeDefinition,
            'fields' => $template->fields->map(fn ($f) => [
                'name' => $f->name,
                'x' => $f->x,
                'y' => $f->y,
                'width' => $f->width,
                'height' => $f->height,
                'person_group' => $f->person_group,
                'person_field_order' => $f->person_field_order,
            ])->all(),
            'paperSizes' => PaperSize::cases(),
            'orientations' => PageOrientation::cases(),
        ]);
    }

    public function update(Request $request, DocumentTemplate $template): RedirectResponse
    {
        $validated = $this->validatePayload($request, $template);
        $documentType = DocumentTypeDefinition::findOrFail($validated['document_type_id']);

        DB::transaction(function () use ($template, $validated, $documentType) {
            $oldSample = $template->only([
                'sample_path', 'sample_original_name', 'sample_mime', 'sample_size',
            ]);
            $template->fill([
                'name' => $validated['name'],
                'doc_type' => $documentType->legacyType()->value,
                'document_type_id' => $documentType->getKey(),
                'paper_size' => $validated['paper_size'],
                'orientation' => $validated['orientation'],
                'custom_width_mm' => $validated['custom_width_mm'],
                'custom_height_mm' => $validated['custom_height_mm'],
                'description' => $validated['description'] ?? null,
                'grouping_mode' => $validated['grouping_mode'],
            ]);

            $before = $template->fields()->count();
            $this->syncFields($template, $validated['fields']);

            $this->audit->saveAndLog(
                'template.updated',
                $template,
                "Updated template '{$template->name}' ({$before} -> ".count($validated['fields'])
                    .' fields, '.$this->personGroupCount($validated['fields']).' person groups).',
            );

            if (($validated['sample_document'] ?? null) instanceof UploadedFile) {
                $template->forceFill(
                    $this->samples->store($template, $validated['sample_document']),
                )->save();

                $this->audit->log(
                    $oldSample['sample_path'] ? 'template.sample-replaced' : 'template.sample-uploaded',
                    $template,
                    old: $oldSample['sample_path'] ? $oldSample : null,
                    new: $template->only([
                        'sample_path', 'sample_original_name', 'sample_mime', 'sample_size',
                    ]),
                    description: "Stored sample document '{$template->sample_original_name}' for template '{$template->name}'.",
                );
            } elseif ($template->sample_path) {
                $relocated = $this->samples->relocate($template);
                if ($relocated !== $template->sample_path) {
                    $template->forceFill(['sample_path' => $relocated])->saveQuietly();
                }
            }

            if ($validated['publish'] ?? false) {
                $this->publishTemplate($template);
            }
        });

        return back()->with(
            'success',
            ($validated['publish'] ?? false)
                ? 'Template saved and published for Staff.'
                : 'Template draft saved.',
        );
    }

    /**
     * Backwards-compatible publishing endpoint used by the template library.
     * Only one template per certificate type is published for Staff.
     */
    public function activate(DocumentTemplate $template): RedirectResponse
    {
        if ($template->fields()->doesntExist()) {
            return back()->with('error', 'Add at least one field before publishing.');
        }

        DB::transaction(fn () => $this->publishTemplate($template));

        return back()->with('success', "'{$template->name}' is now published for {$template->typeLabel()}.");
    }

    public function sample(DocumentTemplate $template): JsonResponse
    {
        $disk = Storage::disk('local');

        abort_unless(
            $template->sample_path && $disk->exists($template->sample_path),
            404,
        );

        // Return preview data as JSON instead of exposing a PDF/image response.
        // This prevents browsers and download-manager extensions from treating
        // an editor preview as a file download.
        return response()->json([
            'name' => $template->sample_original_name ?? basename($template->sample_path),
            'mime' => $template->sample_mime ?? 'application/octet-stream',
            'data' => base64_encode($disk->get($template->sample_path)),
        ], 200, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ], JSON_UNESCAPED_SLASHES);
    }

    public function destroySample(DocumentTemplate $template): RedirectResponse
    {
        if (! $template->sample_path) {
            return back()->with('error', 'This layout does not have a stored sample document.');
        }

        $sample = $template->only([
            'sample_path', 'sample_original_name', 'sample_mime', 'sample_size',
        ]);

        DB::transaction(function () use ($template, $sample) {
            $template->forceFill([
                'sample_path' => null,
                'sample_original_name' => null,
                'sample_mime' => null,
                'sample_size' => null,
            ])->save();

            $this->audit->log(
                'template.sample-deleted',
                $template,
                old: $sample,
                description: "Deleted the stored sample from template '{$template->name}'.",
            );
        });

        $this->samples->deletePath($sample['sample_path']);

        return back()->with('success', 'The stored sample document was deleted.');
    }

    public function destroy(DocumentTemplate $template): RedirectResponse
    {
        $template->loadMissing('documentTypeDefinition');
        $samplePath = $template->sample_path;
        $wasPublished = $template->is_active;
        $recordCount = $template->records()->count();

        DB::transaction(function () use ($template, $wasPublished, $recordCount) {
            $this->audit->log(
                'template.deleted',
                $template,
                old: [
                    'name' => $template->name,
                    'document_type' => $template->documentTypeDefinition?->key ?? $template->doc_type->value,
                    'paper_size' => $template->paper_size->value,
                    'orientation' => $template->orientation->value,
                    'was_published' => $wasPublished,
                    'linked_record_count' => $recordCount,
                    'sample_document' => $template->sample_original_name,
                ],
                description: "Deleted template '{$template->name}'. Existing records retained their captured data.",
            );

            // records.document_template_id uses nullOnDelete. Existing records,
            // scans, and copied field values remain intact after the layout goes.
            $template->delete();
        });

        $this->samples->deletePath($samplePath);

        $message = "Layout '{$template->name}' was deleted.";
        if ($wasPublished) {
            $message .= ' Publish another layout before Staff scan this document type again.';
        }

        return redirect()->route('templates.index')->with('success', $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?DocumentTemplate $template = null): array
    {
        $this->hydrateJsonFields($request);

        if (! $request->has('grouping_mode')) {
            $request->merge([
                'grouping_mode' => $template?->grouping_mode ?: 'auto',
            ]);
        }

        $definition = $request->filled('document_type_id')
            ? DocumentTypeDefinition::find($request->integer('document_type_id'))
            : DocumentTypeDefinition::where('key', (string) $request->input('doc_type'))->first();

        if ($definition) {
            $request->merge([
                'document_type_id' => $definition->getKey(),
                'doc_type' => $definition->legacyType()->value,
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'document_type_id' => [
                'required',
                'integer',
                'exists:document_types,id',
                ...($template ? [Rule::in([$template->document_type_id])] : []),
            ],
            'doc_type' => [
                'required',
                Rule::enum(DocumentType::class),
                ...($template ? [Rule::in([$template->doc_type->value])] : []),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'sample_document' => [
                'nullable',
                File::types(['pdf', 'png', 'jpg', 'jpeg', 'webp', 'bmp', 'tif', 'tiff'])
                    ->max(20 * 1024),
            ],
            'paper_size' => ['required', Rule::enum(PaperSize::class)],
            'orientation' => ['required', Rule::enum(PageOrientation::class)],
            'custom_width_mm' => ['nullable', 'required_if:paper_size,custom', 'numeric', 'min:50', 'max:2000'],
            'custom_height_mm' => ['nullable', 'required_if:paper_size,custom', 'numeric', 'min:50', 'max:2000'],
            'grouping_mode' => ['required', Rule::in(['auto', 'custom'])],
            'publish' => ['sometimes', 'boolean'],
            'fields' => ['required', 'array', 'min:1', 'max:450'],
            'fields.*.name' => ['required', 'string', 'max:500', 'distinct:ignore_case'],
            // Fractions of the page. Bounds keep a box on the paper.
            'fields.*.x' => ['required', 'numeric', 'min:0', 'max:1'],
            'fields.*.y' => ['required', 'numeric', 'min:0', 'max:1'],
            'fields.*.width' => ['required', 'numeric', 'min:0.01', 'max:1'],
            'fields.*.height' => ['required', 'numeric', 'min:0.01', 'max:1'],
            'fields.*.person_group' => [
                Rule::excludeIf(fn () => $request->input('grouping_mode') !== 'custom'),
                'nullable',
                'integer',
                'min:1',
                'max:65535',
            ],
            'fields.*.person_field_order' => [
                Rule::excludeIf(fn () => $request->input('grouping_mode') !== 'custom'),
                'nullable',
                'integer',
                'min:0',
                'max:65535',
            ],
        ]);

        $validated['custom_width_mm'] ??= null;
        $validated['custom_height_mm'] ??= null;

        if ($validated['paper_size'] !== PaperSize::Custom->value) {
            $validated['custom_width_mm'] = null;
            $validated['custom_height_mm'] = null;
        }

        $fieldErrors = [];
        foreach ($validated['fields'] as $index => $field) {
            if ((float) $field['x'] + (float) $field['width'] > 1.00001) {
                $fieldErrors["fields.{$index}.width"] = 'This field marker extends beyond the document width.';
            }

            if ((float) $field['y'] + (float) $field['height'] > 1.00001) {
                $fieldErrors["fields.{$index}.height"] = 'This field marker extends beyond the document height.';
            }

            if ($validated['grouping_mode'] === 'custom') {
                $hasGroup = isset($field['person_group']);
                $hasOrder = isset($field['person_field_order']);

                if ($hasGroup && ! $hasOrder) {
                    $fieldErrors["fields.{$index}.person_field_order"] = 'Choose this field\'s order within its person group.';
                } elseif ($hasOrder && ! $hasGroup) {
                    $fieldErrors["fields.{$index}.person_group"] = 'Choose a person group for this ordered field.';
                }
            }
        }

        if ($fieldErrors !== []) {
            throw ValidationException::withMessages($fieldErrors);
        }

        $validated['fields'] = $this->canonicalizePersonGrouping(
            $validated['fields'],
            $validated['grouping_mode'],
        );

        return $validated;
    }

    private function hydrateJsonFields(Request $request): void
    {
        if (! $request->filled('fields_json')) {
            return;
        }

        try {
            $fields = json_decode((string) $request->input('fields_json'), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages([
                'fields' => 'The field layout could not be read. Refresh the page and try again.',
            ]);
        }

        if (! is_array($fields)) {
            throw ValidationException::withMessages([
                'fields' => 'The field layout must be a valid list of markers.',
            ]);
        }

        $request->merge(['fields' => $fields]);
    }

    /**
     * Publish the layout Staff receive, retiring the previous one atomically.
     * The row lock prevents two simultaneous publishes from leaving two active
     * layouts for the same certificate type.
     */
    private function publishTemplate(DocumentTemplate $template): void
    {
        $sameType = DocumentTemplate::query()
            ->where('document_type_id', $template->document_type_id)
            ->lockForUpdate()
            ->get();

        $previous = $sameType
            ->where('is_active', true)
            ->where('id', '!=', $template->getKey());

        DocumentTemplate::query()
            ->whereIn('id', $sameType->modelKeys())
            ->whereKeyNot($template->getKey())
            ->update(['is_active' => false]);

        // Always write the target row. Its in-memory state may be stale if
        // another publish was waiting on the same lock.
        $template->forceFill(['is_active' => true])->save();

        $this->audit->log(
            'template.activated',
            $template,
            old: ['previous_active' => $previous->pluck('name')->values()->all()],
            new: [
                'active' => $template->name,
                'paper_size' => $template->paper_size->value,
                'orientation' => $template->orientation->value,
            ],
            description: "Published template '{$template->name}' for {$template->typeLabel()}.",
        );
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     */
    private function syncFields(DocumentTemplate $template, array $fields): void
    {
        // Replaced wholesale: field identity is positional, and records keep their
        // own copy of the box they were cropped with.
        $template->fields()->delete();

        foreach (array_values($fields) as $index => $field) {
            $template->fields()->create([
                'name' => $field['name'],
                'x' => $field['x'],
                'y' => $field['y'],
                'width' => $field['width'],
                'height' => $field['height'],
                'sort_order' => $index,
                'is_required' => true,
                'person_group' => $field['person_group'],
                'person_field_order' => $field['person_field_order'],
            ]);
        }
    }

    /**
     * Group numbers and field positions are presentation order, not permanent
     * identifiers. Closing gaps here keeps every saved layout deterministic even
     * after a person or one of their fields is removed in the builder.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private function canonicalizePersonGrouping(array $fields, string $mode): array
    {
        $fields = array_values($fields);

        foreach ($fields as &$field) {
            $field['person_group'] = $mode === 'custom'
                ? ($field['person_group'] ?? null)
                : null;
            $field['person_field_order'] = $mode === 'custom'
                ? ($field['person_field_order'] ?? null)
                : null;
        }
        unset($field);

        if ($mode !== 'custom') {
            return $fields;
        }

        $membersByGroup = [];
        foreach ($fields as $index => $field) {
            if ($field['person_group'] === null) {
                continue;
            }

            $group = (int) $field['person_group'];
            $membersByGroup[$group][] = [
                'field_index' => $index,
                'requested_order' => (int) $field['person_field_order'],
            ];
        }

        ksort($membersByGroup, SORT_NUMERIC);

        $canonicalGroup = 1;
        foreach ($membersByGroup as $members) {
            usort($members, fn (array $left, array $right): int => $left['requested_order'] <=> $right['requested_order']
                    ?: $left['field_index'] <=> $right['field_index']);

            foreach ($members as $canonicalOrder => $member) {
                $fields[$member['field_index']]['person_group'] = $canonicalGroup;
                $fields[$member['field_index']]['person_field_order'] = $canonicalOrder;
            }

            $canonicalGroup++;
        }

        return $fields;
    }

    /** @param list<array<string, mixed>> $fields */
    private function personGroupCount(array $fields): int
    {
        return count(array_unique(array_filter(
            array_column($fields, 'person_group'),
            fn ($group): bool => $group !== null,
        )));
    }
}
