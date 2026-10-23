<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\CivilRecord;
use App\Models\DocumentTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A layout in use is never changed in place: new markers make a new version, so
 * every record keeps the layout it was read with. Drafts are edited in place,
 * and a save from an older copy of the editor cannot overwrite a newer one.
 */
class TemplateVersioningTest extends TestCase
{
    use RefreshDatabase;

    private const COLUMNS = [
        ['name' => 'Entry No.', 'box' => [0.05, 0.2, 0.1, 0.6]],
        ['name' => "Child's Name", 'box' => [0.15, 0.2, 0.3, 0.6]],
    ];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->superAdmin()->create();
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Birth register',
            'doc_type' => DocumentType::Birth->value,
            'paper_size' => 'letter',
            'orientation' => 'landscape',
            'grouping_mode' => 'auto',
            'fields_json' => json_encode([
                ['name' => 'Page number', 'x' => 0.6, 'y' => 0.05, 'width' => 0.1, 'height' => 0.05],
            ], JSON_THROW_ON_ERROR),
            'columns_json' => json_encode(self::COLUMNS, JSON_THROW_ON_ERROR),
            'ruled_ys_json' => json_encode([0.2, 0.35, 0.5, 0.65, 0.8], JSON_THROW_ON_ERROR),
            ...$overrides,
        ];
    }

    /** The same ledger with its second column moved to the right. */
    private function movedColumns(): string
    {
        return json_encode([
            self::COLUMNS[0],
            ['name' => "Child's Name", 'box' => [0.2, 0.2, 0.3, 0.6]],
        ], JSON_THROW_ON_ERROR);
    }

    /** @param  array<string, mixed>  $overrides */
    private function layout(array $overrides = []): DocumentTemplate
    {
        $this->actingAs($this->admin)->post(route('templates.store'), $this->payload($overrides))
            ->assertSessionHasNoErrors();

        return DocumentTemplate::latest('id')->firstOrFail();
    }

    public function test_new_markers_on_a_published_layout_are_saved_as_a_new_draft_version(): void
    {
        $published = $this->layout(['publish' => 1]);

        $response = $this->actingAs($this->admin)->put(route('templates.update', $published), $this->payload([
            'columns_json' => $this->movedColumns(),
            'revision' => $published->revision,
        ]));

        $version = DocumentTemplate::latest('id')->firstOrFail();
        $response->assertRedirect(route('templates.edit', $version))->assertSessionHas('success');

        $this->assertNotSame($published->getKey(), $version->getKey());
        $this->assertSame($published->getKey(), $version->parent_id);
        $this->assertFalse($version->is_active);
        $this->assertSame(0.2, $version->columns[1]['box'][0]);
        $this->assertSame(1, $version->fields()->count());

        // The published layout is exactly as it was, and still published.
        $published->refresh();
        $this->assertTrue($published->is_active);
        $this->assertSame(0.15, $published->columns[1]['box'][0]);
        $this->assertSame(1, $published->revision);
        $this->assertDatabaseHas('audit_logs', ['action' => 'template.versioned']);
    }

    public function test_publishing_a_new_version_keeps_the_old_one_for_its_records(): void
    {
        $published = $this->layout(['publish' => 1]);
        $record = CivilRecord::factory()->create(['document_template_id' => $published->getKey()]);

        $this->actingAs($this->admin)->put(route('templates.update', $published), $this->payload([
            'columns_json' => $this->movedColumns(),
            'publish' => 1,
        ]))->assertSessionHas('success', fn (string $message) => str_contains($message, 'kept unchanged for the 1 record'));

        $version = DocumentTemplate::latest('id')->firstOrFail();
        $this->assertTrue($version->is_active);
        $this->assertFalse($published->refresh()->is_active);
        $this->assertSame(0.15, $published->columns[1]['box'][0]);
        $this->assertSame($published->getKey(), $record->refresh()->document_template_id);
        $this->assertTrue($version->is(DocumentTemplate::activeFor(DocumentType::Birth)));
    }

    public function test_a_retired_layout_that_records_were_read_with_is_still_in_use(): void
    {
        $retired = $this->layout();
        CivilRecord::factory()->create(['document_template_id' => $retired->getKey()]);

        $this->actingAs($this->admin)->put(route('templates.update', $retired), $this->payload([
            'columns_json' => $this->movedColumns(),
        ]))->assertRedirect();

        $this->assertSame(2, DocumentTemplate::count());
        $this->assertSame(0.15, $retired->refresh()->columns[1]['box'][0]);
    }

    public function test_renaming_a_layout_in_use_is_saved_in_place(): void
    {
        $published = $this->layout(['publish' => 1]);

        $this->actingAs($this->admin)->put(route('templates.update', $published), $this->payload([
            'name' => 'Birth register (1950s)',
            'description' => 'Printed by the provincial office.',
            'revision' => 1,
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, DocumentTemplate::count());
        $published->refresh();
        $this->assertSame('Birth register (1950s)', $published->name);
        $this->assertTrue($published->is_active);
        $this->assertSame(2, $published->revision);
    }

    public function test_a_draft_nobody_used_is_changed_in_place(): void
    {
        $draft = $this->layout();

        $this->actingAs($this->admin)->put(route('templates.update', $draft), $this->payload([
            'columns_json' => $this->movedColumns(),
            'revision' => 1,
        ]))->assertRedirect()->assertSessionHas('success', 'Template draft saved.');

        $this->assertSame(1, DocumentTemplate::count());
        $draft->refresh();
        $this->assertSame(0.2, $draft->columns[1]['box'][0]);
        $this->assertSame(2, $draft->revision);
    }

    public function test_a_save_from_an_older_copy_of_the_editor_is_refused(): void
    {
        $draft = $this->layout();

        // One admin saves...
        $this->actingAs($this->admin)->put(route('templates.update', $draft), $this->payload([
            'name' => 'Saved first',
            'revision' => 1,
        ]))->assertSessionHasNoErrors();

        // ...then another, whose editor opened revision 1 as well.
        $this->actingAs(User::factory()->superAdmin()->create())->put(route('templates.update', $draft), $this->payload([
            'name' => 'Saved second',
            'columns_json' => $this->movedColumns(),
            'revision' => 1,
        ]))->assertSessionHasErrors('revision');

        $draft->refresh();
        $this->assertSame('Saved first', $draft->name);
        $this->assertSame(0.15, $draft->columns[1]['box'][0]);
        $this->assertSame(2, $draft->revision);
    }

    public function test_the_editor_says_when_a_layout_is_in_use(): void
    {
        $published = $this->layout(['publish' => 1]);

        $this->actingAs($this->admin)->get(route('templates.edit', $published))
            ->assertOk()
            ->assertSee('This layout is in use:')
            ->assertSee('Save &amp; publish new version', escape: false)
            ->assertSee('name="revision" value="1"', escape: false);

        $draft = $this->layout(['name' => 'Unused draft']);
        $this->actingAs($this->admin)->get(route('templates.edit', $draft))
            ->assertOk()
            ->assertDontSee('This layout is in use:')
            ->assertSee('Save draft');
    }

    public function test_a_duplicate_is_a_draft_copy_with_its_own_sample(): void
    {
        $original = $this->layout([
            'publish' => 1,
            'sample_document' => UploadedFile::fake()->image('register.png', 1200, 900),
        ]);

        $this->actingAs($this->admin)->post(route('templates.duplicate', $original))
            ->assertRedirect()
            ->assertSessionHas('success');

        $copy = DocumentTemplate::latest('id')->firstOrFail();
        $this->assertSame('Birth register (copy)', $copy->name);
        $this->assertFalse($copy->is_active);
        $this->assertSame($original->getKey(), $copy->parent_id);
        $this->assertSame($original->columns, $copy->columns);
        $this->assertSame($original->ruled_ys, $copy->ruled_ys);
        $this->assertSame(['Page number'], $copy->fields()->pluck('name')->all());
        $this->assertNotSame($original->sample_path, $copy->sample_path);
        Storage::disk('local')->assertExists([$original->sample_path, $copy->sample_path]);
        $this->assertTrue($original->refresh()->is_active);

        $this->actingAs($this->admin)->post(route('templates.duplicate', $original));
        $this->assertSame('Birth register (copy 2)', DocumentTemplate::latest('id')->value('name'));
    }

    public function test_a_new_version_keeps_a_copy_of_the_sample(): void
    {
        $published = $this->layout([
            'publish' => 1,
            'sample_document' => UploadedFile::fake()->image('register.png', 1200, 900),
        ]);

        $this->actingAs($this->admin)->put(route('templates.update', $published), $this->payload([
            'columns_json' => $this->movedColumns(),
        ]));

        $version = DocumentTemplate::latest('id')->firstOrFail();
        $this->assertNotNull($version->sample_path);
        $this->assertNotSame($published->refresh()->sample_path, $version->sample_path);
        Storage::disk('local')->assertExists([$published->sample_path, $version->sample_path]);
    }

    public function test_only_super_admins_duplicate_layouts(): void
    {
        $layout = $this->layout();

        $this->actingAs(User::factory()->staff()->create())
            ->post(route('templates.duplicate', $layout))
            ->assertForbidden();
        $this->assertSame(1, DocumentTemplate::count());
    }
}
