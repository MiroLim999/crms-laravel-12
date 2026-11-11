<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Services\Lines\LineMarkers;
use App\Support\MarkerBounds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What the builder refuses to save: row lines apart from their columns, row
 * lines on top of each other, and tilted markers whose corners leave the page.
 */
class TemplateBuilderGridChecksTest extends TestCase
{
    use RefreshDatabase;

    private const COLUMNS = [
        ['name' => "Child's Name", 'box' => [0.1, 0.2, 0.3, 0.6]],
        ['name' => 'Date of Birth', 'box' => [0.4, 0.2, 0.2, 0.6]],
    ];

    /** @param  array<string, mixed>  $overrides */
    private function ledger(array $overrides = []): array
    {
        return [
            'name' => 'Checked ledger',
            'doc_type' => DocumentType::Birth->value,
            'page_ratio' => 11 / 8.5,
            'fields_json' => json_encode([], JSON_THROW_ON_ERROR),
            'columns_json' => json_encode(self::COLUMNS, JSON_THROW_ON_ERROR),
            'ruled_ys_json' => json_encode([0.2, 0.35, 0.5, 0.65, 0.8], JSON_THROW_ON_ERROR),
            ...$overrides,
        ];
    }

    public function test_a_ledger_whose_row_lines_sit_in_its_columns_is_saved(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('templates.store'), $this->ledger())
            ->assertSessionHasNoErrors();

        $this->assertTrue(DocumentTemplate::where('name', 'Checked ledger')->firstOrFail()->isLedger());
    }

    public function test_row_lines_moved_away_from_their_columns_are_refused(): void
    {
        // The columns cover 0.2-0.8; these rows start two rows below them.
        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('templates.store'), $this->ledger([
                'ruled_ys_json' => json_encode([0.5, 0.6, 0.7, 0.8, 0.9, 0.99], JSON_THROW_ON_ERROR),
            ]))
            ->assertSessionHasErrors('ruled_ys');

        $this->assertDatabaseMissing('document_templates', ['name' => 'Checked ledger']);
    }

    public function test_row_lines_almost_on_top_of_each_other_are_refused(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('templates.store'), $this->ledger([
                'ruled_ys_json' => json_encode([0.2, 0.35, 0.3505, 0.5, 0.8], JSON_THROW_ON_ERROR),
            ]))
            ->assertSessionHasErrors('ruled_ys');
    }

    public function test_a_tilted_field_whose_corner_leaves_the_page_is_refused(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $field = ['name' => 'Remarks', 'x' => 0.3, 'y' => 0.0, 'width' => 0.3, 'height' => 0.1];

        // Upright, the box touches the top edge: fine.
        $this->actingAs($admin)->post(route('templates.store'), $this->ledger([
            'name' => 'Upright',
            'fields_json' => json_encode([$field], JSON_THROW_ON_ERROR),
            'columns_json' => '[]',
            'ruled_ys_json' => '[]',
        ]))->assertSessionHasNoErrors();

        // Turned 20 degrees about its centre, a top corner leaves the page.
        $this->actingAs($admin)->post(route('templates.store'), $this->ledger([
            'name' => 'Turned',
            'fields_json' => json_encode([[...$field, 'angle' => 20]], JSON_THROW_ON_ERROR),
            'columns_json' => '[]',
            'ruled_ys_json' => '[]',
        ]))->assertSessionHasErrors('fields.0.angle');
    }

    public function test_a_tilted_column_past_the_edge_is_refused(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('templates.store'), $this->ledger([
                'columns_json' => json_encode([
                    ['name' => 'Entry', 'box' => [0.0, 0.2, 0.05, 0.6], 'angle' => 5],
                    ['name' => 'Name', 'box' => [0.1, 0.2, 0.3, 0.6]],
                ], JSON_THROW_ON_ERROR),
            ]))
            ->assertSessionHasErrors('columns.0.box');
    }

    public function test_field_names_share_the_column_name_limit(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('templates.store'), $this->ledger([
                'fields_json' => json_encode([[
                    'name' => str_repeat('n', 121), 'x' => 0.1, 'y' => 0.05, 'width' => 0.2, 'height' => 0.05,
                ]], JSON_THROW_ON_ERROR),
            ]))
            ->assertSessionHasErrors('fields.0.name');
    }

    public function test_grid_detection_returns_the_printed_rules_for_snapping(): void
    {
        Storage::fake('local');
        $this->app->instance(LineMarkers::class, new class extends LineMarkers
        {
            public function grid(string $imagePath): array
            {
                return [
                    'horizontal' => [0.1, 0.2, 0.3],
                    'vertical' => [0.05, 0.5],
                    'suggestion' => ['ruled_ys' => [0.2, 0.3], 'columns' => [], 'estimated_ys' => 0],
                ];
            }
        });

        $this->actingAs(User::factory()->superAdmin()->create())
            ->post(route('templates.detect-grid'), ['image' => UploadedFile::fake()->image('sample.png', 400, 300)])
            ->assertOk()
            ->assertJsonPath('lines.horizontal', [0.1, 0.2, 0.3])
            ->assertJsonPath('lines.vertical', [0.05, 0.5]);
    }

    public function test_the_removed_test_on_sample_leaves_nothing_behind(): void
    {
        // "Test on sample" was removed (2026-11-11): its pages, kept under
        // template-tests/, are register pages and go with the next prune.
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->put('template-tests/'.Str::uuid().'/page.png', 'png');

        $this->artisan('documents:prune-pages')->assertSuccessful();

        $disk->assertMissing('template-tests');
        $this->assertFalse(Route::has('templates.test-layout'));
        $this->assertFalse(Route::has('templates.test-layout.status'));
    }

    public function test_marker_bounds_count_the_turned_corners(): void
    {
        // A wide, flat box in the middle of a landscape page stays on it when turned.
        $this->assertTrue(MarkerBounds::inside(0.3, 0.4, 0.4, 0.1, 30, 1.294));
        // The same box against the top edge does not.
        $this->assertFalse(MarkerBounds::inside(0.3, 0.0, 0.4, 0.1, 30, 1.294));
        // Upright, only the box itself counts.
        $this->assertTrue(MarkerBounds::inside(0.0, 0.0, 1.0, 1.0, 0, 1.294));
    }
}
