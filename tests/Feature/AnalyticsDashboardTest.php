<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\RecordStatus;
use App\Models\CivilRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Analytics are consolidated into the role-aware dashboard. Staff receive only
 * their own work summary; Admin and Super Admin receive the focused CRM overview.
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
            ->assertSee('Total digitized records')
            ->assertSee('Digitization Volume &amp; Trend', escape: false)
            ->assertSee('OCR AI Quality &amp; Accuracy', escape: false)
            ->assertSee('Staff Processing &amp; Throughput', escape: false)
            ->assertDontSee('Reporting scope')
            ->assertDontSee('Governance activity');

        $this->assertNotNull($admin->viewData('analytics'));
        $this->assertNull($admin->viewData('system'));

        $super = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Total digitized records')
            ->assertSee('Document Type Distribution')
            ->assertDontSee('System readiness')
            ->assertDontSee('Open Template Builder');

        $this->assertNull($super->viewData('system'));
    }

    public function test_it_counts_digitized_records_and_ocr_quality_signals(): void
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

        $analytics = $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->viewData('analytics');

        $this->assertSame(1, $analytics['headline']['records']);
        $this->assertSame(1, $analytics['headline']['period_records']);
        $this->assertSame(80.0, $analytics['ocr_quality']['average_confidence']);
        $this->assertSame(1, $analytics['ocr_quality']['below_threshold']);
        $this->assertSame(50.0, $analytics['ocr_quality']['threshold_pass_rate']);
        $this->assertSame(50.0, $analytics['ocr_quality']['correction_rate']);
    }

    public function test_crm_charts_use_a_fixed_twelve_month_scope(): void
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
            'submitted_at' => Carbon::parse('2025-08-20 02:00:00'),
        ]);
        $death->fields()->create([
            'name' => 'Deceased Full Name',
            'ocr_text' => 'Juan',
            'verified_value' => 'John',
            'ocr_confidence' => 40.0,
        ]);

        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard', [
                'period' => 'custom',
                'from' => '2026-08-01',
                'to' => '2026-08-10',
                'document_type' => 'birth',
                'ocr_model' => 'birth-model',
            ]))
            ->assertOk();

        $analytics = $response->viewData('analytics');
        $scope = $response->viewData('scope');

        $this->assertSame('365', $scope['period']);
        $this->assertSame(2, $analytics['headline']['records']);
        $this->assertSame(1, $analytics['headline']['period_records']);
        $this->assertSame(95.0, $analytics['ocr_quality']['average_confidence']);
        $this->assertSame(['Birth'], $analytics['by_document_type']
            ->where('total', '>', 0)
            ->pluck('type')
            ->map->shortLabel()
            ->values()
            ->all());
        $this->assertSame([$staff->name], $analytics['throughput']->pluck('name')->all());
        $this->assertCount(12, $analytics['trend']['labels']);
        $this->assertSame(1, collect($analytics['trend']['series'])->sum(fn (array $series) => array_sum($series['data'])));
    }

    public function test_super_admin_uses_the_same_focused_crm_dashboard(): void
    {
        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Average OCR confidence')
            ->assertSee('Human correction rate')
            ->assertSee('Active accounts')
            ->assertDontSee('Template usage and OCR review signals');

        $this->assertNull($response->viewData('system'));
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
