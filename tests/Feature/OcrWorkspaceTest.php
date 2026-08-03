<?php

namespace Tests\Feature;

use App\Models\OcrModel;
use App\Models\OcrSetting;
use App\Models\User;
use Database\Seeders\DocumentTemplateSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The OCR workspace: choose the model Staff scan with, and manage what is installed.
 *
 * The OCR service is faked throughout. These tests are about Laravel's half of the
 * contract - authorization, the durable registry, chunked upload reassembly, the
 * settings form, and the audit trail - not about whether TrOCR reads handwriting.
 */
class OcrWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Start from an empty upload area.
     *
     * RefreshDatabase rolls back the database but nothing rolls back the disk, and
     * user ids repeat between tests, so an abandoned upload from an earlier run sits
     * at exactly the path the next one uses. That leftover carries a progress record,
     * which is enough to change how the next test's first slice is interpreted.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $root = storage_path('app/ocr-uploads');

        if (is_dir($root)) {
            (new Filesystem)->deleteDirectory($root);
        }
    }

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    /**
     * A reachable service with one fine-tuned model plus the base model.
     */
    private function fakeHealthyService(array $overrides = []): void
    {
        $models = [
            ['key' => 'trocr-v1', 'label' => 'TrOCR v1', 'available' => true, 'loaded' => false],
            ['key' => 'base', 'label' => 'TrOCR base', 'available' => true, 'loaded' => false],
        ];

        Http::fake(array_merge([
            '*/health' => Http::response([
                'status' => 'ok',
                'model_loaded' => false,
                'device' => 'cuda',
                'default' => 'trocr-v1',
                'models' => $models,
            ]),
            '*/models' => Http::response([
                'default' => 'trocr-v1',
                'models' => $models,
            ]),
        ], $overrides));
    }

    // ------------------------------------------------------------------ authorization

    /**
     * Every route in the workspace is Super Admin only.
     *
     * The service has no authentication of its own, so this gate is the only thing
     * standing between a Staff account and deleting 1.3 GB of weights or silently
     * changing what the whole registry is read with.
     */
    public function test_every_workspace_route_is_super_admin_only(): void
    {
        $this->fakeHealthyService();

        $routes = [
            ['get', route('ocr.index')],
            ['post', route('ocr.rescan')],
            ['post', route('ocr.settings')],
            ['get', route('ocr.engine.status')],
            ['post', route('ocr.uploads.chunk')],
            ['post', route('ocr.uploads.discard')],
            ['post', route('ocr.store')],
            ['post', route('ocr.rename', 'trocr-v1')],
            ['delete', route('ocr.destroy', 'trocr-v1')],
        ];

        foreach ([User::factory()->staff()->create(), User::factory()->admin()->create()] as $user) {
            foreach ($routes as [$method, $url]) {
                $this->actingAs($user)->$method($url)
                    ->assertForbidden("{$user->roleSlug()->value} must not reach {$method} {$url}");
            }
        }
    }

    /**
     * The removed surface stays removed. A named route that no longer exists throws,
     * which is the assertion: nothing in the app can still link to fine-tuning,
     * datasets, evaluation, prediction, or engine process control.
     */
    public function test_the_removed_features_have_no_routes(): void
    {
        foreach ([
            'ocr.jobs.train', 'ocr.jobs.evaluate', 'ocr.jobs.status', 'ocr.jobs.cancel',
            'ocr.datasets.store', 'ocr.datasets.validate', 'ocr.datasets.destroy',
            'ocr.predict', 'ocr.chart', 'ocr.evaluation', 'ocr.activate',
            'ocr.engine.start', 'ocr.engine.stop',
        ] as $name) {
            $this->assertFalse(
                app('router')->has($name),
                "Route '{$name}' should have been removed with its feature.",
            );
        }
    }

    // ---------------------------------------------------------------- graceful degradation

    /**
     * The page has to render with the service down: that is exactly when a Super
     * Admin is looking for the command to start it.
     */
    public function test_the_workspace_renders_when_the_service_is_unreachable(): void
    {
        Http::fake(['*' => Http::response(status: 500)]);

        $this->actingAs($this->superAdmin())
            ->get(route('ocr.index'))
            ->assertOk()
            ->assertSee('OCR service offline')
            ->assertSee('python -m uvicorn ml.api.main:app', escape: false)
            // Process control is gone, not hidden behind a flag.
            ->assertDontSee('Start FastAPI')
            ->assertDontSee('Stop FastAPI');
    }

    public function test_the_workspace_shows_the_model_picker_when_the_service_is_up(): void
    {
        $this->fakeHealthyService();

        $this->actingAs($this->superAdmin())
            ->get(route('ocr.index'))
            ->assertOk()
            ->assertSee('OCR service online')
            ->assertSee('Approved model')
            ->assertSee('Save policy')
            ->assertSee('TrOCR v1')
            // Nothing promoted yet, so the picker has to offer an explicit empty
            // state; otherwise the first model is silently chosen and the form can
            // never become dirty by selecting it.
            ->assertSee('Select a model')
            ->assertSee('Action needed')
            // Healthy-state launch instructions would duplicate the status strip.
            ->assertDontSee('python -m uvicorn ml.api.main:app', escape: false)
            // The stripped tabs must not come back.
            ->assertDontSee('Fine-tuning')
            ->assertDontSee('Datasets')
            ->assertDontSee('Predict');
    }

    public function test_the_placeholder_option_disappears_once_a_model_is_in_use(): void
    {
        $this->fakeHealthyService();
        $actor = $this->superAdmin();

        $this->actingAs($actor)->post(route('ocr.settings'), ['model' => 'trocr-v1']);

        $this->actingAs($actor)
            ->get(route('ocr.index'))
            ->assertOk()
            ->assertDontSee('Select a model')
            ->assertDontSee('Action needed')
            ->assertSee('Active');
    }

    // -------------------------------------------------------------------- chunked upload

    /**
     * PHP caps uploads at 40M and a model is ~1.3 GB, so reassembly is the feature.
     */
    public function test_chunked_upload_reassembles_a_file_across_requests(): void
    {
        $actor = $this->superAdmin();
        $uploadId = str_repeat('a', 32);
        $fileKey = str_repeat('b', 32);

        $pieces = ['{"hidden_', 'size": 768}'];

        foreach ($pieces as $index => $piece) {
            $response = $this->actingAs($actor)->post(route('ocr.uploads.chunk'), [
                'upload_id' => $uploadId,
                'file_key' => $fileKey,
                'filename' => 'config.json',
                'index' => $index,
                'total' => count($pieces),
                'chunk' => UploadedFile::fake()->createWithContent("part{$index}", $piece),
            ]);

            $response->assertOk()->assertJson([
                'complete' => $index === count($pieces) - 1,
                'name' => 'config.json',
            ]);
        }

        $assembled = storage_path(
            "app/ocr-uploads/{$actor->getKey()}/{$uploadId}/files/config.json"
        );

        $this->assertFileExists($assembled);
        $this->assertSame('{"hidden_size": 768}', file_get_contents($assembled));
    }

    /**
     * Slices are appended as they arrive, so one that overtakes another has to be
     * parked and folded in once the gap ahead of it is filled. Without that, a
     * reordered slice would land at the wrong offset in the file.
     */
    public function test_chunked_upload_absorbs_slices_that_arrive_out_of_order(): void
    {
        $actor = $this->superAdmin();
        $uploadId = str_repeat('c', 32);
        $fileKey = str_repeat('d', 32);

        $pieces = ['{"hidden_', 'size": ', '768}'];
        $send = fn (int $index) => $this->actingAs($actor)->post(route('ocr.uploads.chunk'), [
            'upload_id' => $uploadId,
            'file_key' => $fileKey,
            'filename' => 'config.json',
            'index' => $index,
            'total' => count($pieces),
            'chunk' => UploadedFile::fake()->createWithContent("part{$index}", $pieces[$index]),
        ]);

        // The last two arrive first, so neither can be appended yet.
        $send(2)->assertOk()->assertJson(['complete' => false, 'received' => 1]);
        $send(1)->assertOk()->assertJson(['complete' => false, 'received' => 2]);

        // Slice 0 closes the gap, and the parked pair follows it in immediately.
        $send(0)->assertOk()->assertJson(['complete' => true, 'received' => 3]);

        $assembled = storage_path(
            "app/ocr-uploads/{$actor->getKey()}/{$uploadId}/files/config.json"
        );

        $this->assertFileExists($assembled);
        $this->assertSame('{"hidden_size": 768}', file_get_contents($assembled));
    }

    /**
     * A slice already in the file must be dropped rather than appended a second time:
     * the point of appending in order is that those bytes are already committed.
     */
    public function test_a_resent_slice_is_not_appended_twice(): void
    {
        $actor = $this->superAdmin();
        $uploadId = str_repeat('e', 32);
        $fileKey = str_repeat('f', 32);

        $pieces = ['{"hidden_', 'size": ', '768}'];
        $send = fn (int $index) => $this->actingAs($actor)->post(route('ocr.uploads.chunk'), [
            'upload_id' => $uploadId,
            'file_key' => $fileKey,
            'filename' => 'config.json',
            'index' => $index,
            'total' => count($pieces),
            'chunk' => UploadedFile::fake()->createWithContent("part{$index}", $pieces[$index]),
        ]);

        $send(0)->assertOk()->assertJson(['complete' => false, 'received' => 1]);
        $send(1)->assertOk()->assertJson(['complete' => false, 'received' => 2]);
        // A flaky connection makes the browser resend one it already acknowledged.
        $send(1)->assertOk()->assertJson(['complete' => false, 'received' => 2]);
        $send(2)->assertOk()->assertJson(['complete' => true]);

        $assembled = storage_path(
            "app/ocr-uploads/{$actor->getKey()}/{$uploadId}/files/config.json"
        );

        $this->assertSame('{"hidden_size": 768}', file_get_contents($assembled));
    }

    /**
     * The page tells the browser how large a slice may be. Hardcoding it is a guess:
     * too large and every slice 413s, too small and the upload wastes requests.
     */
    public function test_the_slice_size_handed_to_the_browser_fits_this_server(): void
    {
        $this->fakeHealthyService();

        $chunkBytes = $this->actingAs($this->superAdmin())
            ->get(route('ocr.index'))
            ->assertOk()
            ->viewData('chunkBytes');

        $megabyte = 1024 * 1024;
        $limit = min(
            (int) filter_var(ini_get('upload_max_filesize'), FILTER_SANITIZE_NUMBER_INT) * $megabyte,
            (int) filter_var(ini_get('post_max_size'), FILTER_SANITIZE_NUMBER_INT) * $megabyte,
        );

        $this->assertGreaterThanOrEqual($megabyte, $chunkBytes);
        $this->assertSame(0, $chunkBytes % $megabyte, 'A slice should be a whole number of MB.');
        $this->assertLessThan($limit, $chunkBytes, 'A slice must leave room for the form fields.');
        $this->assertLessThanOrEqual(32 * $megabyte, $chunkBytes);
    }

    public function test_chunked_upload_refuses_a_path_traversal_filename(): void
    {
        $actor = $this->superAdmin();

        // '..' and separators are stripped, leaving a name with no accepted
        // extension, which is rejected rather than written somewhere unexpected.
        $this->actingAs($actor)
            ->post(route('ocr.uploads.chunk'), [
                'upload_id' => str_repeat('a', 32),
                'file_key' => str_repeat('b', 32),
                'filename' => '../../../../.env',
                'index' => 0,
                'total' => 1,
                'chunk' => UploadedFile::fake()->createWithContent('x', 'x'),
            ])
            ->assertSessionHasErrors('filename');

        $this->assertDirectoryDoesNotExist(
            storage_path("app/ocr-uploads/{$actor->getKey()}/".str_repeat('a', 32).'/files')
        );
    }

    public function test_chunked_upload_refuses_a_malformed_upload_id(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('ocr.uploads.chunk'), [
                'upload_id' => '../escape',
                'file_key' => str_repeat('b', 32),
                'filename' => 'config.json',
                'index' => 0,
                'total' => 1,
                'chunk' => UploadedFile::fake()->createWithContent('x', 'x'),
            ])
            ->assertSessionHasErrors('upload_id');
    }

    // ------------------------------------------------------------------ installing a model

    /**
     * A single .zip is forwarded as `archive`, so the service extracts it and finds
     * the model inside rather than Laravel unpacking a 1.3 GB file itself.
     */
    public function test_installing_a_model_from_a_zip_sends_it_as_an_archive(): void
    {
        $this->fakeHealthyService([
            '*/add_model' => Http::response([
                'ok' => true,
                'name' => 'trocr-v2',
                'saved' => ['config.json', 'model.safetensors'],
            ]),
        ]);

        $actor = $this->superAdmin();
        $uploadId = str_repeat('c', 32);

        // Stand in for the browser's slices having already been reassembled.
        $this->actingAs($actor)->post(route('ocr.uploads.chunk'), [
            'upload_id' => $uploadId,
            'file_key' => str_repeat('d', 32),
            'filename' => 'trocr-v2.zip',
            'index' => 0,
            'total' => 1,
            'chunk' => UploadedFile::fake()->createWithContent('trocr-v2.zip', 'PK-not-a-real-zip'),
        ])->assertOk();

        $this->actingAs($actor)
            ->post(route('ocr.store'), ['name' => 'trocr-v2', 'upload_id' => $uploadId])
            ->assertRedirect(route('ocr.index'))
            ->assertSessionHas('success');

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/add_model')) {
                return false;
            }

            $fields = collect($request->data())->pluck('name');

            // The zip goes in the `archive` part; `files` is the folder-upload shape.
            return $fields->contains('archive') && ! $fields->contains('files');
        });

        $model = OcrModel::firstWhere('key', 'trocr-v2');

        $this->assertNotNull($model);
        $this->assertFalse($model->is_active, 'Installing must not silently change what Staff use.');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ocr_model.added',
            'user_id' => $actor->getKey(),
        ]);

        // The reassembled archive must not be left behind.
        $this->assertDirectoryDoesNotExist(
            storage_path("app/ocr-uploads/{$actor->getKey()}/{$uploadId}")
        );
    }

    public function test_a_model_folder_is_sent_as_loose_files(): void
    {
        $this->fakeHealthyService([
            '*/add_model' => Http::response(['ok' => true, 'name' => 'trocr-v3', 'saved' => []]),
        ]);

        $actor = $this->superAdmin();
        $uploadId = str_repeat('e', 32);

        foreach (['config.json', 'model.safetensors'] as $index => $filename) {
            $this->actingAs($actor)->post(route('ocr.uploads.chunk'), [
                'upload_id' => $uploadId,
                'file_key' => str_repeat((string) ($index + 1), 32),
                'filename' => $filename,
                'index' => 0,
                'total' => 1,
                'chunk' => UploadedFile::fake()->createWithContent($filename, 'x'),
            ])->assertOk();
        }

        $this->actingAs($actor)
            ->post(route('ocr.store'), ['name' => 'trocr-v3', 'upload_id' => $uploadId])
            ->assertSessionHas('success');

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/add_model')) {
                return false;
            }

            $fields = collect($request->data())->pluck('name');

            return $fields->filter(fn ($name) => $name === 'files')->count() === 2
                && ! $fields->contains('archive');
        });
    }

    /**
     * The upload has to go out labelled multipart.
     *
     * OcrClient builds every request with asJson(), which pins Content-Type to
     * application/json. attach() switches the body to multipart but leaves that
     * header alone, and Guzzle will not overwrite a Content-Type that is already
     * set - so the model arrived as a multipart body labelled JSON. FastAPI then
     * parsed no form at all, saw an empty `name`, and answered "Please provide a
     * model name" no matter what the Super Admin had typed.
     */
    public function test_a_model_upload_is_labelled_multipart_not_json(): void
    {
        $this->fakeHealthyService([
            '*/add_model' => Http::response(['ok' => true, 'name' => 'trocr-v4', 'saved' => []]),
        ]);

        $actor = $this->superAdmin();
        $uploadId = str_repeat('9', 32);

        $this->actingAs($actor)->post(route('ocr.uploads.chunk'), [
            'upload_id' => $uploadId,
            'file_key' => str_repeat('8', 32),
            'filename' => 'model.zip',
            'index' => 0,
            'total' => 1,
            'chunk' => UploadedFile::fake()->createWithContent('model.zip', 'PK'),
        ])->assertOk();

        $this->actingAs($actor)
            ->post(route('ocr.store'), ['name' => 'trocr-v4', 'upload_id' => $uploadId])
            ->assertSessionHas('success');

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/add_model')) {
                return false;
            }

            $contentType = $request->header('Content-Type')[0] ?? '';

            return str_starts_with($contentType, 'multipart/form-data')
                && collect($request->data())->pluck('name')->contains('name');
        });
    }

    /**
     * A zip beside loose files is ambiguous - which one is the model? - so it is
     * refused rather than guessed at.
     */
    public function test_a_zip_mixed_with_loose_files_is_refused(): void
    {
        $this->fakeHealthyService();

        $actor = $this->superAdmin();
        $uploadId = str_repeat('f', 32);

        foreach (['config.json', 'extra.zip'] as $index => $filename) {
            $this->actingAs($actor)->post(route('ocr.uploads.chunk'), [
                'upload_id' => $uploadId,
                'file_key' => str_repeat((string) ($index + 6), 32),
                'filename' => $filename,
                'index' => 0,
                'total' => 1,
                'chunk' => UploadedFile::fake()->createWithContent($filename, 'x'),
            ])->assertOk();
        }

        $this->actingAs($actor)
            ->post(route('ocr.store'), ['name' => 'mixed', 'upload_id' => $uploadId])
            ->assertSessionHas('error');

        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/add_model'));
    }

    // --------------------------------------------------------------------- save settings

    /**
     * Saving the settings is what makes Staff scanning use a model. Until then a
     * model is just a folder on disk.
     */
    public function test_saving_settings_is_what_makes_staff_scanning_use_a_model(): void
    {
        $this->fakeHealthyService();
        $actor = $this->superAdmin();

        $this->assertNull(OcrModel::active());

        $this->actingAs($actor)
            ->post(route('ocr.settings'), ['model' => 'trocr-v1'])
            ->assertRedirect(route('ocr.index'))
            ->assertSessionHas('success');

        $active = OcrModel::active();

        $this->assertNotNull($active);
        $this->assertSame('trocr-v1', $active->key);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ocr_model.activated',
            'user_id' => $actor->getKey(),
        ]);
    }

    public function test_saving_settings_persists_the_staff_choice_flag_and_threshold(): void
    {
        $this->fakeHealthyService();
        $actor = $this->superAdmin();

        $this->actingAs($actor)
            ->post(route('ocr.settings'), [
                'model' => 'trocr-v1',
                'allow_staff_model_choice' => '1',
                'confidence_review_threshold' => 65,
            ])
            ->assertSessionHas('success');

        OcrSetting::forgetCached();

        $this->assertTrue(OcrSetting::staffMayChooseModel());
        $this->assertSame(65.0, OcrSetting::threshold());

        $this->assertDatabaseHas('audit_logs', ['action' => 'ocr_settings.updated']);
    }

    /**
     * An empty threshold clears the override rather than storing zero, so the
     * documented CRMS_CONFIDENCE_THRESHOLD fallback keeps working.
     */
    public function test_clearing_the_threshold_falls_back_to_config(): void
    {
        $this->fakeHealthyService();
        $actor = $this->superAdmin();

        config(['crms.confidence_review_threshold' => 80.0]);

        $this->actingAs($actor)->post(route('ocr.settings'), [
            'model' => 'trocr-v1',
            'confidence_review_threshold' => 65,
        ]);

        $this->actingAs($actor)->post(route('ocr.settings'), [
            'model' => 'trocr-v1',
            'confidence_review_threshold' => '',
        ]);

        OcrSetting::forgetCached();

        $this->assertNull(OcrSetting::current()->confidence_review_threshold);
        $this->assertSame(80.0, OcrSetting::threshold());
    }

    public function test_settings_are_refused_for_a_model_the_service_cannot_serve(): void
    {
        $this->fakeHealthyService();

        $this->actingAs($this->superAdmin())
            ->post(route('ocr.settings'), ['model' => 'ghost-model'])
            ->assertSessionHas('error');

        $this->assertNull(OcrModel::active());
    }

    /**
     * Exactly one model may be in use: saving a second demotes the first, so Staff
     * are never scanning against an ambiguous target.
     */
    public function test_saving_a_different_model_demotes_the_previous_one(): void
    {
        $this->fakeHealthyService();
        $actor = $this->superAdmin();

        $this->actingAs($actor)->post(route('ocr.settings'), ['model' => 'trocr-v1']);
        $this->actingAs($actor)->post(route('ocr.settings'), ['model' => 'base']);

        $this->assertSame('base', OcrModel::active()->key);
        $this->assertSame(1, OcrModel::where('is_active', true)->count());
    }

    public function test_the_model_in_use_cannot_be_renamed_or_deleted(): void
    {
        $this->fakeHealthyService();
        $actor = $this->superAdmin();

        $this->actingAs($actor)->post(route('ocr.settings'), ['model' => 'trocr-v1']);

        $this->actingAs($actor)
            ->post(route('ocr.rename', 'trocr-v1'), ['new_name' => 'renamed'])
            ->assertSessionHas('error');

        $this->actingAs($actor)
            ->delete(route('ocr.destroy', 'trocr-v1'))
            ->assertSessionHas('error');

        $this->assertSame('trocr-v1', OcrModel::active()->key);
    }

    public function test_the_base_model_cannot_be_renamed_or_deleted(): void
    {
        $this->fakeHealthyService();
        $actor = $this->superAdmin();

        $this->actingAs($actor)
            ->post(route('ocr.rename', 'base'), ['new_name' => 'mine'])
            ->assertSessionHas('error');

        $this->actingAs($actor)
            ->delete(route('ocr.destroy', 'base'))
            ->assertSessionHas('error');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '_model'));
    }

    public function test_deleting_a_model_tombstones_it_and_is_audited(): void
    {
        $this->fakeHealthyService([
            '*/delete_model' => Http::response(['ok' => true, 'deleted' => 'trocr-v1']),
        ]);

        $actor = $this->superAdmin();

        $this->actingAs($actor)
            ->delete(route('ocr.destroy', 'trocr-v1'))
            ->assertSessionHas('success');

        $model = OcrModel::firstWhere('key', 'trocr-v1');

        $this->assertNotNull($model);
        $this->assertNotNull($model->disk_deleted_at);
        $this->assertSame($actor->getKey(), $model->disk_deleted_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ocr_model.deleted']);
    }

    // --------------------------------------------------------------------------- engine

    public function test_engine_status_reports_reachability_for_the_poller(): void
    {
        $this->fakeHealthyService();

        $this->actingAs($this->superAdmin())
            ->get(route('ocr.engine.status'))
            ->assertOk()
            ->assertJson(['reachable' => true, 'device' => 'cuda']);
    }

    // ----------------------------------------------------------------- staff-side choice

    /**
     * Off by default: every reading in the archive comes from the one model a Super
     * Admin selected, and the scanning page offers no way to deviate.
     */
    public function test_staff_cannot_choose_a_model_unless_the_setting_allows_it(): void
    {
        $this->fakeHealthyService();
        $staff = User::factory()->staff()->create();

        OcrModel::create(['key' => 'trocr-v1', 'label' => 'TrOCR v1', 'is_active' => true]);
        $this->seedTemplate();

        $this->actingAs($staff)
            ->get(route('documents.workspace', ['type' => 'birth']))
            ->assertOk()
            ->assertDontSee('OCR model');

        // A key posted anyway is ignored, and the promoted model is used instead.
        Http::fake([
            '*/health' => Http::response([
                'status' => 'ok', 'model_loaded' => false, 'device' => 'cuda',
                'default' => 'trocr-v1', 'models' => [],
            ]),
            '*/ocr' => Http::response([
                'results' => [['name' => 'Name', 'text' => 'Maria', 'confidence' => 91.0]],
                'model' => 'TrOCR v1',
                'modelKey' => 'trocr-v1',
            ]),
        ]);

        $this->actingAs($staff)
            ->postJson(route('documents.recognise'), [
                'fields' => [['name' => 'Name', 'image' => 'data:image/png;base64,AA==']],
                'model' => 'base',
            ])
            ->assertOk();

        Http::assertSent(function ($request) {
            return ! str_ends_with($request->url(), '/ocr')
                || $request->data()['model'] === 'trocr-v1';
        });
    }

    public function test_staff_may_pick_an_installed_model_when_the_setting_allows_it(): void
    {
        $this->fakeHealthyService([
            '*/ocr' => Http::response([
                'results' => [['name' => 'Name', 'text' => 'Maria', 'confidence' => 91.0]],
                'model' => 'TrOCR base',
                'modelKey' => 'base',
            ]),
        ]);

        OcrModel::create(['key' => 'trocr-v1', 'label' => 'TrOCR v1', 'is_active' => true]);
        OcrSetting::create(['allow_staff_model_choice' => true]);
        OcrSetting::forgetCached();
        $this->seedTemplate();

        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->get(route('documents.workspace', ['type' => 'birth']))
            ->assertOk()
            ->assertSee('OCR model');

        $this->actingAs($staff)
            ->postJson(route('documents.recognise'), [
                'fields' => [['name' => 'Name', 'image' => 'data:image/png;base64,AA==']],
                'model' => 'base',
            ])
            ->assertOk()
            ->assertJsonPath('modelKey', 'base');

        Http::assertSent(function ($request) {
            return ! str_ends_with($request->url(), '/ocr')
                || $request->data()['model'] === 'base';
        });
    }

    /**
     * A model the service cannot serve is never honoured, however it arrives. A
     * stale tab must not quietly swap the model behind a record.
     */
    public function test_an_unknown_model_key_falls_back_to_the_selected_model(): void
    {
        $this->fakeHealthyService([
            '*/ocr' => Http::response([
                'results' => [['name' => 'Name', 'text' => 'Maria', 'confidence' => 91.0]],
                'model' => 'TrOCR v1',
                'modelKey' => 'trocr-v1',
            ]),
        ]);

        OcrModel::create(['key' => 'trocr-v1', 'label' => 'TrOCR v1', 'is_active' => true]);
        OcrSetting::create(['allow_staff_model_choice' => true]);
        OcrSetting::forgetCached();

        $this->actingAs(User::factory()->staff()->create())
            ->postJson(route('documents.recognise'), [
                'fields' => [['name' => 'Name', 'image' => 'data:image/png;base64,AA==']],
                'model' => 'ghost-model',
            ])
            ->assertOk();

        Http::assertSent(function ($request) {
            return ! str_ends_with($request->url(), '/ocr')
                || $request->data()['model'] === 'trocr-v1';
        });
    }

    /**
     * The scanning workspace needs an active template for the type it renders.
     */
    private function seedTemplate(): void
    {
        $this->seed(DocumentTemplateSeeder::class);
    }
}
