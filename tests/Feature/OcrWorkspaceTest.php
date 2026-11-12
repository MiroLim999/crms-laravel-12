<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Jobs\ProcessDocumentPage;
use App\Models\AuditLog;
use App\Models\DocumentPage;
use App\Models\DocumentTemplate;
use App\Models\OcrModel;
use App\Models\OcrSetting;
use App\Models\User;
use App\Services\Ocr\EngineStatus;
use App\Services\Ocr\OcrModelPerformance;
use Database\Seeders\DocumentTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The OCR workspace: choose the model scans use, and manage what is installed.
 *
 * The OCR service is faked throughout. These tests are about Laravel's half of the
 * contract - authorization, direct-upload registration, the durable registry,
 * settings, and the audit trail - not about whether TrOCR reads handwriting.
 */
class OcrWorkspaceTest extends TestCase
{
    use RefreshDatabase;

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
            [
                'key' => 'trocr-v1',
                'label' => 'TrOCR v1',
                'available' => true,
                'loaded' => false,
                'files' => ['config.json', 'model.safetensors', 'tokenizer.json'],
            ],
            ['key' => 'base', 'label' => 'TrOCR base', 'available' => true, 'loaded' => false, 'files' => []],
        ];

        Http::fake(array_merge([
            '*/health' => Http::response([
                'status' => 'ok',
                'model_loaded' => false,
                'device' => 'cuda',
                'precision' => 'fp16',
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
     * The removed surface stays removed. A named route that no longer exists throws,
     * which is the assertion: nothing in the app can still link to fine-tuning,
     * datasets, evaluation, prediction, engine process control, or the old
     * reading of crops cut in the browser (the queue job reads pages now).
     */
    public function test_the_removed_features_have_no_routes(): void
    {
        foreach ([
            'ocr.jobs.train', 'ocr.jobs.evaluate', 'ocr.jobs.status', 'ocr.jobs.cancel',
            'ocr.datasets.store', 'ocr.datasets.validate', 'ocr.datasets.destroy',
            'ocr.predict', 'ocr.chart', 'ocr.evaluation', 'ocr.activate',
            'ocr.engine.start', 'ocr.engine.stop',
            'ocr.uploads.chunk', 'ocr.uploads.discard', 'ocr.store',
            'documents.recognise',
        ] as $name) {
            $this->assertFalse(
                app('router')->has($name),
                "Route '{$name}' should have been removed with its feature.",
            );
        }
    }

    // ---------------------------------------------------------------- graceful degradation

    /**
     * The page has to render with the service down, and say that it is down:
     * that is exactly when a Super Admin opens it to find out why scans fail.
     */
    public function test_the_workspace_renders_when_the_service_is_unreachable(): void
    {
        Http::fake(['*' => Http::response(status: 500)]);

        $this->actingAs($this->superAdmin())
            ->get(route('ocr.index'))
            ->assertOk()
            ->assertSee('OCR service offline')
            // Process control is gone, not hidden behind a flag.
            ->assertDontSee('Start FastAPI')
            ->assertDontSee('Stop FastAPI');
    }

    public function test_the_performance_card_says_where_its_numbers_come_from(): void
    {
        $this->fakeHealthyService();

        $response = $this->actingAs($this->superAdmin())
            ->get(route('ocr.index'))
            ->assertOk()
            // Processing time sits with the scores, and the hint says how to switch model.
            ->assertSee('Reading speed')
            ->assertSee('Click a model to see its scores')
            // The old note's jargon, and its claim about error reduction, are gone.
            ->assertDontSee('teacher forcing')
            ->assertDontSee('Error reduction reflects');

        // The radar is the first view.
        $this->assertMatchesRegularExpression(
            '/class="btn btn-primary" id="ocr-btn-view-radar" aria-pressed="true"/',
            $response->getContent(),
        );
    }

    public function test_the_workspace_lets_the_super_admin_activate_a_model_when_the_service_is_up(): void
    {
        $this->fakeHealthyService();

        $this->actingAs($this->superAdmin())
            ->get(route('ocr.index'))
            ->assertOk()
            ->assertSee('OCR service online')
            ->assertSee('TrOCR v1')
            ->assertSee('Activate model')
            ->assertSee('Rescan models')
            // Nothing activated yet, so the panel has to say nothing can be scanned.
            ->assertSee('No model is active yet')
            // The removed panels, the stripped tabs and the old PowerShell setup
            // panel must not come back.
            ->assertDontSee('Scanning policy')
            ->assertDontSee('Benchmark Audit')
            ->assertDontSee('PowerShell setup')
            ->assertDontSee('Fine-tuning')
            ->assertDontSee('Datasets')
            ->assertDontSee('Predict');
    }

    public function test_the_activation_notice_disappears_once_a_model_is_in_use(): void
    {
        $this->fakeHealthyService();
        $actor = $this->superAdmin();

        $this->actingAs($actor)->post(route('ocr.models.activate', 'trocr-v1'));

        $this->actingAs($actor)
            ->get(route('ocr.index'))
            ->assertOk()
            ->assertDontSee('No model is active yet')
            ->assertSee('Active');
    }

    // -------------------------------------------------------------- direct upload

    public function test_super_admin_can_issue_a_short_lived_direct_upload_ticket(): void
    {
        config([
            'services.ocr.browser_url' => 'http://127.0.0.1:8001',
            'services.ocr.upload_secret' => 'test-upload-secret',
            'services.ocr.upload_ticket_ttl' => 900,
        ]);

        $actor = $this->superAdmin();
        $response = $this->actingAs($actor)
            ->postJson(route('ocr.uploads.authorize'), ['name' => 'trocr direct v2'])
            ->assertOk()
            ->assertJsonPath('upload_url', 'http://127.0.0.1:8001/add_model');

        [$encoded, $signature] = explode('.', $response->json('authorization'), 2);
        $decode = fn (string $value) => base64_decode(
            strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4),
            true,
        );
        $payload = json_decode($decode($encoded), true, flags: JSON_THROW_ON_ERROR);
        $expected = hash_hmac('sha256', $encoded, 'test-upload-secret', true);

        $this->assertTrue(hash_equals($expected, $decode($signature)));
        $this->assertSame('ocr-model-upload', $payload['purpose']);
        $this->assertSame('trocr direct v2', $payload['name']);
        $this->assertSame($actor->getKey(), $payload['user_id']);
        $this->assertGreaterThan(now()->getTimestamp(), $payload['expires_at']);
    }

    public function test_completed_direct_upload_is_registered_and_audited(): void
    {
        $this->fakeHealthyService();
        $actor = $this->superAdmin();

        $this->actingAs($actor)
            ->postJson(route('ocr.register'), [
                'name' => 'trocr-v1',
                // This browser-controlled value must never reach the audit log.
                'saved' => ['forged-browser-value.txt'],
            ])
            ->assertOk()
            ->assertJson([
                'registered' => true,
                'name' => 'trocr-v1',
            ])
            ->assertSessionHas('success');

        $model = OcrModel::firstWhere('key', 'trocr-v1');

        $this->assertNotNull($model);
        $this->assertFalse($model->is_active, 'Installing must not silently change what scans use.');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ocr_model.added',
            'user_id' => $actor->getKey(),
        ]);

        $audit = AuditLog::where('action', 'ocr_model.added')->sole();
        $this->assertSame(
            ['key' => 'trocr-v1', 'files' => ['config.json', 'model.safetensors', 'tokenizer.json']],
            $audit->new_values,
        );

        // A retry after Laravel committed but the browser lost the response must
        // succeed without duplicating the installation audit.
        $this->actingAs($actor)
            ->postJson(route('ocr.register'), ['name' => 'trocr-v1'])
            ->assertOk()
            ->assertJsonPath('registered', true);

        $this->assertSame(1, AuditLog::where('action', 'ocr_model.added')->count());
    }

    public function test_direct_upload_registration_refuses_a_model_not_on_disk(): void
    {
        $this->fakeHealthyService();

        $this->actingAs($this->superAdmin())
            ->postJson(route('ocr.register'), ['name' => 'ghost-model'])
            ->assertStatus(503)
            ->assertJsonPath(
                'message',
                "The OCR service does not report 'ghost-model' as installed. Rescan, or check ml/models/.",
            );

        $this->assertDatabaseMissing('ocr_models', ['key' => 'ghost-model']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ocr_model.added']);
    }

    public function test_registration_imports_the_server_validated_test_benchmark(): void
    {
        $evaluation = [
            'schema_version' => 1,
            'model_key' => 'trocr-v1',
            'dataset' => 'civil-records-v1',
            'manifest_sha256' => str_repeat('a', 64),
            'split' => 'test',
            'sample_count' => 5000,
            'metrics' => ['cer' => 0.0003, 'wer' => 0.001, 'exact_match' => 0.9978],
            'evaluated_at' => '2026-08-12T00:00:00+00:00',
            'weights_file' => 'model.safetensors',
            'weights_sha256' => str_repeat('b', 64),
        ];
        $models = [[
            'key' => 'trocr-v1',
            'label' => 'TrOCR v1',
            'available' => true,
            'loaded' => false,
            'files' => ['config.json', 'evaluation-report.json', 'model.safetensors'],
            'evaluation' => $evaluation,
        ]];
        $this->fakeHealthyService([
            '*/models' => Http::response(['default' => 'trocr-v1', 'models' => $models]),
        ]);

        $this->actingAs($this->superAdmin())
            ->postJson(route('ocr.register'), ['name' => 'trocr-v1'])
            ->assertOk();

        $model = OcrModel::firstWhere('key', 'trocr-v1');
        $this->assertSame(0.0003, $model->cer);
        $this->assertSame(0.001, $model->wer);
        $this->assertSame(0.9978, $model->exact_match);
        $this->assertSame('civil-records-v1', $model->evaluation_dataset);
        $this->assertSame('test', $model->evaluation_split);
        $this->assertSame(5000, $model->evaluation_sample_count);
        $this->assertSame(str_repeat('a', 64), $model->evaluation_manifest_sha256);
        $this->assertSame(str_repeat('b', 64), $model->evaluation_weights_sha256);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ocr_model.evaluation_imported']);
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluation(string $manifest, float $cer, float $wer, float $exact, string $dataset = 'civil-records-v1'): array
    {
        return [
            'schema_version' => 1,
            'model_key' => 'any',
            'dataset' => $dataset,
            'manifest_sha256' => $manifest,
            'split' => 'test',
            'sample_count' => 5000,
            'metrics' => ['cer' => $cer, 'wer' => $wer, 'exact_match' => $exact],
            'evaluated_at' => '2026-08-12T00:00:00+00:00',
            'weights_file' => 'model.safetensors',
            'weights_sha256' => str_repeat('b', 64),
        ];
    }

    /**
     * The notebook ships the base model's scores from the same run; FastAPI puts
     * them on the base model. Registering the upload must pick them up at once,
     * so the overlay compares like with like without a Rescan.
     */
    public function test_registration_also_imports_base_scores_from_the_same_run(): void
    {
        OcrModel::create(['key' => 'base', 'label' => 'base']);
        $models = [
            [
                'key' => 'trocr-v2', 'label' => 'TrOCR v2', 'available' => true, 'loaded' => false,
                'files' => ['base-evaluation-report.json', 'config.json', 'evaluation-report.json', 'model.safetensors'],
                'evaluation' => $this->evaluation(str_repeat('c', 64), 0.0003, 0.001, 0.9978),
            ],
            [
                'key' => 'base', 'label' => 'TrOCR base', 'available' => true, 'loaded' => false,
                'files' => ['config.json', 'evaluation-report.json', 'model.safetensors'],
                'evaluation' => $this->evaluation(str_repeat('c', 64), 0.1198, 0.5146, 0.3318),
            ],
        ];
        $this->fakeHealthyService([
            '*/models' => Http::response(['default' => 'trocr-v2', 'models' => $models]),
        ]);

        $this->actingAs($this->superAdmin())
            ->postJson(route('ocr.register'), ['name' => 'trocr-v2'])
            ->assertOk()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'same test set, so the comparison is fair'));

        $base = OcrModel::firstWhere('key', 'base');
        $this->assertSame(0.1198, $base->cer);
        $this->assertSame(str_repeat('c', 64), $base->evaluation_manifest_sha256);
        $this->assertTrue(OcrModel::firstWhere('key', 'trocr-v2')->sharesTestSetWith($base));
    }

    public function test_registration_warns_when_base_scores_are_from_another_test_set(): void
    {
        OcrModel::create(['key' => 'base', 'label' => 'base']);
        $models = [
            [
                'key' => 'trocr-v2', 'label' => 'TrOCR v2', 'available' => true, 'loaded' => false,
                'files' => ['config.json', 'evaluation-report.json', 'model.safetensors'],
                'evaluation' => $this->evaluation(str_repeat('c', 64), 0.0003, 0.001, 0.9978, 'names-100k'),
            ],
            [
                'key' => 'base', 'label' => 'TrOCR base', 'available' => true, 'loaded' => false,
                'files' => ['config.json', 'evaluation-report.json', 'model.safetensors'],
                'evaluation' => $this->evaluation(str_repeat('d', 64), 0.1198, 0.5146, 0.3318, 'names-50k'),
            ],
        ];
        $this->fakeHealthyService([
            '*/models' => Http::response(['default' => 'trocr-v2', 'models' => $models]),
        ]);

        $this->actingAs($this->superAdmin())
            ->postJson(route('ocr.register'), ['name' => 'trocr-v2'])
            ->assertOk()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'could not be matched to the same test set (they are from names-50k)'));
    }

    // --------------------------------------------------------------------- save settings

    /**
     * Exactly one model may be in use: activating a second demotes the first, so
     * scans are never run against an ambiguous target.
     */
    public function test_saving_a_different_model_demotes_the_previous_one(): void
    {
        $this->fakeHealthyService();
        $actor = $this->superAdmin();

        $this->actingAs($actor)->post(route('ocr.models.activate', 'trocr-v1'));
        $this->actingAs($actor)->post(route('ocr.models.activate', 'base'));

        $this->assertSame('base', OcrModel::active()->key);
        $this->assertSame(1, OcrModel::where('is_active', true)->count());
    }

    public function test_the_model_in_use_cannot_be_renamed_or_deleted(): void
    {
        $this->fakeHealthyService();
        $actor = $this->superAdmin();

        $this->actingAs($actor)->post(route('ocr.models.activate', 'trocr-v1'));

        $this->actingAs($actor)
            ->post(route('ocr.rename', 'trocr-v1'), ['new_name' => 'renamed'])
            ->assertSessionHas('error');

        $this->actingAs($actor)
            ->delete(route('ocr.destroy', 'trocr-v1'))
            ->assertSessionHas('error');

        $this->assertSame('trocr-v1', OcrModel::active()->key);
    }

    public function test_the_base_model_cannot_be_renamed(): void
    {
        $this->fakeHealthyService();
        $actor = $this->superAdmin();

        $this->actingAs($actor)
            ->post(route('ocr.rename', 'base'), ['new_name' => 'mine'])
            ->assertSessionHas('error');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '_model'));
    }

    public function test_the_base_model_can_be_deleted_like_any_other(): void
    {
        $this->fakeHealthyService([
            '*/delete_model' => Http::response(['ok' => true, 'deleted' => 'base']),
        ]);
        $actor = $this->superAdmin();

        $this->actingAs($actor)
            ->delete(route('ocr.destroy', 'base'))
            ->assertSessionHas('success');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/delete_model')
            && ($request['model'] ?? null) === 'base');
        $this->assertDatabaseHas('audit_logs', ['action' => 'ocr_model.deleted']);
    }

    public function test_a_fresh_install_with_no_models_shows_an_empty_list(): void
    {
        // ml/models is not in git: on a new computer the service has nothing to list
        // until a model is added by hand.
        Http::fake([
            '*/health' => Http::response(['status' => 'ok', 'model_loaded' => false, 'device' => 'cpu', 'default' => null, 'models' => []]),
            '*/models' => Http::response(['default' => null, 'models' => []]),
        ]);

        $this->actingAs($this->superAdmin())
            ->get(route('ocr.index'))
            ->assertOk()
            ->assertSee('No models available')
            ->assertDontSee('Built-in model');

        $this->assertSame(0, OcrModel::count());
    }

    public function test_a_base_model_whose_folder_is_gone_is_marked_deleted(): void
    {
        OcrModel::create(['key' => 'base', 'label' => 'TrOCR base']);
        Http::fake([
            '*/health' => Http::response(['status' => 'ok', 'model_loaded' => false, 'device' => 'cpu', 'default' => null, 'models' => []]),
            '*/models' => Http::response(['default' => null, 'models' => []]),
        ]);

        $this->actingAs($this->superAdmin())
            ->get(route('ocr.index'))
            ->assertOk()
            ->assertSee('No models available');

        $this->assertNotNull(OcrModel::firstWhere('key', 'base')->disk_deleted_at);
    }

    public function test_a_picked_colour_is_kept_and_a_taken_colour_is_swapped(): void
    {
        $actor = $this->superAdmin();
        OcrModel::create(['key' => 'model-a', 'label' => 'model-a']);
        OcrModel::create(['key' => 'model-b', 'label' => 'model-b']);
        $slots = fn () => collect(app(OcrModelPerformance::class)->summary()['models'])
            ->pluck('color_slot', 'key');

        $before = $slots();
        $this->assertNotSame($before['model-a'], $before['model-b']);

        // A free colour.
        $free = collect(range(1, 8))->first(fn ($slot) => ! $before->contains($slot));
        $this->actingAs($actor)
            ->post(route('ocr.models.color', 'model-a'), ['color_slot' => $free])
            ->assertSessionHas('success');
        $this->assertSame($free, $slots()['model-a']);
        $this->assertSame($before['model-b'], $slots()['model-b']);

        // B's colour: A takes it, B takes A's.
        $this->actingAs($actor)
            ->post(route('ocr.models.color', 'model-a'), ['color_slot' => $before['model-b']])
            ->assertSessionHas('success');
        $this->assertSame($before['model-b'], $slots()['model-a']);
        $this->assertSame($free, $slots()['model-b']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ocr_model.color_changed']);
    }

    public function test_a_colour_outside_the_palette_is_refused(): void
    {
        OcrModel::create(['key' => 'model-a', 'label' => 'model-a']);

        $this->actingAs($this->superAdmin())
            ->post(route('ocr.models.color', 'model-a'), ['color_slot' => 9])
            ->assertSessionHasErrors('color_slot');

        $this->assertNull(OcrModel::firstWhere('key', 'model-a')->color_slot);
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

        $this->assertNull($model);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ocr_model.deleted']);
    }

    // --------------------------------------------------------------------------- engine

    public function test_engine_status_reports_reachability_for_the_poller(): void
    {
        $this->fakeHealthyService();

        $this->actingAs($this->superAdmin())
            ->get(route('ocr.engine.status'))
            ->assertOk()
            ->assertJson(['reachable' => true, 'device' => 'cuda', 'precision' => 'fp16', 'mode' => 'GPU, fp16']);
    }

    public function test_the_status_pill_says_where_the_service_reads(): void
    {
        $this->fakeHealthyService();

        $this->actingAs($this->superAdmin())
            ->get(route('ocr.index'))
            ->assertOk()
            ->assertSee('OCR service online (GPU, fp16)');

        $this->assertSame('GPU, fp32', EngineStatus::modeLabel('cuda', 'fp32'));
        $this->assertSame('GPU, fp32', EngineStatus::modeLabel('cuda', null)); // an older service
        $this->assertSame('CPU', EngineStatus::modeLabel('cpu', 'fp32'));
        $this->assertNull(EngineStatus::modeLabel('unavailable (RuntimeError)', null));
        $this->assertNull(EngineStatus::modeLabel(null, null));
    }

    // ----------------------------------------------------------------- scan model

    /**
     * A model key sent with a scan is ignored: every scan reads with the active
     * model, so a stale tab cannot quietly swap the model behind a record.
     */
    public function test_a_model_key_sent_with_a_scan_is_ignored(): void
    {
        $this->fakeHealthyService();

        OcrModel::create(['key' => 'trocr-v1', 'label' => 'TrOCR v1', 'is_active' => true]);
        $this->seedTemplate();

        $this->alignPage(User::factory()->superAdmin()->create(), 'ghost-model')
            ->assertAccepted()
            ->assertJsonPath('modelKey', 'trocr-v1');

        $this->assertSame('trocr-v1', DocumentPage::firstOrFail()->ocr_model_key);
    }

    /**
     * The scanning workspace needs an active template for the type it renders.
     */
    private function seedTemplate(): void
    {
        $this->seed(DocumentTemplateSeeder::class);
    }

    /**
     * Finish the Align step with a model chosen, as the workspace does. The page
     * is stored with the model it will be read with; the job that would outline
     * and read it is only queued, so Kraken never runs here.
     */
    private function alignPage(User $account, string $model): TestResponse
    {
        Storage::fake('local');
        Queue::fake();

        $response = $this->actingAs($account)
            ->withHeader('Accept', 'application/json')
            ->post(route('documents.pages.store'), [
                'document_template_id' => DocumentTemplate::activeFor(DocumentType::Birth)->getKey(),
                'page' => UploadedFile::fake()->image('page.png', 800, 600),
                'geometry_json' => json_encode([
                    'columns' => [],
                    'ruled_ys' => [],
                    'fields' => [['name' => 'Child Full Name', 'box' => [0.1, 0.1, 0.3, 0.05]]],
                ], JSON_THROW_ON_ERROR),
                'model' => $model,
            ]);

        Queue::assertPushed(ProcessDocumentPage::class);

        return $response;
    }
}
