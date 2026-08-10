<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\RecordStatus;
use App\Models\ChangeRequest;
use App\Models\CivilRecord;
use App\Models\DocumentTemplate;
use App\Models\DocumentTypeDefinition;
use App\Models\OcrModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Analytics are consolidated into the role-aware dashboard. Staff receive only
 * their own work summary; Admin and Super Admin receive global read-only figures;
 * OCR/template governance stays Super Admin-only.
 */
class AnalyticsDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_legacy_analytics_url_remains_gated_and_redirects_oversight_users(): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->get(route('analytics.index'))
            ->assertForbidden();

        foreach ([User::factory()->admin()->create(), User::factory()->superAdmin()->create()] as $user) {
            $this->actingAs($user)
                ->get(route('analytics.index', ['period' => '90']))
                ->assertRedirect(route('dashboard', ['period' => '90']));
        }
    }

    public function test_dashboard_content_is_role_aware(): void
    {
        $staff = $this->actingAs(User::factory()->staff()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Your submissions this month')
            ->assertDontSee('Reporting scope')
            ->assertDontSee('Governance activity');

        $this->assertNull($staff->viewData('analytics'));
        $this->assertNull($staff->viewData('system'));

        $admin = $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Reporting scope')
            ->assertSee('Digitisation volume')
            ->assertSee('OCR review signals')
            ->assertSee('Governance activity')
            ->assertSee('Account health')
            ->assertDontSee('Template usage and OCR review signals');

        $this->assertNotNull($admin->viewData('analytics'));
        $this->assertNull($admin->viewData('system'));

        $super = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('System readiness')
            ->assertSee('Template usage and OCR review signals')
            ->assertSee('Open Template Builder');

        $this->assertNotNull($super->viewData('system'));
    }

    public function test_it_counts_submissions_pending_requests_and_ocr_threshold_compliance(): void
    {
        Carbon::setTestNow('2026-08-11 04:00:00');
        $staff = User::factory()->staff()->create();

        $submitted = $this->record($staff, DocumentType::Birth, RecordStatus::Submitted);
        $this->record($staff, DocumentType::Death, RecordStatus::Draft);

        $submitted->fields()->create([
            'name' => 'Child Full Name',
            'ocr_text' => 'Ana Cruz',
            'verified_value' => 'Ana Cruz',
            'ocr_confidence' => 90.0,
        ]);
        $submitted->fields()->create([
            'name' => 'Date of Birth',
            'ocr_text' => '1 Jan 2001',
            'verified_value' => '1 January 2001',
            'ocr_confidence' => 70.0,
        ]);

        ChangeRequest::create([
            'record_id' => $submitted->getKey(),
            'reason' => 'Misread surname.',
            'requested_by' => $staff->getKey(),
        ]);

        $analytics = $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->viewData('analytics');

        $this->assertSame(1, $analytics['headline']['records']);
        $this->assertSame(1, $analytics['headline']['pending_requests']);
        $this->assertSame(80.0, $analytics['ocr_quality']['average_confidence']);
        $this->assertSame(1, $analytics['ocr_quality']['below_threshold']);
        $this->assertSame(50.0, $analytics['ocr_quality']['threshold_pass_rate']);
        $this->assertSame(50.0, $analytics['ocr_quality']['correction_rate']);
    }

    public function test_filters_apply_to_records_ocr_signals_and_account_counts(): void
    {
        Carbon::setTestNow('2026-08-11 04:00:00');
        $staff = User::factory()->staff()->create();

        $birth = $this->record($staff, DocumentType::Birth, RecordStatus::Submitted, [
            'ocr_model_key' => 'birth-model',
            'submitted_at' => Carbon::parse('2026-08-05 02:00:00'),
        ]);
        $birth->fields()->create([
            'name' => 'Child Full Name',
            'ocr_text' => 'Ana',
            'verified_value' => 'Ana',
            'ocr_confidence' => 95.0,
        ]);

        $death = $this->record($staff, DocumentType::Death, RecordStatus::Submitted, [
            'ocr_model_key' => 'death-model',
            'submitted_at' => Carbon::parse('2026-08-06 02:00:00'),
        ]);
        $death->fields()->create([
            'name' => 'Deceased Full Name',
            'ocr_text' => 'Juan',
            'verified_value' => 'John',
            'ocr_confidence' => 40.0,
        ]);

        $analytics = $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard', [
                'period' => 'custom',
                'from' => '2026-08-01',
                'to' => '2026-08-10',
                'document_type' => 'birth',
                'ocr_model' => 'birth-model',
            ]))
            ->assertOk()
            ->viewData('analytics');

        $this->assertSame(1, $analytics['headline']['records']);
        $this->assertSame(95.0, $analytics['ocr_quality']['average_confidence']);
        $this->assertSame(['Birth'], $analytics['by_document_type']
            ->where('total', '>', 0)
            ->pluck('type')
            ->map->shortLabel()
            ->values()
            ->all());
        $this->assertSame(['birth-model'], $analytics['recent_records']->pluck('ocr_model_key')->all());
        $this->assertSame([$staff->name], $analytics['throughput']->pluck('name')->all());
    }

    public function test_super_admin_system_readiness_uses_published_template_and_model_state(): void
    {
        $birth = DocumentTypeDefinition::where('key', 'birth')->firstOrFail();
        $template = DocumentTemplate::create([
            'name' => 'Birth Ledger v1',
            'doc_type' => 'birth',
            'document_type_id' => $birth->getKey(),
            'is_active' => true,
        ]);
        $template->fields()->create([
            'name' => 'Child Full Name',
            'x' => .1,
            'y' => .1,
            'width' => .4,
            'height' => .05,
            'sort_order' => 0,
        ]);
        OcrModel::create(['key' => 'registry-model', 'label' => 'Registry model', 'is_active' => true]);

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Registry model')
            ->assertSee('1 of 3 types ready');

        $system = $response->viewData('system');

        $this->assertSame(1, $system['ready_types']);
        $this->assertSame(3, $system['total_types']);
        $this->assertSame(2, $system['template_issues']);
        $this->assertSame('registry-model', $system['active_model']->key);
        $this->assertSame('Birth Ledger v1', $system['template_performance']->first()['template']->name);
    }

    public function test_analytics_is_removed_from_the_sidebar(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('>Analytics<', escape: false)
            ->assertSee('>Reports<', escape: false)
            ->assertSee('>Audit Log<', escape: false);
    }

    public function test_reading_dashboard_defaults_does_not_create_ocr_settings(): void
    {
        $this->assertDatabaseCount('ocr_settings', 0);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertOk();

        $this->assertDatabaseCount('ocr_settings', 0);
    }

    public function test_super_admin_system_status_reports_live_ocr_and_cached_scan_storage(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('scans/one.png', '1234');
        Storage::disk('local')->put('scans/two.png', '123456');
        Cache::forget('dashboard.scan-storage.v1');
        Http::fake([
            '*/health' => Http::response([
                'status' => 'ok',
                'device' => 'cuda',
                'default' => 'base',
                'models' => [],
            ]),
        ]);

        foreach ([User::factory()->staff()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)
                ->get(route('dashboard.system-status'))
                ->assertForbidden();
        }

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('dashboard.system-status'))
            ->assertOk()
            ->assertJsonPath('engine.reachable', true)
            ->assertJsonPath('engine.device', 'cuda')
            ->assertJsonPath('scan_storage.available', true)
            ->assertJsonPath('scan_storage.bytes', 10)
            ->assertJsonPath('scan_storage.files', 2);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function record(
        User $staff,
        DocumentType $type,
        RecordStatus $status,
        array $overrides = [],
    ): CivilRecord {
        return CivilRecord::create([
            'doc_type' => $type->value,
            'status' => $status->value,
            'created_by' => $staff->getKey(),
            'submitted_by' => $status === RecordStatus::Submitted ? $staff->getKey() : null,
            'submitted_at' => $status === RecordStatus::Submitted ? now() : null,
            ...$overrides,
        ]);
    }
}
