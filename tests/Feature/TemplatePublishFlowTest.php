<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\DocumentTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * From the Template Builder to Staff's workspace (review of 2026-11-11):
 *
 * - each layout has its own page 2: a copy or a new version never shares it;
 * - page 2 layouts are not listed, published, edited or deleted by themselves;
 * - a published layout is unpublished before it is deleted;
 * - publishing needs a sample, real column names and a Name column;
 * - a type may have several published layouts, and Staff choose one.
 */
class TemplatePublishFlowTest extends TestCase
{
    use RefreshDatabase;

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
            'page_ratio' => 11 / 8.5,
            'grouping_mode' => 'auto',
            'fields_json' => '[]',
            'columns_json' => json_encode([
                ['name' => 'Entry No.', 'box' => [0.05, 0.2, 0.1, 0.6], 'role' => 'entry'],
                ['name' => "Child's Name", 'box' => [0.15, 0.2, 0.3, 0.6], 'role' => 'name'],
            ], JSON_THROW_ON_ERROR),
            'ruled_ys_json' => json_encode([0.2, 0.35, 0.5, 0.65, 0.8], JSON_THROW_ON_ERROR),
            'sample_document' => UploadedFile::fake()->image('register.png', 1200, 900),
            ...$overrides,
        ];
    }

    /** Page 2 as the builder sends it with a two-page layout. */
    private function pageTwo(string $column = 'Informant'): string
    {
        return json_encode([
            'fields' => [],
            'columns' => [['name' => $column, 'box' => [0.1, 0.2, 0.5, 0.6]]],
            'ruled_ys' => [0.2, 0.35, 0.5, 0.65, 0.8],
            'grouping_mode' => 'auto',
        ], JSON_THROW_ON_ERROR);
    }

    /** @param  array<string, mixed>  $overrides */
    private function layout(array $overrides = []): DocumentTemplate
    {
        $this->actingAs($this->admin)->post(route('templates.store'), $this->payload($overrides))
            ->assertSessionHasNoErrors();

        return DocumentTemplate::listed()->latest('id')->firstOrFail();
    }

    /** @return list<string> */
    private function pageTwoColumns(DocumentTemplate $template): array
    {
        return array_column($template->refresh()->pageTwoTemplate->columns, 'name');
    }

    // ------------------------------------------------------------ 1. own page 2

    public function test_a_duplicate_gets_its_own_page_two(): void
    {
        $original = $this->layout(['page_two_json' => $this->pageTwo(), 'publish' => 1]);
        $this->assertTrue($original->is_active);
        $this->assertTrue($original->pageTwoTemplate->is_page_two);

        $this->actingAs($this->admin)->post(route('templates.duplicate', $original))->assertRedirect();
        $copy = DocumentTemplate::listed()->latest('id')->firstOrFail();

        $this->assertNotSame($original->page_two_template_id, $copy->page_two_template_id);
        $this->assertTrue($copy->pageTwoTemplate->is_page_two);
        $this->assertSame(['Informant'], $this->pageTwoColumns($copy));
        $this->assertFalse($copy->is_version);

        // Changing the copy's page 2 leaves the published original's alone.
        $this->actingAs($this->admin)->put(route('templates.update', $copy), $this->payload([
            'name' => $copy->name,
            'page_two_json' => $this->pageTwo('Mother'),
            'revision' => $copy->revision,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(['Mother'], $this->pageTwoColumns($copy));
        $this->assertSame(['Informant'], $this->pageTwoColumns($original));
    }

    public function test_a_new_version_gets_its_own_page_two_and_the_old_one_keeps_its(): void
    {
        $published = $this->layout(['page_two_json' => $this->pageTwo(), 'publish' => 1]);
        $oldPageTwo = $published->page_two_template_id;

        // Only page 2 changes: the layout in use is versioned.
        $this->actingAs($this->admin)->put(route('templates.update', $published), $this->payload([
            'page_two_json' => $this->pageTwo('Mother'),
            'revision' => $published->revision,
        ]))->assertSessionHasNoErrors();

        $version = DocumentTemplate::listed()->latest('id')->firstOrFail();
        $this->assertTrue($version->is_version);
        $this->assertNotSame($oldPageTwo, $version->page_two_template_id);
        $this->assertSame(['Mother'], $this->pageTwoColumns($version));
        $this->assertSame($oldPageTwo, $published->refresh()->page_two_template_id);
        $this->assertSame(['Informant'], $this->pageTwoColumns($published));
    }

    public function test_a_version_saved_without_page_two_changes_copies_it(): void
    {
        $published = $this->layout(['page_two_json' => $this->pageTwo(), 'publish' => 1]);

        // Column 1 moves; page 2 is sent as it was.
        $this->actingAs($this->admin)->put(route('templates.update', $published), $this->payload([
            'columns_json' => json_encode([
                ['name' => 'Entry No.', 'box' => [0.06, 0.2, 0.1, 0.6], 'role' => 'entry'],
                ['name' => "Child's Name", 'box' => [0.16, 0.2, 0.3, 0.6], 'role' => 'name'],
            ], JSON_THROW_ON_ERROR),
            'page_two_json' => $this->pageTwo(),
            'revision' => $published->revision,
        ]))->assertSessionHasNoErrors();

        $version = DocumentTemplate::listed()->latest('id')->firstOrFail();
        $this->assertNotSame($published->page_two_template_id, $version->page_two_template_id);
        $this->assertSame(['Informant'], $this->pageTwoColumns($version));
    }

    public function test_a_draft_changes_its_own_page_two_in_place_and_can_drop_it(): void
    {
        $draft = $this->layout(['page_two_json' => $this->pageTwo()]);
        $pageTwo = $draft->page_two_template_id;

        $this->actingAs($this->admin)->put(route('templates.update', $draft), $this->payload([
            'page_two_json' => $this->pageTwo('Mother'),
            'revision' => $draft->revision,
        ]))->assertSessionHasNoErrors();
        $this->assertSame($pageTwo, $draft->refresh()->page_two_template_id);
        $this->assertSame(['Mother'], $this->pageTwoColumns($draft));

        // Two pages turned off: page 2 goes.
        $this->actingAs($this->admin)->put(route('templates.update', $draft), $this->payload([
            'page_two_json' => json_encode(['fields' => [], 'columns' => [], 'ruled_ys' => [], 'grouping_mode' => 'auto']),
            'revision' => $draft->revision,
        ]))->assertSessionHasNoErrors();
        $this->assertNull($draft->refresh()->page_two_template_id);
        $this->assertDatabaseMissing('document_templates', ['id' => $pageTwo]);
    }

    public function test_deleting_a_layout_deletes_its_page_two(): void
    {
        $draft = $this->layout(['page_two_json' => $this->pageTwo()]);
        $pageTwo = $draft->page_two_template_id;

        $this->actingAs($this->admin)->delete(route('templates.destroy', $draft))->assertSessionHas('success');

        $this->assertDatabaseMissing('document_templates', ['id' => $pageTwo]);
    }

    // ------------------------------------------------------- 2. page 2 is hidden

    public function test_page_two_layouts_are_not_listed_or_handled_by_themselves(): void
    {
        $main = $this->layout(['page_two_json' => $this->pageTwo(), 'publish' => 1]);
        $pageTwo = $main->pageTwoTemplate;

        $this->actingAs($this->admin)->get(route('templates.index'))
            ->assertOk()
            ->assertSee('Two pages: page 2 is changed inside this layout')
            ->assertDontSee(route('templates.edit', $pageTwo).'"', escape: false)
            ->assertDontSee(route('templates.activate', $pageTwo), escape: false);

        foreach ([
            fn () => $this->get(route('templates.edit', $pageTwo)),
            fn () => $this->post(route('templates.activate', $pageTwo)),
            fn () => $this->post(route('templates.duplicate', $pageTwo)),
            fn () => $this->delete(route('templates.destroy', $pageTwo)),
        ] as $action) {
            $action()->assertRedirect(route('templates.edit', $main))
                ->assertSessionHas('error', fn (string $message) => str_contains($message, 'page 2 of a two-page layout'));
        }

        $this->assertDatabaseHas('document_templates', ['id' => $pageTwo->getKey(), 'is_active' => false]);
        $this->assertSame(1, DocumentTemplate::where('is_page_two', true)->count());

        // Not offered as another layout's page 2 either.
        $this->actingAs($this->admin)->get(route('templates.create', ['type' => 'birth']))
            ->assertOk()
            ->assertDontSee('value="'.$pageTwo->getKey().'"', escape: false);
    }

    // ------------------------------------------------------- 4-7. publish checks

    public function test_a_layout_is_not_published_without_a_sample_real_names_and_a_name_column(): void
    {
        $this->actingAs($this->admin)->post(route('templates.store'), $this->payload([
            'columns_json' => json_encode([
                ['name' => 'Column 1', 'box' => [0.05, 0.2, 0.1, 0.6]],
                ['name' => 'Column 2', 'box' => [0.15, 0.2, 0.3, 0.6]],
            ], JSON_THROW_ON_ERROR),
            'sample_document' => null,
            'publish' => 1,
        ]))->assertSessionHasNoErrors()->assertSessionHas('error', function (string $message) {
            return str_contains($message, 'Template saved as a draft. It was not published')
                && str_contains($message, 'Add a sample page')
                && str_contains($message, 'still named: Column 1, Column 2')
                && str_contains($message, "holds the person's name");
        });

        $draft = DocumentTemplate::firstOrFail();
        $this->assertFalse($draft->is_active, 'saved, but as a draft');

        // The library's Publish window lists the same, and so does the server.
        $this->get(route('templates.index'))
            ->assertOk()
            ->assertSee('is not ready yet')
            ->assertSee('still named: Column 1, Column 2');
        $this->post(route('templates.activate', $draft))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'was not published'));
        $this->assertFalse($draft->refresh()->is_active);
    }

    public function test_the_name_column_may_be_on_page_two(): void
    {
        $layout = $this->layout([
            'columns_json' => json_encode([['name' => 'Entry No.', 'box' => [0.05, 0.2, 0.1, 0.6]]], JSON_THROW_ON_ERROR),
            'page_two_json' => json_encode([
                'fields' => [],
                'columns' => [['name' => 'Mother', 'box' => [0.1, 0.2, 0.5, 0.6], 'role' => 'name']],
                'ruled_ys' => [0.2, 0.35, 0.5, 0.65, 0.8],
                'grouping_mode' => 'auto',
            ], JSON_THROW_ON_ERROR),
            'publish' => 1,
        ]);

        $this->assertTrue($layout->is_active);
    }

    public function test_saving_a_published_layout_with_publish_asked_does_not_unpublish_it(): void
    {
        $published = $this->layout(['publish' => 1]);
        // Its sample was deleted later; renaming it must not say "not published".
        $published->forceFill(['sample_path' => null])->save();

        $this->actingAs($this->admin)->put(route('templates.update', $published), $this->payload([
            'name' => 'Birth register (1970s)',
            'sample_document' => null,
            'publish' => 1,
            'revision' => $published->revision,
        ]))->assertSessionHas('success');

        $this->assertTrue($published->refresh()->is_active);
    }

    // ------------------------------------------- 11. several published layouts

    public function test_publishing_a_new_version_replaces_the_version_it_came_from_only(): void
    {
        $older = $this->layout(['name' => '1970s books', 'publish' => 1]);
        $other = $this->layout(['name' => '1990s books', 'publish' => 1]);

        $this->actingAs($this->admin)->put(route('templates.update', $older), $this->payload([
            'name' => '1970s books',
            'columns_json' => json_encode([
                ['name' => 'Entry No.', 'box' => [0.06, 0.2, 0.1, 0.6]],
                ['name' => "Child's Name", 'box' => [0.16, 0.2, 0.3, 0.6], 'role' => 'name'],
            ], JSON_THROW_ON_ERROR),
            'publish' => 1,
        ]))->assertSessionHas('success');

        $version = DocumentTemplate::listed()->latest('id')->firstOrFail();
        $this->assertTrue($version->is_active);
        $this->assertFalse($older->refresh()->is_active, 'its older version is replaced');
        $this->assertTrue($other->refresh()->is_active, 'another book layout stays');
        $this->assertSame(
            [$version->getKey(), $other->getKey()],
            DocumentTemplate::publishedFor(DocumentType::Birth)->modelKeys(),
        );
    }

    public function test_publishing_a_duplicate_keeps_the_original_published(): void
    {
        $original = $this->layout(['publish' => 1]);
        $this->actingAs($this->admin)->post(route('templates.duplicate', $original));
        $copy = DocumentTemplate::listed()->latest('id')->firstOrFail();

        $this->post(route('templates.activate', $copy))->assertSessionHas('success');

        $this->assertTrue($copy->refresh()->is_active);
        $this->assertTrue($original->refresh()->is_active);
    }

    public function test_staff_choose_between_published_layouts_and_the_choice_is_remembered(): void
    {
        $seventies = $this->layout(['name' => '1970s books', 'publish' => 1]);
        $nineties = $this->layout(['name' => '1990s books', 'publish' => 1]);
        $staff = User::factory()->staff()->create();
        $workspace = fn (array $query = []) => $this->actingAs($staff)
            ->get(route('documents.workspace', ['type' => 'birth', ...$query]));

        // The newest published first, both offered.
        $workspace()->assertOk()
            ->assertSee('Pick the layout printed like your book')
            ->assertSee('data-layout-choice="'.$seventies->getKey().'"', escape: false)
            ->assertSee('templateId: '.$nineties->getKey().',', escape: false);

        $workspace(['template' => $seventies->getKey()])->assertOk()
            ->assertSee('templateId: '.$seventies->getKey().',', escape: false);

        // Remembered the next time this type is opened.
        $workspace()->assertOk()
            ->assertSee('templateId: '.$seventies->getKey().',', escape: false);

        // A layout that is not published for the type is not offered.
        $this->actingAs($this->admin)->post(route('templates.deactivate', $seventies))->assertSessionHas('success');
        $workspace(['template' => $seventies->getKey()])->assertOk()
            ->assertSee('templateId: '.$nineties->getKey().',', escape: false)
            ->assertDontSee('Pick the layout printed like your book');

        // The New Document type list says how many layouts there are.
        $this->actingAs($this->admin)->post(route('templates.activate', $seventies));
        // Someone who has not scanned yet (no last type in the session) sees the list.
        $this->flushSession();
        $this->actingAs(User::factory()->staff()->create())->get(route('documents.create'))
            ->assertOk()
            ->assertSee('2 layouts');
    }

    public function test_unpublishing_the_last_layout_says_staff_cannot_scan_the_type(): void
    {
        $only = $this->layout(['publish' => 1]);

        $this->actingAs($this->admin)->post(route('templates.deactivate', $only))
            ->assertSessionHas('success', fn (string $message) => str_contains($message, "Staff can't scan"));

        $this->actingAs(User::factory()->staff()->create())
            ->get(route('documents.workspace', ['type' => 'birth']))
            ->assertRedirect(route('documents.create'));
    }
}
