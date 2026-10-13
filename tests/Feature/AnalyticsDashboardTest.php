<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\RecordStatus;
use App\Models\CivilRecord;
use App\Models\User;
use App\Services\ChangeRequestService;
use App\Services\DashboardAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Analytics are consolidated into the role-aware dashboard. Staff receive only
 * their own work summary; Admin and Super Admin receive the CRM overview for
 * a reporting period they choose for each panel.
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
            ->assertDontSee('Reporting period');

        $this->assertNull($staff->viewData('analytics'));

        foreach ([User::factory()->admin()->create(), User::factory()->superAdmin()->create()] as $user) {
            $response = $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertSee('Reporting period')
                ->assertSee('Total digitized records')
                ->assertSee('Digitization Volume &amp; Trend', escape: false)
                ->assertSee('Document Type Distribution')
                ->assertSee('OCR Quality')
                ->assertSee('Processing &amp; Throughput', escape: false);

            $this->assertNotNull($response->viewData('analytics'));
        }
    }

    public function test_it_counts_digitized_records_and_ocr_quality_signals(): void
    {
        Carbon::setTestNow('2026-08-11 04:00:00');
        $staff = User::factory()->staff()->create();

        $submitted = $this->record($staff, DocumentType::Birth);

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

    public function test_twelve_months_is_the_default_period_in_whole_calendar_months(): void
    {
        Carbon::setTestNow('2026-08-11 04:00:00');
        $staff = User::factory()->staff()->create();

        $this->record($staff, DocumentType::Birth, ['submitted_at' => Carbon::parse('2026-08-05 02:00:00')]);
        // Before the window, which starts on 1 Sep 2025.
        $this->record($staff, DocumentType::Death, ['submitted_at' => Carbon::parse('2025-08-20 02:00:00')]);

        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Showing <strong>1 Sep 2025 – 11 Aug 2026</strong>', escape: false)
            ->assertSee('1 submitted in the last 12 months');

        $scope = $response->viewData('scopes')['trend'];
        $analytics = $response->viewData('analytics');

        $this->assertSame('365', $scope['period']);
        $this->assertSame('Last 12 months', $scope['badge']);
        $this->assertSame(2, $analytics['headline']['records']);
        $this->assertSame(1, $analytics['headline']['period_records']);
        $this->assertSame('month', $analytics['trend']['mode']);
        $this->assertCount(12, $analytics['trend']['labels']);
        $this->assertSame('Sep 2025', $analytics['trend']['labels'][0]);
        $this->assertSame('Aug 2026', $analytics['trend']['labels'][11]);
        $this->assertNull($analytics['trend']['starts_at']);
    }

    public function test_the_period_filter_scopes_every_period_chart(): void
    {
        Carbon::setTestNow('2026-08-11 04:00:00');
        $recentStaff = User::factory()->staff()->create();
        $olderStaff = User::factory()->staff()->create();
        $admin = User::factory()->admin()->create();

        $recent = $this->record($recentStaff, DocumentType::Birth, ['submitted_at' => Carbon::parse('2026-08-05 02:00:00')]);
        $recent->fields()->create([
            'name' => 'Child Full Name',
            'ocr_text' => 'Ana',
            'verified_value' => 'Ana',
            'ocr_confidence' => 95.0,
        ]);

        $older = $this->record($olderStaff, DocumentType::Death, ['submitted_at' => Carbon::parse('2026-06-01 02:00:00')]);
        $older->fields()->create([
            'name' => 'Deceased Full Name',
            'ocr_text' => 'Juan',
            'verified_value' => 'John',
            'ocr_confidence' => 40.0,
        ]);

        // Last 30 days, 13 Jul to 11 Aug 2026, charted day by day.
        $response = $this->actingAs($admin)
            ->get(route('dashboard', $this->everyPanel(['period' => '30'])))
            ->assertOk()
            ->assertSee('Showing <strong>13 Jul 2026 – 11 Aug 2026</strong>', escape: false)
            ->assertSee('1 submitted in the last 30 days');

        $analytics = $response->viewData('analytics');
        $this->assertSame('Last 30 days', $response->viewData('scopes')['trend']['badge']);
        $this->assertSame(2, $analytics['headline']['records']);
        $this->assertSame(1, $analytics['headline']['period_records']);
        $this->assertSame(['Birth'], $this->typesWithRecords($analytics));
        $this->assertSame([$recentStaff->name], $analytics['throughput']->pluck('name')->all());
        $this->assertSame(95.0, $analytics['ocr_quality']['average_confidence']);
        $this->assertSame(0.0, $analytics['ocr_quality']['correction_rate']);
        $this->assertSame('day', $analytics['trend']['mode']);
        $this->assertCount(30, $analytics['trend']['labels']);
        $this->assertSame(['Birth'], array_column($analytics['trend']['series'], 'name'));

        // Custom dates hold only the older record, its submitter and its reading.
        $response = $this->actingAs($admin)
            ->get(route('dashboard', $this->everyPanel(['period' => 'custom', 'from' => '2026-05-01', 'to' => '2026-06-30'])))
            ->assertOk()
            ->assertSee('1 submitted in the selected period');

        $analytics = $response->viewData('analytics');
        $this->assertSame('1 May 2026 – 30 Jun 2026', $response->viewData('scopes')['trend']['badge']);
        $this->assertSame(1, $analytics['headline']['period_records']);
        $this->assertSame(['Death'], $this->typesWithRecords($analytics));
        $this->assertSame([$olderStaff->name], $analytics['throughput']->pluck('name')->all());
        $this->assertSame(40.0, $analytics['ocr_quality']['average_confidence']);
        $this->assertSame(100.0, $analytics['ocr_quality']['correction_rate']);

        // Last 90 days, 14 May to 11 Aug 2026, holds both.
        $analytics = $this->actingAs($admin)
            ->get(route('dashboard', $this->everyPanel(['period' => '90'])))
            ->viewData('analytics');
        $this->assertSame(2, $analytics['headline']['period_records']);
    }

    public function test_the_trend_badge_compares_periods_of_the_same_length(): void
    {
        Carbon::setTestNow('2026-08-11 04:00:00');
        $staff = User::factory()->staff()->create();
        $admin = User::factory()->admin()->create();

        foreach (['2026-08-01', '2026-08-10', '2026-07-01', '2026-06-05', '2025-06-15'] as $day) {
            $this->record($staff, DocumentType::Birth, ['submitted_at' => Carbon::parse($day.' 02:00:00')]);
        }

        // The last 30 days against the 30 days before them: 2 against 1.
        $headline = $this->actingAs($admin)
            ->get(route('dashboard', ['trend_period' => '30']))
            ->assertSee('+100.0% vs the previous 30 days')
            ->viewData('analytics')['headline'];
        $this->assertSame([2, 1, 100.0], [$headline['period_records'], $headline['previous_records'], $headline['change']]);

        // Twelve months against the same dates a year earlier: 4 against 1.
        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertSee('+300.0% vs the same dates last year');

        // Nothing earlier to compare with says so instead of a percentage.
        $this->actingAs($admin)
            ->get(route('dashboard', ['trend_period' => 'custom', 'trend_from' => '2025-06-01', 'trend_to' => '2025-06-30']))
            ->assertSee('None in the previous 30 days');
    }

    public function test_the_correction_rate_counts_only_changes_made_while_verifying(): void
    {
        Carbon::setTestNow('2026-08-11 04:00:00');
        $staff = User::factory()->staff()->create();
        $record = $this->record($staff, DocumentType::Birth);

        $fields = collect([
            ['Child Full Name', 'Maria Santos', 'Maria Santos'],
            ['Mother Full Name', 'Ana Reyes', 'Ana Reyes'],
            // Staff fixed only the capital letters: still a correction.
            ['Father Full Name', 'juan dela cruz', 'Juan dela Cruz'],
            ['Place of Birth', 'Manila', 'Manila'],
        ])->map(fn (array $field) => $record->fields()->create([
            'name' => $field[0],
            'ocr_text' => $field[1],
            'verified_value' => $field[2],
            'ocr_confidence' => 90.0,
        ]));

        // Later, an approved change request rewrites two values staff had
        // accepted as read. That is an amendment, not a verification fix.
        $service = app(ChangeRequestService::class);
        $request = $service->open($record->load('fields'), [
            $fields[0]->getKey() => 'Mariah Santos',
            $fields[1]->getKey() => 'Anna Reyes',
        ], 'The register spells both names differently.', $staff);
        $service->approve($request, User::factory()->admin()->create());
        $this->assertSame('Mariah Santos', $fields[0]->fresh()->verified_value);

        $quality = $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->viewData('analytics')['ocr_quality'];

        $this->assertSame(4, $quality['comparable_fields']);
        $this->assertSame(1, $quality['corrected_fields']);
        $this->assertSame(25.0, $quality['correction_rate']);
    }

    public function test_each_document_type_keeps_its_colour_on_every_chart(): void
    {
        Carbon::setTestNow('2026-08-11 04:00:00');
        $staff = User::factory()->staff()->create();

        $this->record($staff, DocumentType::Birth, ['submitted_at' => Carbon::parse('2026-08-01 02:00:00')]);
        foreach (['2026-08-02', '2026-08-03', '2026-08-04'] as $day) {
            $this->record($staff, DocumentType::Death, ['submitted_at' => Carbon::parse($day.' 02:00:00')]);
        }

        $response = $this->actingAs(User::factory()->admin()->create())->get(route('dashboard'));
        $analytics = $response->viewData('analytics');

        $line = fn (string $type) => collect($analytics['trend']['series'])->firstWhere('name', $type)['color'];
        $slice = fn (string $type) => collect($analytics['type_slices'])->firstWhere('label', $type)['color'];
        $row = fn (string $type) => $analytics['by_document_type']
            ->first(fn (array $row) => $row['type']->shortLabel() === $type)['color'];

        // Death has the most records, so it leads the donut and the list...
        $this->assertSame('Death', $analytics['type_slices'][0]['label']);
        $this->assertSame('Death', $analytics['by_document_type'][0]['type']->shortLabel());

        // ...but every type keeps one colour, taken from its fixed place in the
        // type list (Birth is first), not from its rank.
        $this->assertSame('#2563eb', $line('Birth'));
        foreach (['Birth', 'Death'] as $type) {
            $this->assertSame($line($type), $slice($type));
            $this->assertSame($line($type), $row($type));
        }
        $this->assertNotSame($line('Birth'), $line('Death'));
        $response->assertSee('--dashboard-type-color: '.$line('Death'), escape: false);
    }

    public function test_the_trend_starts_at_the_first_record_and_leaves_out_empty_types(): void
    {
        Carbon::setTestNow('2026-08-11 04:00:00');
        $staff = User::factory()->staff()->create();
        $this->record($staff, DocumentType::Birth, ['submitted_at' => Carbon::parse('2026-06-15 02:00:00')]);

        $trend = $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertSee('The first archived record is from 15 Jun 2026')
            ->viewData('analytics')['trend'];

        $this->assertSame(['Jun 2026', 'Jul 2026', 'Aug 2026'], $trend['labels']);
        // Death and Marriage have no records, so they get no flat line at zero.
        $this->assertSame(['Birth'], array_column($trend['series'], 'name'));
    }

    public function test_a_bad_period_shows_its_message_and_the_default_period(): void
    {
        Carbon::setTestNow('2026-08-11 04:00:00');
        $admin = User::factory()->admin()->create();

        $cases = [
            [['period' => 'custom'], 'Choose the first day of the custom period.'],
            [['period' => '7'], 'Choose one of the listed periods.'],
            [['period' => 'custom', 'from' => '2026-08-10', 'to' => '2026-08-01'], 'The end date must be on or after the start date.'],
            [['period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-09-01'], 'The end date cannot be after today.'],
            [['period' => 'custom', 'from' => '1999-12-31', 'to' => '2026-08-01'], 'The start date cannot be before 1 Jan 2000.'],
        ];

        foreach (DashboardAnalytics::PANELS as $panel) {
            foreach ($cases as [$query, $message]) {
                $response = $this->actingAs($admin)
                    ->get(route('dashboard', $this->prefixed($panel, $query)))
                    ->assertOk()
                    ->assertSee($message);

                $this->assertSame('365', $response->viewData('scopes')[$panel]['period']);
            }
        }

        // The custom dates keep what was typed, so they can be corrected.
        $this->actingAs($admin)
            ->get(route('dashboard', ['ocr_period' => 'custom', 'ocr_from' => '2026-08-10', 'ocr_to' => '2026-08-01']))
            ->assertSee('The end date must be on or after the start date.')
            ->assertSee('value="2026-08-10"', escape: false)
            ->assertSee('value="2026-08-01"', escape: false);

        // Dates mean nothing to a preset period, so they are ignored.
        $response = $this->actingAs($admin)
            ->get(route('dashboard', ['trend_period' => '30', 'trend_from' => 'not a date']))
            ->assertOk()
            ->assertDontSee('Enter the start date');
        $this->assertSame('30', $response->viewData('scopes')['trend']['period']);
    }

    public function test_each_panel_keeps_its_own_period(): void
    {
        Carbon::setTestNow('2026-08-11 04:00:00');
        $recentStaff = User::factory()->staff()->create();
        $olderStaff = User::factory()->staff()->create();

        $this->record($recentStaff, DocumentType::Birth, ['submitted_at' => Carbon::parse('2026-08-05 02:00:00')]);
        $this->record($olderStaff, DocumentType::Death, ['submitted_at' => Carbon::parse('2026-06-01 02:00:00')]);

        // Only the throughput panel is narrowed; the type panel stays on 12 months.
        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard', ['staff_period' => '30']))
            ->assertOk();

        $scopes = $response->viewData('scopes');
        $analytics = $response->viewData('analytics');

        $this->assertSame(['30', '365', '365', '365'], [$scopes['staff']['period'], $scopes['trend']['period'], $scopes['types']['period'], $scopes['ocr']['period']]);
        $this->assertSame([$recentStaff->name], $analytics['throughput']->pluck('name')->all());
        $this->assertSame(['Birth', 'Death'], collect($this->typesWithRecords($analytics))->sort()->values()->all());
        $this->assertSame(2, $analytics['headline']['period_records']);

        // A panel's form keeps the other panels' choices and returns to itself.
        $response->assertSee('name="staff_period"', escape: false)
            ->assertSee('/dashboard#panel-staff', escape: false)
            ->assertSee('/dashboard#panel-ocr', escape: false);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard', ['staff_period' => '30', 'types_period' => '90']))
            ->assertSee('<input type="hidden" name="staff_period" value="30">', escape: false)
            ->assertSee('<input type="hidden" name="types_period" value="90">', escape: false);

        // One panel's bad period leaves the others as chosen.
        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard', ['types_period' => '7', 'ocr_period' => '30']))
            ->assertSee('Choose one of the listed periods.');
        $this->assertSame(['365', '30'], [$response->viewData('scopes')['types']['period'], $response->viewData('scopes')['ocr']['period']]);
    }
    public function test_super_admin_uses_the_same_focused_crm_dashboard(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Average OCR confidence')
            ->assertSee('Human correction rate')
            ->assertSee('Active accounts')
            ->assertDontSee('Template usage and OCR review signals');
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

    /**
     * The same period for every panel, as the dashboard's query string carries it.
     *
     * @param  array<string, string>  $query  period, from and to, unprefixed
     * @return array<string, string>
     */
    private function everyPanel(array $query): array
    {
        return array_merge(...array_map(fn (string $panel) => $this->prefixed($panel, $query), DashboardAnalytics::PANELS));
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, string>
     */
    private function prefixed(string $panel, array $query): array
    {
        return array_combine(array_map(fn (string $key) => "{$panel}_{$key}", array_keys($query)), $query);
    }

    /**
     * @param  array<string, mixed>  $analytics
     * @return list<string>
     */
    private function typesWithRecords(array $analytics): array
    {
        return $analytics['by_document_type']
            ->where('total', '>', 0)
            ->map(fn (array $row) => $row['type']->shortLabel())
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function record(User $staff, DocumentType $type, array $overrides = []): CivilRecord
    {
        return CivilRecord::create([
            'doc_type' => $type->value,
            'status' => RecordStatus::Submitted->value,
            'created_by' => $staff->getKey(),
            'submitted_by' => $staff->getKey(),
            'submitted_at' => now(),
            ...$overrides,
        ]);
    }
}
