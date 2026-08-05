<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\OcrModel;
use App\Models\OcrSetting;
use App\Models\User;
use Database\Seeders\DocumentTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The OCR workspace: choose the model Staff scan with, and manage what is installed.
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
     * Direct uploads also require signed tickets, while the remaining lifecycle
     * calls rely on this gate and the service's loopback binding.
     */
    public function test_every_workspace_route_is_super_admin_only(): void
    {
        $this->fakeHealthyService();

        $routes = [
            ['get', route('ocr.index')],
            ['post', route('ocr.rescan')],
            ['post', route('ocr.settings')],
            ['get', route('ocr.engine.status')],
            ['post', route('ocr.uploads.authorize')],
            ['post', route('ocr.register')],
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
            'ocr.uploads.chunk', 'ocr.uploads.discard', 'ocr.store',
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
            ->assertSee('PowerShell setup')
            ->assertSee('Activate virtual environment')
            ->assertSee('.\.venv\Scripts\Activate.ps1', escape: false)
            ->assertSee('Start OCR service')
            ->assertSee('Copy command')
            ->assertSee('python -m uvicorn ml.api.main:app --host 127.0.0.1 --port 8001', escape: false)
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
        $this->assertFalse($model->is_active, 'Installing must not silently change what Staff use.');
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
