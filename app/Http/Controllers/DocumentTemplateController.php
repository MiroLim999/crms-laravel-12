<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Enums\PageOrientation;
use App\Enums\PaperSize;
use App\Models\DocumentTemplate;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        return view('templates.index', [
            'templates' => DocumentTemplate::withCount(['fields', 'records'])
                ->with('creator')
                ->orderBy('doc_type')
                ->orderByDesc('is_active')
                ->get()
                ->groupBy(fn (DocumentTemplate $t) => $t->doc_type->value),
            'documentTypes' => DocumentType::cases(),
        ]);
    }

    public function create(Request $request): View
    {
        $type = DocumentType::tryFrom((string) $request->query('type')) ?? DocumentType::Birth;

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

        $template = DB::transaction(function () use ($validated, $request) {
            $template = DocumentTemplate::create([
                'name' => $validated['name'],
                'doc_type' => $validated['doc_type'],
                'paper_size' => $validated['paper_size'],
                'orientation' => $validated['orientation'],
                'custom_width_mm' => $validated['custom_width_mm'],
                'custom_height_mm' => $validated['custom_height_mm'],
                'description' => $validated['description'] ?? null,
                'is_active' => false,
                'created_by' => $request->user()->getKey(),
            ]);

            $this->syncFields($template, $validated['fields']);

            $this->audit->log(
                'template.created',
                $template,
                new: ['name' => $template->name, 'doc_type' => $validated['doc_type'],
                    'paper_size' => $validated['paper_size'], 'orientation' => $validated['orientation'],
                    'custom_width_mm' => $validated['custom_width_mm'],
                    'custom_height_mm' => $validated['custom_height_mm'],
                    'field_count' => count($validated['fields'])],
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
        $template->load('fields');

        return view('templates.edit', [
            'template' => $template,
            'docType' => $template->doc_type,
            'fields' => $template->fields->map(fn ($f) => [
                'name' => $f->name,
                'x' => $f->x,
                'y' => $f->y,
                'width' => $f->width,
                'height' => $f->height,
            ])->all(),
            'paperSizes' => PaperSize::cases(),
            'orientations' => PageOrientation::cases(),
        ]);
    }

    public function update(Request $request, DocumentTemplate $template): RedirectResponse
    {
        $validated = $this->validatePayload($request, $template);

        DB::transaction(function () use ($template, $validated) {
            $template->fill([
                'name' => $validated['name'],
                'doc_type' => $validated['doc_type'],
                'paper_size' => $validated['paper_size'],
                'orientation' => $validated['orientation'],
                'custom_width_mm' => $validated['custom_width_mm'],
                'custom_height_mm' => $validated['custom_height_mm'],
                'description' => $validated['description'] ?? null,
            ]);

            $before = $template->fields()->count();
            $this->syncFields($template, $validated['fields']);

            $this->audit->saveAndLog(
                'template.updated',
                $template,
                "Updated template '{$template->name}' ({$before} -> ".count($validated['fields']).' fields).',
            );

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

        return back()->with('success', "'{$template->name}' is now published for {$template->doc_type->label()}.");
    }

    /**
     * Templates already used by records are kept: deleting one would orphan the
     * layout that produced those crops.
     */
    public function destroy(DocumentTemplate $template): RedirectResponse
    {
        if ($template->is_active) {
            return back()->with(
                'error',
                'A published template cannot be deleted. Publish another layout for this certificate type first.',
            );
        }

        if ($template->records()->exists()) {
            return back()->with(
                'error',
                'This template has been used by existing records and cannot be deleted.',
            );
        }

        $this->audit->log(
            'template.deleted',
            $template,
            old: [
                'name' => $template->name,
                'doc_type' => $template->doc_type->value,
                'paper_size' => $template->paper_size->value,
                'orientation' => $template->orientation->value,
            ],
            description: "Deleted template '{$template->name}'.",
        );

        $template->delete();

        return redirect()->route('templates.index')->with('success', 'Template deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?DocumentTemplate $template = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'doc_type' => [
                'required',
                Rule::enum(DocumentType::class),
                ...($template ? [Rule::in([$template->doc_type->value])] : []),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'paper_size' => ['required', Rule::enum(PaperSize::class)],
            'orientation' => ['required', Rule::enum(PageOrientation::class)],
            'custom_width_mm' => ['nullable', 'required_if:paper_size,custom', 'numeric', 'min:50', 'max:2000'],
            'custom_height_mm' => ['nullable', 'required_if:paper_size,custom', 'numeric', 'min:50', 'max:2000'],
            'publish' => ['sometimes', 'boolean'],
            'fields' => ['required', 'array', 'min:1', 'max:100'],
            'fields.*.name' => ['required', 'string', 'max:120', 'distinct:ignore_case'],
            // Fractions of the page. Bounds keep a box on the paper.
            'fields.*.x' => ['required', 'numeric', 'min:0', 'max:1'],
            'fields.*.y' => ['required', 'numeric', 'min:0', 'max:1'],
            'fields.*.width' => ['required', 'numeric', 'min:0.01', 'max:1'],
            'fields.*.height' => ['required', 'numeric', 'min:0.01', 'max:1'],
        ]);

        $validated['custom_width_mm'] ??= null;
        $validated['custom_height_mm'] ??= null;

        if ($validated['paper_size'] !== PaperSize::Custom->value) {
            $validated['custom_width_mm'] = null;
            $validated['custom_height_mm'] = null;
        }

        $coordinateErrors = [];
        foreach ($validated['fields'] as $index => $field) {
            if ((float) $field['x'] + (float) $field['width'] > 1.00001) {
                $coordinateErrors["fields.{$index}.width"] = 'This field marker extends beyond the document width.';
            }

            if ((float) $field['y'] + (float) $field['height'] > 1.00001) {
                $coordinateErrors["fields.{$index}.height"] = 'This field marker extends beyond the document height.';
            }
        }

        if ($coordinateErrors !== []) {
            throw ValidationException::withMessages($coordinateErrors);
        }

        return $validated;
    }

    /**
     * Publish the layout Staff receive, retiring the previous one atomically.
     * The row lock prevents two simultaneous publishes from leaving two active
     * layouts for the same certificate type.
     */
    private function publishTemplate(DocumentTemplate $template): void
    {
        $sameType = DocumentTemplate::query()
            ->where('doc_type', $template->doc_type->value)
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
            description: "Published template '{$template->name}' for {$template->doc_type->label()}.",
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
            ]);
        }
    }
}
