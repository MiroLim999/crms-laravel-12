<?php

namespace Tests\Feature;

use App\Models\CivilRecord;
use App\Models\OcrModel;
use App\Models\RecordField;
use App\Models\User;
use App\Services\Ocr\OcrModelPerformance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OcrModelPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_artifact_bound_test_evaluation_populates_the_radar(): void
    {
        OcrModel::create([
            'key' => 'evaluated-v1',
            'label' => 'Evaluated v1',
            'is_active' => true,
            'cer' => 0.1234,
            'wer' => 0.25,
            'exact_match' => 0.876,
            'evaluation_dataset' => 'civil-records-v1',
            'evaluation_split' => 'test',
            'evaluation_sample_count' => 5000,
            'evaluation_manifest_sha256' => str_repeat('a', 64),
            'evaluation_weights_sha256' => str_repeat('b', 64),
            'evaluated_at' => '2026-08-02 12:00:00',
        ]);

        $result = app(OcrModelPerformance::class)->summary();
        $profile = collect($result['models'])->firstWhere('key', 'evaluated-v1');

        $this->assertSame('evaluated-v1', $result['selected']);
        $this->assertTrue($profile['has_data']);
        $this->assertSame('evaluation', $profile['source']);
        $this->assertSame('Locked test set', $profile['source_label']);
        $this->assertSame('civil-records-v1 · test · 5,000 samples · evaluated Aug 2, 2026', $profile['evidence']);
        $this->assertSame([
            'character_accuracy' => 87.7,
            'word_accuracy' => 75.0,
            'exact_match' => 87.6,
        ], $profile['scores']);
    }

    public function test_metrics_without_complete_report_provenance_are_not_shown(): void
    {
        OcrModel::create([
            'key' => 'legacy-metrics',
            'cer' => 0.01,
            'wer' => 0.02,
            'exact_match' => 0.95,
        ]);

        $profile = collect(app(OcrModelPerformance::class)->summary()['models'])
            ->firstWhere('key', 'legacy-metrics');

        $this->assertFalse($profile['has_data']);
        $this->assertSame('No benchmark', $profile['source_label']);
        $this->assertSame([
            'character_accuracy' => null,
            'word_accuracy' => null,
            'exact_match' => null,
        ], $profile['scores']);
    }

    public function test_submitted_scan_results_are_never_used_as_benchmark_data(): void
    {
        OcrModel::create(['key' => 'scanned-model', 'label' => 'Scanned model']);
        $staff = User::factory()->staff()->create();
        $record = CivilRecord::factory()->submitted($staff)->create([
            'ocr_model_key' => 'scanned-model',
            'created_by' => $staff,
        ]);
        RecordField::factory()->create([
            'record_id' => $record,
            'ocr_text' => 'perfect match',
            'verified_value' => 'perfect match',
        ]);

        $profile = collect(app(OcrModelPerformance::class)->summary()['models'])
            ->firstWhere('key', 'scanned-model');

        $this->assertFalse($profile['has_data']);
        $this->assertSame('No benchmark', $profile['source_label']);
    }

    public function test_workspace_lists_a_remote_model_without_a_report_as_no_benchmark(): void
    {
        $remoteModels = [[
            'key' => 'remote-v1',
            'label' => 'Remote v1',
            'available' => true,
            'loaded' => false,
            'files' => ['config.json'],
        ]];
        Http::fake(['*' => Http::response([
            'status' => 'ok',
            'model_loaded' => false,
            'device' => 'cpu',
            'default' => 'remote-v1',
            'models' => $remoteModels,
        ])]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('ocr.index'))
            ->assertOk()
            ->assertViewHas('modelPerformance', function (array $payload): bool {
                return $payload['selected'] === 'remote-v1'
                    && $payload['models'][0]['key'] === 'remote-v1'
                    && $payload['models'][0]['has_data'] === false;
            });
    }
}
