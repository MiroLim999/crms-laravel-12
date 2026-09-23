<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\RecordStatus;
use App\Models\AuditLog;
use App\Models\CivilRecord;
use App\Models\DocumentPage;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Services\Lines\LineMarkers;
use Database\Seeders\DocumentTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Ledger templates (columns + ruled row lines), grid detection, the training
 * export and pruning of unsubmitted pages.
 */
class LedgerTemplateAndExportTest extends TestCase
{
    use RefreshDatabase;

    private const COLUMNS = [
        ['name' => "Child's Name", 'box' => [0.1, 0.2, 0.3, 0.6]],
        ['name' => 'Date of Birth', 'box' => [0.4, 0.2, 0.2, 0.6]],
    ];

    public function test_a_ledger_template_stores_columns_and_ruled_lines_instead_of_row_rectangles(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())->post(route('templates.store'), [
            'name' => 'Register of births 1950s',
            'doc_type' => DocumentType::Birth->value,
            'paper_size' => 'letter',
            'orientation' => 'landscape',
            'grouping_mode' => 'auto',
            'fields_json' => json_encode([], JSON_THROW_ON_ERROR),
            'columns_json' => json_encode(self::COLUMNS, JSON_THROW_ON_ERROR),
            'ruled_ys_json' => json_encode([0.2, 0.35, 0.5, 0.65, 0.8], JSON_THROW_ON_ERROR),
            'publish' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $template = DocumentTemplate::where('name', 'Register of births 1950s')->firstOrFail();
        $this->assertTrue($template->isLedger());
        $this->assertTrue($template->is_active);
        $this->assertSame(0, $template->fields()->count());
        $this->assertSame("Child's Name", $template->columns[0]['name']);
        $this->assertSame([0.2, 0.35, 0.5, 0.65, 0.8], $template->ruled_ys);
        $this->assertSame('column', $template->columnBoxes()[1]['kind']);
        $this->assertSame(1, $template->columnBoxes()[1]['columnIndex']);
    }

    public function test_columns_need_ruled_lines_running_top_to_bottom(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $payload = [
            'name' => 'Broken ledger',
            'doc_type' => DocumentType::Birth->value,
            'paper_size' => 'letter',
            'orientation' => 'portrait',
            'fields_json' => json_encode([], JSON_THROW_ON_ERROR),
            'columns_json' => json_encode(self::COLUMNS, JSON_THROW_ON_ERROR),
        ];

        $this->actingAs($admin)->post(route('templates.store'), [...$payload, 'ruled_ys_json' => '[]'])
            ->assertSessionHasErrors('ruled_ys');
        $this->actingAs($admin)->post(route('templates.store'), [...$payload, 'ruled_ys_json' => '[0.5, 0.3]'])
            ->assertSessionHasErrors('ruled_ys');
        $this->assertDatabaseMissing('document_templates', ['name' => 'Broken ledger']);
    }

    public function test_a_column_cannot_share_a_name_with_a_field(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())->post(route('templates.store'), [
            'name' => 'Clashing names',
            'doc_type' => DocumentType::Birth->value,
            'paper_size' => 'letter',
            'orientation' => 'portrait',
            'fields_json' => json_encode([[
                'name' => "child's name", 'x' => 0.1, 'y' => 0.05, 'width' => 0.2, 'height' => 0.05,
            ]], JSON_THROW_ON_ERROR),
            'columns_json' => json_encode(self::COLUMNS, JSON_THROW_ON_ERROR),
            'ruled_ys_json' => '[0.2, 0.5, 0.8]',
        ])->assertSessionHasErrors('columns.0.name');
    }

