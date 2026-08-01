<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Models\DocumentTemplate;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
            'documentTypes' => DocumentType::cases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePayload($request);

        $template = DB::transaction(function () use ($validated, $request) {
            $template = DocumentTemplate::create([
                'name' => $validated['name'],
                'doc_type' => $validated['doc_type'],
                'description' => $validated['description'] ?? null,
                'is_active' => false,
                'created_by' => $request->user()->getKey(),
            ]);

            $this->syncFields($template, $validated['fields']);

            $this->audit->log(
                'template.created',
                $template,
                new: ['name' => $template->name, 'doc_type' => $validated['doc_type'],
                    'field_count' => count($validated['fields'])],
                description: "Created template '{$template->name}'.",
            );

            return $template;
        });

        return redirect()
            ->route('templates.edit', $template)
            ->with('success', 'Template created. Activate it to make it available to Staff.');
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
            'documentTypes' => DocumentType::cases(),
        ]);
    }

    public function update(Request $request, DocumentTemplate $template): RedirectResponse
    {
        $validated = $this->validatePayload($request);

        DB::transaction(function () use ($template, $validated) {
            $template->fill([
                'name' => $validated['name'],
                'doc_type' => $validated['doc_type'],
                'description' => $validated['description'] ?? null,
            ]);

            $before = $template->fields()->count();
            $this->syncFields($template, $validated['fields']);

            $this->audit->saveAndLog(
                'template.updated',
                $template,
                "Updated template '{$template->name}' ({$before} -> ".count($validated['fields']).' fields).',
            );
        });

        return back()->with('success', 'Template saved.');
    }

    /**
     * Make this the template Staff receive for its document type. Only one per
     * type is active, so activating implicitly retires the previous one.
     */
    public function activate(DocumentTemplate $template): RedirectResponse
    {
        if ($template->fields()->doesntExist()) {
            return back()->with('error', 'Add at least one field before activating.');
        }

        DB::transaction(function () use ($template) {
            $previous = DocumentTemplate::where('doc_type', $template->doc_type->value)
                ->where('is_active', true)
                ->whereKeyNot($template->getKey())
                ->get();

            DocumentTemplate::whereIn('id', $previous->modelKeys())
                ->update(['is_active' => false]);

            $template->forceFill(['is_active' => true])->save();

            $this->audit->log(
                'template.activated',
                $template,
                old: ['previous_active' => $previous->pluck('name')->all()],
                new: ['active' => $template->name],
                description: "Activated template '{$template->name}' for {$template->doc_type->label()}.",
            );
        });

        return back()->with('success', "'{$template->name}' is now active for {$template->doc_type->label()}.");
    }

    /**
     * Templates already used by records are kept: deleting one would orphan the
     * layout that produced those crops.
     */
    public function destroy(DocumentTemplate $template): RedirectResponse
    {
        if ($template->records()->exists()) {
            return back()->with(
                'error',
                'This template has been used by existing records and cannot be deleted. Deactivate it instead.',
            );
        }

        $this->audit->log(
            'template.deleted',
            $template,
            old: ['name' => $template->name, 'doc_type' => $template->doc_type->value],
            description: "Deleted template '{$template->name}'.",
        );

        $template->delete();

        return redirect()->route('templates.index')->with('success', 'Template deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'doc_type' => ['required', Rule::enum(DocumentType::class)],
            'description' => ['nullable', 'string', 'max:1000'],
            'fields' => ['required', 'array', 'min:1'],
            'fields.*.name' => ['required', 'string', 'max:120'],
            // Fractions of the page. Bounds keep a box on the paper.
            'fields.*.x' => ['required', 'numeric', 'min:0', 'max:1'],
            'fields.*.y' => ['required', 'numeric', 'min:0', 'max:1'],
            'fields.*.width' => ['required', 'numeric', 'min:0.01', 'max:1'],
            'fields.*.height' => ['required', 'numeric', 'min:0.01', 'max:1'],
        ]);
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
