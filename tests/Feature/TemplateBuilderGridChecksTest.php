<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Jobs\TestTemplateLayout;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Services\Lines\LineMarkers;
use App\Services\Lines\LineMarkersException;
use App\Support\MarkerBounds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
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

    private const TEST_GEOMETRY = [
        'columns' => [['name' => "Child's Name", 'box' => [0.1, 0.2, 0.3, 0.6]]],
        'ruled_ys' => [0.2, 0.5, 0.8],
        'fields' => [],
    ];

    /** @param  array<string, mixed>  $overrides */
    private function ledger(array $overrides = []): array
    {
        return [
            'name' => 'Checked ledger',
            'doc_type' => DocumentType::Birth->value,
            'paper_size' => 'letter',
            'orientation' => 'landscape',
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

    public function test_testing_a_layout_answers_at_once_with_an_id_to_poll(): void
    {
        Storage::fake('local');
        Queue::fake();
        $admin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($admin)
            ->post(route('templates.test-layout'), [
                'page' => UploadedFile::fake()->image('sample.png', 400, 300),
                'geometry_json' => json_encode(self::TEST_GEOMETRY, JSON_THROW_ON_ERROR),
            ])
            ->assertAccepted()
            ->assertJsonPath('status', 'queued');

        $id = $response->json('id');
        $this->assertTrue(Str::isUuid($id));
        $this->assertSame(route('templates.test-layout.status', $id), $response->json('statusUrl'));
        Storage::disk('local')->assertExists(TestTemplateLayout::directory($id).'/page.png');
        Queue::assertPushed(TestTemplateLayout::class, fn (TestTemplateLayout $job) => $job->testId === $id
            && $job->geometry['ruled_ys'] === self::TEST_GEOMETRY['ruled_ys']);

        $this->actingAs($admin)->getJson(route('templates.test-layout.status', $id))
            ->assertOk()
            ->assertExactJson(['status' => 'queued']);

        Storage::disk('local')->put(TestTemplateLayout::directory($id).'/started', '');
        $this->actingAs($admin)->getJson(route('templates.test-layout.status', $id))
            ->assertExactJson(['status' => 'running']);
    }

    public function test_the_layout_is_tested_on_its_sample_without_keeping_anything(): void
    {
        Storage::fake('local');
        $this->app->instance(LineMarkers::class, new class extends LineMarkers
        {
            public function detect(string $pagePath, array $geometry, string $outDirectory): array
            {
                file_put_contents($outDirectory.'/lines.json', '{}');

                return [
                    'size' => [400, 300],
                    'deskew' => 0.0,
                    'fit' => ['fitted' => true],
                    'geometry' => $geometry,
                    'notes' => [['code' => 'lines_below_grid', 'count' => 2, 'rows' => 1], ['code' => 'made_up']],
                    // line_markers.py reports how many lines were left out.
                    'ignored' => 1,
                    'lines' => [
                        ['source' => 'kraken', 'column' => "Child's Name", 'column_index' => 0, 'row' => 1,
                            'polygon' => [[1, 2], [3, 2], [3, 4]], 'bbox' => [1, 2, 2, 2], 'crop' => 'c.png', 'flags' => ['no_row', 'other']],
                    ],
                ];
            }
        });

        $admin = User::factory()->superAdmin()->create();
        // The test queue runs the job at once, so the result is ready.
        $id = $this->actingAs($admin)
            ->post(route('templates.test-layout'), [
                'page' => UploadedFile::fake()->image('sample.png', 400, 300),
                'geometry_json' => json_encode(self::TEST_GEOMETRY, JSON_THROW_ON_ERROR),
            ])
            ->assertAccepted()
            ->json('id');

        $response = $this->actingAs($admin)->getJson(route('templates.test-layout.status', $id))
            ->assertOk()
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('fit.fitted', true)
            ->assertJsonPath('image', null)
            ->assertJsonPath('ignored', 1)
            ->assertJsonPath('notes', [['code' => 'lines_below_grid', 'count' => 2, 'rows' => 1]])
            ->assertJsonPath('lines.0.flags', ['no_row'])
            ->assertJsonPath('lines.0.row', 1);

        $this->assertSame([0.2, 0.5, 0.8], $response->json('geometry.ruled_ys'));
        // The sample is a real register page: gone once the result is shown.
        $this->assertSame([], Storage::disk('local')->allFiles(TestTemplateLayout::ROOT));
        $this->actingAs($admin)->getJson(route('templates.test-layout.status', $id))->assertNotFound();

        $staff = User::factory()->staff()->create();
        $this->actingAs($staff)
            ->post(route('templates.test-layout'), [
                'page' => UploadedFile::fake()->image('sample.png', 400, 300),
                'geometry_json' => json_encode(self::TEST_GEOMETRY, JSON_THROW_ON_ERROR),
            ])
            ->assertForbidden();
        $this->actingAs($staff)->getJson(route('templates.test-layout.status', $id))->assertForbidden();
    }

    public function test_a_test_that_line_detection_refuses_reports_why(): void
    {
        Storage::fake('local');
        $this->app->instance(LineMarkers::class, new class extends LineMarkers
        {
            public function detect(string $pagePath, array $geometry, string $outDirectory): array
            {
                throw new LineMarkersException('Line detection failed: no table found.');
            }
        });
        $admin = User::factory()->superAdmin()->create();

        $id = $this->actingAs($admin)
            ->post(route('templates.test-layout'), [
                'page' => UploadedFile::fake()->image('sample.png', 400, 300),
                'geometry_json' => json_encode(self::TEST_GEOMETRY, JSON_THROW_ON_ERROR),
            ])
            ->json('id');

        $this->actingAs($admin)->getJson(route('templates.test-layout.status', $id))
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'Line detection failed: no table found.']);
        $this->assertSame([], Storage::disk('local')->allFiles(TestTemplateLayout::ROOT));
    }

    public function test_abandoned_layout_tests_are_pruned(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $stale = TestTemplateLayout::directory((string) Str::uuid());
        $fresh = TestTemplateLayout::directory((string) Str::uuid());
        $disk->put($stale.'/page.png', 'png');
        $disk->put($fresh.'/page.png', 'png');
        touch($disk->path($stale), now()->subDays(2)->getTimestamp());

        $this->artisan('documents:prune-pages')->assertSuccessful();

        $disk->assertMissing($stale);
        $disk->assertExists($fresh.'/page.png');
    }

    public function test_a_broken_layout_is_not_tested(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson(route('templates.test-layout'), [
                'page' => UploadedFile::fake()->image('sample.png', 400, 300),
                'geometry_json' => json_encode([
                    'columns' => [['name' => 'A', 'box' => [0.1, 0.2, 0.3, 0.6]]],
                    'ruled_ys' => [],
                    'fields' => [],
                ], JSON_THROW_ON_ERROR),
            ])
            ->assertJsonValidationErrors('geometry.ruled_ys');
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
