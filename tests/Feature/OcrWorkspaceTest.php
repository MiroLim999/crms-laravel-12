<?php

namespace Tests\Feature;

use App\Enums\MlJobStatus;
use App\Enums\MlJobType;
use App\Models\AuditLog;
use App\Models\MlDataset;
use App\Models\MlJob;
use App\Models\OcrModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The OCR workspace: the whole ML lifecycle driven from one page.
 *
 * The OCR service is faked throughout. These tests are about Laravel's half of the
 * contract - authorization, the durable `ml_jobs` history, chunked upload
 * reassembly, and the audit trail - not about whether TrOCR trains.
 */
class OcrWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    /**
     * A reachable service with one fine-tuned model and one dataset.
     */
    private function fakeHealthyService(array $overrides = []): void
    {
        Http::fake(array_merge([
            '*/health' => Http::response([
                'status' => 'ok',
                'model_loaded' => false,
                'device' => 'cuda',
                'default' => 'trocr-v1',
                'models' => [
                    ['key' => 'trocr-v1', 'label' => 'TrOCR v1', 'available' => true, 'loaded' => false],
                    ['key' => 'base', 'label' => 'TrOCR base', 'available' => true, 'loaded' => false],
                ],
                'busy' => false,
                'job' => null,
            ]),
            '*/models' => Http::response([
                'default' => 'trocr-v1',
                'models' => [
                    ['key' => 'trocr-v1', 'label' => 'TrOCR v1', 'available' => true, 'loaded' => false],
                    ['key' => 'base', 'label' => 'TrOCR base', 'available' => true, 'loaded' => false],
                ],
            ]),
            '*/datasets' => Http::response([
                'ok' => true,
                'datasets' => [[
                    'name' => 'names-2026',
                    'folder' => 'names-2026',
                    'images' => ['train' => 400, 'val' => 50, 'test' => 50],
                    'usable' => ['train' => 400, 'val' => 50, 'test' => 50],
                    'total_images' => 500,
                    'manifest_rows' => 500,
                    'has_manifest' => true,
                    'size_bytes' => 12_345_678,
                ]],
            ]),
            '*/training_defaults' => Http::response([
                'ok' => true,
                'defaults' => ['epochs' => 5, 'batch_size' => 8, 'learning_rate' => 5e-5],
            ]),
        ], $overrides));
    }

    // ------------------------------------------------------------------ authorization

    /**
     * Every route in the workspace is Super Admin only.
     *
     * The service has no authentication of its own, so this gate is the only thing
     * standing between a Staff account and deleting 1.3 GB of weights or starting a
     * job that pins the GPU for hours.
     */
    public function test_every_workspace_route_is_super_admin_only(): void
    {
        $this->fakeHealthyService();

        $job = MlJob::create([
            'job_id' => 'abc123', 'type' => MlJobType::Training,
            'status' => MlJobStatus::Running, 'config' => [],
        ]);

        $routes = [
            ['get', route('ocr.index')],
            ['post', route('ocr.rescan')],
            ['post', route('ocr.engine.start')],
            ['post', route('ocr.engine.stop')],
            ['get', route('ocr.engine.status')],
            ['post', route('ocr.uploads.chunk')],
            ['post', route('ocr.uploads.discard')],
            ['post', route('ocr.store')],
            ['post', route('ocr.activate', 'trocr-v1')],
            ['post', route('ocr.rename', 'trocr-v1')],
            ['delete', route('ocr.destroy', 'trocr-v1')],
            ['post', route('ocr.evaluation', 'trocr-v1')],
            ['post', route('ocr.datasets.store')],
            ['post', route('ocr.datasets.validate', 'names-2026')],
            ['delete', route('ocr.datasets.destroy', 'names-2026')],
            ['post', route('ocr.jobs.train')],
            ['post', route('ocr.jobs.evaluate')],
            ['get', route('ocr.jobs.status', $job)],
            ['post', route('ocr.jobs.cancel', $job)],
            ['post', route('ocr.predict')],
            ['get', route('ocr.chart', ['variant' => 'base', 'name' => 'x.png'])],
        ];

        foreach ([User::factory()->staff()->create(), User::factory()->admin()->create()] as $user) {
            foreach ($routes as [$method, $url]) {
                $this->actingAs($user)->$method($url)
                    ->assertForbidden("{$user->roleSlug()->value} must not reach {$method} {$url}");
            }
        }
    }

    // ---------------------------------------------------------------- graceful degradation

    /**
     * The page has to render with the service down: that is exactly when a Super
     * Admin needs the Run button.
     */
    public function test_the_workspace_renders_when_the_service_is_unreachable(): void
    {
        Http::fake(['*' => Http::response(status: 500)]);

        $this->actingAs($this->superAdmin())
            ->get(route('ocr.index'))
            ->assertOk()
            ->assertSee('OCR service offline')
            ->assertSee('Run service');
    }

    public function test_the_workspace_shows_every_tab_when_the_service_is_up(): void
    {
        $this->fakeHealthyService();

        $this->actingAs($this->superAdmin())
            ->get(route('ocr.index'))
            ->assertOk()
            ->assertSee('OCR service online')
            ->assertSee('Fine-tuning')
            ->assertSee('Evaluation')
            ->assertSee('Predict')
            ->assertSee('names-2026');
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

    // -------------------------------------------------------------------------- datasets

    public function test_uploading_a_dataset_stores_its_validation_report_and_audits_it(): void
    {
        $this->fakeHealthyService([
            '*/datasets' => Http::sequence()
                ->push([
                    'ok' => true,
                    'name' => 'names-2026',
                    'summary' => [
                        'name' => 'names-2026',
                        'images' => ['train' => 400, 'val' => 50, 'test' => 50],
                        'total_images' => 500,
                        'size_bytes' => 999,
                    ],
                    'validation' => ['ok' => true, 'errors' => [], 'warnings' => [],
                                     'usable' => ['train' => 400, 'val' => 50, 'test' => 50]],
                ]),
        ]);

        $actor = $this->superAdmin();
        $uploadId = str_repeat('c', 32);

        // Stand in for the browser's slices having already been reassembled.
        $this->actingAs($actor)->post(route('ocr.uploads.chunk'), [
            'upload_id' => $uploadId,
            'file_key' => str_repeat('d', 32),
            'filename' => 'names.zip',
            'index' => 0,
            'total' => 1,
            'chunk' => UploadedFile::fake()->createWithContent('names.zip', 'PK-not-a-real-zip'),
        ])->assertOk();

        $this->actingAs($actor)
            ->post(route('ocr.datasets.store'), [
                'name' => 'names-2026',
                'upload_id' => $uploadId,
            ])
            ->assertRedirect(route('ocr.index', ['tab' => 'datasets']))
            ->assertSessionHas('success');

        $dataset = MlDataset::firstWhere('name', 'names-2026');

        $this->assertNotNull($dataset);
        $this->assertTrue($dataset->is_valid);
        $this->assertTrue($dataset->isTrainable());
        $this->assertSame(500, $dataset->total_images);
        $this->assertSame($actor->getKey(), $dataset->uploaded_by);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ml_dataset.uploaded',
            'user_id' => $actor->getKey(),
        ]);

        // The reassembled archive must not be left behind.
        $this->assertDirectoryDoesNotExist(
            storage_path("app/ocr-uploads/{$actor->getKey()}/{$uploadId}")
        );
    }

    public function test_deleting_a_dataset_is_audited(): void
    {
        $this->fakeHealthyService([
            '*/datasets/*' => Http::response(['ok' => true, 'deleted' => 'names-2026']),
        ]);

        MlDataset::create(['name' => 'names-2026', 'total_images' => 500]);
        $actor = $this->superAdmin();

        $this->actingAs($actor)
            ->delete(route('ocr.datasets.destroy', 'names-2026'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('ml_datasets', ['name' => 'names-2026']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ml_dataset.deleted']);
    }

    // ------------------------------------------------------------------------------ jobs

    public function test_starting_a_fine_tuning_run_records_the_resolved_config(): void
    {
        $this->fakeHealthyService([
            '*/jobs' => Http::response([
                'ok' => true,
                'job_id' => 'job-abc',
                'job' => [
                    'id' => 'job-abc',
                    'status' => 'running',
                    // The service echoes back what it resolved, defaults filled in.
                    'config' => [
                        'dataset' => 'names-2026',
                        'output_name' => 'trocr-v2',
                        'epochs' => 5,
                        'batch_size' => 8,
                        'num_workers' => 0,
                    ],
                    'progress' => ['stage' => 'starting', 'percent' => 0],
                    'log' => ['[00:00:00] Training job started.'],
                ],
            ]),
        ]);

        MlDataset::create([
            'name' => 'names-2026',
            'is_valid' => true,
            'validated_at' => now(),
            'validation' => ['ok' => true, 'usable' => ['train' => 400]],
        ]);

        $actor = $this->superAdmin();

        $this->actingAs($actor)
            ->post(route('ocr.jobs.train'), [
                'dataset' => 'names-2026',
                'output_name' => 'trocr-v2',
                'base_model' => 'base',
                'epochs' => 5,
                'batch_size' => 8,
                'learning_rate' => 0.00005,
                'max_label_length' => 32,
                'num_workers' => 0,
            ])
            ->assertRedirect(route('ocr.index', ['tab' => 'training']))
            ->assertSessionHas('success');

        $job = MlJob::firstWhere('job_id', 'job-abc');

        $this->assertNotNull($job);
        $this->assertSame(MlJobType::Training, $job->type);
        $this->assertSame(MlJobStatus::Running, $job->status);
        $this->assertSame('names-2026', $job->dataset);
        $this->assertSame('trocr-v2', $job->output_name);
        $this->assertSame(5, $job->config['epochs']);
        $this->assertSame($actor->getKey(), $job->triggered_by);

        $this->assertDatabaseHas('audit_logs', ['action' => 'ml_job.started']);
    }

    /**
     * Training on a dataset that failed validation wastes hours and fails deep into
     * an epoch, so it is refused locally without even asking the service.
     */
    public function test_fine_tuning_is_refused_on_a_dataset_that_failed_validation(): void
    {
        $this->fakeHealthyService();

        MlDataset::create([
            'name' => 'broken',
            'is_valid' => false,
            'validated_at' => now(),
            'validation' => ['ok' => false, 'errors' => ['2 rows point at a missing image.']],
        ]);

        $this->actingAs($this->superAdmin())
            ->post(route('ocr.jobs.train'), [
                'dataset' => 'broken',
                'output_name' => 'trocr-v2',
                'epochs' => 1,
                'batch_size' => 1,
                'learning_rate' => 0.00005,
                'max_label_length' => 32,
                'num_workers' => 0,
            ])
            ->assertSessionHas('error');

        $this->assertDatabaseCount('ml_jobs', 0);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/jobs'));
    }

    /**
     * One GPU job at a time. A second start is told what is already running rather
     * than being queued behind it.
     */
    public function test_a_second_job_reports_the_conflict_rather_than_queueing(): void
    {
        $this->fakeHealthyService([
            '*/jobs' => Http::response(
                ['ok' => false, 'error' => 'A training job is already running (job-abc).'],
                409,
            ),
        ]);

        $this->actingAs($this->superAdmin())
            ->post(route('ocr.jobs.evaluate'), [
                'model' => 'trocr-v1',
                'dataset' => 'names-2026',
                'split' => 'test',
            ])
            ->assertSessionHas('warning');

        $this->assertDatabaseCount('ml_jobs', 0);
    }

    public function test_cancelling_a_run_asks_the_service_and_audits_it(): void
    {
        $this->fakeHealthyService([
            '*/jobs/*/cancel' => Http::response([
                'ok' => true,
                'job' => ['id' => 'job-abc', 'status' => 'running', 'cancel_requested' => true,
                          'progress' => ['percent' => 40]],
            ]),
        ]);

        $actor = $this->superAdmin();

        $job = MlJob::create([
            'job_id' => 'job-abc',
            'type' => MlJobType::Training,
            'status' => MlJobStatus::Running,
            'config' => ['dataset' => 'names-2026'],
            'progress' => ['percent' => 40],
            'triggered_by' => $actor->getKey(),
        ]);

        $this->actingAs($actor)
            ->post(route('ocr.jobs.cancel', $job))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', ['action' => 'ml_job.cancel_requested']);
    }

    /**
     * A completed evaluation is what puts real numbers on a model. Confidence never
     * does - it is the model's certainty in its own output.
     */
    public function test_a_completed_evaluation_writes_its_metrics_onto_the_model(): void
    {
        $actor = $this->superAdmin();

        $job = MlJob::create([
            'job_id' => 'job-eval',
            'type' => MlJobType::Evaluation,
            'status' => MlJobStatus::Running,
            'config' => ['model' => 'trocr-v1', 'dataset' => 'names-2026', 'split' => 'test'],
            'model_key' => 'trocr-v1',
            'dataset' => 'names-2026',
            'triggered_by' => $actor->getKey(),
        ]);

        $this->fakeHealthyService([
            '*/jobs/job-eval' => Http::response([
                'ok' => true,
                'job' => [
                    'id' => 'job-eval',
                    'status' => 'completed',
                    'progress' => ['percent' => 100, 'stage' => 'done'],
                    'metrics' => ['cer' => 0.0842, 'wer' => 0.1533, 'exact_match' => 0.7412,
                                  'total' => 50],
                    'result' => ['model_key' => 'trocr-v1'],
                    'log' => ['done'],
                    'error' => null,
                ],
            ]),
        ]);

        $this->actingAs($actor)
            ->get(route('ocr.jobs.status', $job))
            ->assertOk()
            ->assertJson(['status' => 'completed', 'live' => false]);

        $model = OcrModel::firstWhere('key', 'trocr-v1');

        $this->assertNotNull($model);
        $this->assertSame(0.0842, $model->cer);
        $this->assertSame(0.7412, $model->exact_match);
        $this->assertNotNull($model->evaluated_at);

        // Evaluating must never promote. That stays an explicit human decision.
        $this->assertFalse($model->is_active);
        $this->assertNull(OcrModel::active());
    }

    /**
     * A finished training run registers its output so it appears in the model list -
     * but is not activated. Nothing reaches Staff without a deliberate step.
     */
    public function test_a_completed_training_run_registers_but_does_not_promote_its_model(): void
    {
        $actor = $this->superAdmin();

        $job = MlJob::create([
            'job_id' => 'job-train',
            'type' => MlJobType::Training,
            'status' => MlJobStatus::Running,
            'config' => ['dataset' => 'names-2026', 'output_name' => 'trocr-v2', 'epochs' => 5],
            'dataset' => 'names-2026',
            'output_name' => 'trocr-v2',
            'triggered_by' => $actor->getKey(),
        ]);

        $this->fakeHealthyService([
            '*/jobs/job-train' => Http::response([
                'ok' => true,
                'job' => [
                    'id' => 'job-train',
                    'status' => 'completed',
                    'progress' => ['percent' => 100],
                    'metrics' => ['best_val_loss' => 0.42, 'epochs_completed' => 5],
                    'result' => ['output_name' => 'trocr-v2'],
                    'log' => [], 'error' => null,
                ],
            ]),
        ]);

        $this->actingAs($actor)->get(route('ocr.jobs.status', $job))->assertOk();

        $model = OcrModel::firstWhere('key', 'trocr-v2');

        $this->assertNotNull($model, 'A finished run should register its output model.');
        $this->assertFalse($model->is_active);
        $this->assertNull(OcrModel::active());
    }

    // ------------------------------------------------------------------------ promotion

    /**
     * The "save it so Staff can use it" step, and the only action here that changes
     * what a Staff scan runs against.
     */
    public function test_promoting_a_model_is_what_makes_staff_scanning_use_it(): void
    {
        $this->fakeHealthyService();
        $actor = $this->superAdmin();

        $this->assertNull(OcrModel::active());

        $this->actingAs($actor)
            ->post(route('ocr.activate', 'trocr-v1'))
            ->assertRedirect(route('ocr.index', ['tab' => 'models']))
            ->assertSessionHas('success');

        $active = OcrModel::active();

        $this->assertNotNull($active);
        $this->assertSame('trocr-v1', $active->key);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ocr_model.activated',
            'user_id' => $actor->getKey(),
        ]);
    }

    public function test_promotion_is_refused_for_a_model_the_service_cannot_serve(): void
    {
        $this->fakeHealthyService();

        $this->actingAs($this->superAdmin())
            ->post(route('ocr.activate', 'ghost-model'))
            ->assertSessionHas('error');

        $this->assertNull(OcrModel::active());
    }

    /**
     * Exactly one model may be active: promoting a second demotes the first, so
     * Staff are never scanning against an ambiguous target.
     */
    public function test_promoting_a_model_demotes_the_previous_one(): void
    {
        $this->fakeHealthyService();
        $actor = $this->superAdmin();

        $this->actingAs($actor)->post(route('ocr.activate', 'trocr-v1'));
        $this->actingAs($actor)->post(route('ocr.activate', 'base'));

        $this->assertSame('base', OcrModel::active()->key);
        $this->assertSame(1, OcrModel::where('is_active', true)->count());
    }

    public function test_the_active_model_cannot_be_renamed_or_deleted(): void
    {
        $this->fakeHealthyService();
        $actor = $this->superAdmin();

        $this->actingAs($actor)->post(route('ocr.activate', 'trocr-v1'));

        $this->actingAs($actor)
            ->post(route('ocr.rename', 'trocr-v1'), ['new_name' => 'renamed'])
            ->assertSessionHas('error');

        $this->actingAs($actor)
            ->delete(route('ocr.destroy', 'trocr-v1'))
            ->assertSessionHas('error');

        $this->assertSame('trocr-v1', OcrModel::active()->key);
    }

    // --------------------------------------------------------------------------- engine

    public function test_stopping_the_engine_is_refused_when_nothing_is_being_tracked(): void
    {
        $this->fakeHealthyService();

        $this->actingAs($this->superAdmin())
            ->post(route('ocr.engine.stop'))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('audit_logs', ['action' => 'ocr_engine.stopped']);
    }

    public function test_starting_the_engine_is_refused_when_it_already_answers(): void
    {
        $this->fakeHealthyService();

        $this->actingAs($this->superAdmin())
            ->post(route('ocr.engine.start'))
            ->assertSessionHas('error');
    }

    public function test_engine_status_reports_reachability_for_the_poller(): void
    {
        $this->fakeHealthyService();

        $this->actingAs($this->superAdmin())
            ->get(route('ocr.engine.status'))
            ->assertOk()
            ->assertJson(['reachable' => true, 'device' => 'cuda', 'busy' => false]);
    }

    // -------------------------------------------------------------------------- predict

    public function test_spot_check_prediction_returns_confidence_per_image(): void
    {
        $this->fakeHealthyService([
            '*/predict' => Http::response([
                'ok' => true,
                'model' => 'TrOCR v1',
                'modelKey' => 'trocr-v1',
                'count' => 2,
                'average_confidence' => 88.5,
                'low_confidence' => 1,
                'threshold' => 80.0,
                'rows' => [
                    ['filename' => 'a.png', 'text' => 'Maria Santos', 'confidence' => 96.2],
                    ['filename' => 'b.png', 'text' => 'Jose Cruz', 'confidence' => 74.1],
                ],
            ]),
        ]);

        $this->actingAs($this->superAdmin())
            ->post(route('ocr.predict'), [
                'model' => 'trocr-v1',
                'images' => [
                    UploadedFile::fake()->image('a.png'),
                    UploadedFile::fake()->image('b.png'),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('rows.0.text', 'Maria Santos')
            ->assertJsonPath('rows.1.confidence', 74.1)
            // JSON renders a whole float as an int, so compare numerically.
            ->assertJsonPath(
                'threshold',
                fn ($value) => (float) $value === (float) config('crms.confidence_review_threshold'),
            );
    }

    public function test_prediction_is_capped_and_reports_the_service_being_down(): void
    {
        Http::fake(['*' => Http::response(status: 500)]);

        $this->actingAs($this->superAdmin())
            ->post(route('ocr.predict'), [
                'model' => 'trocr-v1',
                'images' => [UploadedFile::fake()->image('a.png')],
            ])
            ->assertStatus(503)
            ->assertJsonStructure(['message']);
    }
}