    public function test_an_existing_rectangle_template_keeps_working_and_reopens_without_a_grid(): void
    {
        $this->seed(DocumentTemplateSeeder::class);
        $template = DocumentTemplate::activeFor(DocumentType::Birth);

        $this->assertFalse($template->isLedger());
        $this->assertSame([], $template->columnBoxes());
        $this->assertCount($template->fields->count(), $template->markerBoxes());

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('templates.edit', $template))
            ->assertOk()
            ->assertSee('id="ledgerGridHeading"', escape: false)
            ->assertSee('"initialColumns":[]', escape: false);
    }

    public function test_grid_detection_returns_the_suggestion_and_discards_the_image(): void
    {
        Storage::fake('local');
        $this->app->instance(LineMarkers::class, new class extends LineMarkers
        {
            public function grid(string $imagePath): array
            {
                return ['suggestion' => [
                    'ruled_ys' => [0.2, 0.3, 0.4],
                    'columns' => [['box' => [0.1, 0.2, 0.3, 0.2]]],
                    'estimated_ys' => 1,
                ]];
            }
        });

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('templates.detect-grid'), ['image' => UploadedFile::fake()->image('sample.png', 400, 300)])
            ->assertOk()
            ->assertJson(['ruled_ys' => [0.2, 0.3, 0.4], 'estimated_ys' => 1]);

        $this->assertSame([], Storage::disk('local')->allFiles('template-grid'));

        $this->actingAs(User::factory()->staff()->create())
            ->post(route('templates.detect-grid'), ['image' => UploadedFile::fake()->image('sample.png', 400, 300)])
            ->assertForbidden();
    }

    public function test_training_export_writes_the_exact_crops_with_their_corrected_text(): void
    {
        Storage::fake('local');
        $this->seed(DocumentTemplateSeeder::class);
        $user = User::factory()->staff()->create();
        $record = CivilRecord::create([
            'doc_type' => DocumentType::Birth->value,
            'status' => RecordStatus::Submitted,
            'created_by' => $user->getKey(),
            'submitted_by' => $user->getKey(),
            'submitted_at' => now(),
        ]);
        Storage::disk('local')->put('records/'.$record->getKey().'/crops/001.png', 'exact-crop-bytes');

        $record->fields()->create([
            'name' => "Child's Name · row 3", 'ocr_text' => 'Juan Dela Crux', 'verified_value' => 'Juan Dela Cruz',
            'crop_path' => 'records/'.$record->getKey().'/crops/001.png',
            'line_column' => "Child's Name", 'line_row' => 3, 'polygon' => [[0.1, 0.2], [0.3, 0.2], [0.3, 0.25]],
            'x' => 0.1, 'y' => 0.2, 'width' => 0.2, 'height' => 0.05, 'sort_order' => 0,
        ]);
        // Captured before outlines existed: no crop was kept, so it is not exported.
        $record->fields()->create([
            'name' => 'Registry number', 'verified_value' => '1978-083',
            'x' => 0.1, 'y' => 0.1, 'width' => 0.2, 'height' => 0.05, 'sort_order' => 1,
        ]);

        $out = Storage::disk('local')->path('export-test');
        $this->artisan('crms:export-training', ['--out' => $out])
            ->expectsOutputToContain('Exported 1 line(s)')
            ->expectsOutputToContain('1 verified field(s) predate line outlines')
            ->assertSuccessful();

        $rows = array_map('str_getcsv', file($out.'/labels.csv', FILE_IGNORE_NEW_LINES));
        $this->assertSame(['file_name', 'text', 'doc_id', 'column', 'row'], $rows[0]);
        $this->assertCount(2, $rows);
        [$fileName, $text, $docId, $column, $row] = $rows[1];
        $this->assertSame('Juan Dela Cruz', $text);
        $this->assertSame((string) $record->getKey(), $docId);
        $this->assertSame("Child's Name", $column);
        $this->assertSame('3', $row);
        $this->assertSame('exact-crop-bytes', File::get($out.'/'.$fileName));
        $this->assertTrue(AuditLog::where('action', 'training.exported')->exists());
    }

    public function test_unsubmitted_pages_are_pruned_with_their_files(): void
    {
        Storage::fake('local');
        $user = User::factory()->staff()->create();
        $make = function (string $age) use ($user) {
            $page = DocumentPage::create([
                'created_by' => $user->getKey(), 'status' => 'ready', 'image_path' => 'x',
                'width' => 10, 'height' => 10, 'geometry' => ['columns' => [], 'ruled_ys' => [], 'fields' => []],
            ]);
            Storage::disk('local')->put($page->directory().'/page.png', 'png');
            $page->forceFill(['updated_at' => now()->sub($age)])->saveQuietly();

            return $page;
        };
        $stale = $make('2 days');
        $fresh = $make('1 hour');

        $this->artisan('documents:prune-pages')->assertSuccessful();

        $this->assertModelMissing($stale);
        $this->assertModelExists($fresh);
        Storage::disk('local')->assertMissing($stale->directory().'/page.png');
        Storage::disk('local')->assertExists($fresh->directory().'/page.png');
    }
}
