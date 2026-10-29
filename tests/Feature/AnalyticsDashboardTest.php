<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\RecordStatus;
use App\Models\AuditLog;
use App\Models\CivilRecord;
use App\Models\DocumentTemplate;
use App\Models\OcrModel;
use App\Models\User;
use App\Services\ChangeRequestService;
use App\Services\DashboardAnalytics;
use Database\Seeders\DocumentTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
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
                ->assertSee('OCR Quality')
                ->assertSee('Change requests')
                ->assertSee('Recent changes')
                // The total card's links say where they go.
                ->assertSee('View report')
                ->assertSee('Manage users');

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
            // The chip says what it counts.
            ->assertSee('<strong>1</strong> field below 80%', escape: false)
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
            ->assertSee('1</strong> record submitted', escape: false)
            ->assertDontSee('by month');

        $scope = $response->viewData('scopes')['trend'];
        $analytics = $response->viewData('analytics');
        // The archive is older than the period, so the chart covers all of it.
        $this->assertSame('1 Sep 2025 – 11 Aug 2026', $analytics['trend']['range_label']);

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

    public function test_the_total_card_shows_what_the_archive_holds_by_type(): void
    {
        Carbon::setTestNow('2026-08-11 04:00:00');
        $staff = User::factory()->staff()->create();

        $this->record($staff, DocumentType::Birth, ['submitted_at' => Carbon::parse('2026-08-05 02:00:00')]);
        $this->record($staff, DocumentType::Death, ['submitted_at' => Carbon::parse('2026-08-06 02:00:00')]);
        // Long before the trend's period, and still in the archive.
        $this->record($staff, DocumentType::Death, ['submitted_at' => Carbon::parse('2024-03-01 02:00:00')]);

        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard', ['trend_period' => '30']))
            ->assertOk()
            ->assertSee('66.7%')
            ->assertSee('33.3%');

        // All time, largest first; each type keeps its fixed colour, not its rank's.
        $types = $response->viewData('analytics')['archive_types'];
        $this->assertSame([['Death', 2], ['Birth', 1]], array_map(fn (array $type) => [$type['name'], $type['total']], $types));
        $this->assertSame('#2563eb', $types[1]['color']);

        // The trend card counts only its own period.
        $response->assertSee('2</strong> records submitted', escape: false);
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
            ->assertSee('1</strong> record submitted', escape: false)
            ->assertSee('13 Jul – 11 Aug 2026')
            ->assertDontSee('by day');

        $analytics = $response->viewData('analytics');
        $this->assertSame('Last 30 days', $response->viewData('scopes')['trend']['badge']);
        $this->assertSame(2, $analytics['headline']['records']);
        $this->assertSame(1, $analytics['headline']['period_records']);
        $this->assertSame(95.0, $analytics['ocr_quality']['average_confidence']);
        $this->assertSame(0.0, $analytics['ocr_quality']['correction_rate']);
        $this->assertSame('day', $analytics['trend']['mode']);
        $this->assertCount(30, $analytics['trend']['labels']);
        $this->assertSame(['Birth'], array_column($analytics['trend']['series'], 'name'));

        // Custom dates hold only the older record, its submitter and its reading.
        $response = $this->actingAs($admin)
            ->get(route('dashboard', $this->everyPanel(['period' => 'custom', 'from' => '2026-05-01', 'to' => '2026-06-30'])))
            ->assertOk()
            ->assertSee('1</strong> record submitted', escape: false);

        $analytics = $response->viewData('analytics');
        $this->assertSame('1 May 2026 – 30 Jun 2026', $response->viewData('scopes')['trend']['badge']);
        $this->assertSame(1, $analytics['headline']['period_records']);
        $this->assertSame(['Death'], array_column($analytics['trend']['series'], 'name'));
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

        // The archive opens on 1 Aug 2024, before every earlier period below.
        foreach (['2026-08-01', '2026-08-10', '2026-07-01', '2026-06-05', '2025-06-15', '2024-08-01'] as $day) {
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

    public function test_the_trend_badge_waits_until_the_archive_covers_the_earlier_period(): void
    {
        Carbon::setTestNow('2026-08-11 04:00:00');
        $staff = User::factory()->staff()->create();
        $admin = User::factory()->admin()->create();

        // The archive opens on 20 Jul 2026, inside the last 30 days.
        foreach (['2026-07-20', '2026-08-05'] as $day) {
            $this->record($staff, DocumentType::Birth, ['submitted_at' => Carbon::parse($day.' 02:00:00')]);
        }

        // Against a year the archive never saw, a comparison says nothing.
        $response = $this->actingAs($admin)->get(route('dashboard'))->assertOk();
        $this->assertFalse($response->viewData('analytics')['headline']['comparable']);
        $response->assertDontSee('the same dates last year');

        // The same for the 30 days before 13 Jul, which all predate the archive.
        $response = $this->actingAs($admin)->get(route('dashboard', ['trend_period' => '30']))->assertOk();
        $this->assertFalse($response->viewData('analytics')['headline']['comparable']);
        $response->assertDontSee('the previous 30 days');
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

    public function test_each_document_type_keeps_its_own_colour(): void
    {
        Carbon::setTestNow('2026-08-11 04:00:00');
        $staff = User::factory()->staff()->create();

        $this->record($staff, DocumentType::Birth, ['submitted_at' => Carbon::parse('2026-08-01 02:00:00')]);
        foreach (['2026-08-02', '2026-08-03', '2026-08-04'] as $day) {
            $this->record($staff, DocumentType::Death, ['submitted_at' => Carbon::parse($day.' 02:00:00')]);
        }

        $response = $this->actingAs(User::factory()->admin()->create())->get(route('dashboard'));
        $line = fn (string $type) => collect($response->viewData('analytics')['trend']['series'])
            ->firstWhere('name', $type)['color'];

        // Death has the most records, but every type keeps one colour, taken
        // from its fixed place in the type list (Birth is first), not its rank.
        $this->assertSame('#2563eb', $line('Birth'));
        $this->assertNotSame($line('Birth'), $line('Death'));
        $response->assertSee('--dashboard-type-color: '.$line('Death'), escape: false);
    }

    public function test_the_trend_starts_at_the_first_record_and_leaves_out_empty_types(): void
    {
        Carbon::setTestNow('2026-08-11 04:00:00');
        $staff = User::factory()->staff()->create();
        $this->record($staff, DocumentType::Birth, ['submitted_at' => Carbon::parse('2026-06-15 02:00:00')]);

        // The header gives the dates the chart covers, not the period's.
        $trend = $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertSee('15 Jun – 11 Aug 2026')
            ->assertDontSee('The first archived record')
            ->viewData('analytics')['trend'];

        $this->assertSame('15 Jun – 11 Aug 2026', $trend['range_label']);
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

        // Only the change requests panel is narrowed; the trend stays on 12 months.
        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard', ['requests_period' => '30']))
            ->assertOk();

        $scopes = $response->viewData('scopes');
        $analytics = $response->viewData('analytics');

        $this->assertSame(['30', '365', '365'], [$scopes['requests']['period'], $scopes['trend']['period'], $scopes['ocr']['period']]);
        $this->assertSame(['Birth', 'Death'], array_column($analytics['trend']['series'], 'name'));
        $this->assertSame(2, $analytics['headline']['period_records']);

        // A panel's form keeps the other panels' choices and returns to itself.
        $response->assertSee('name="requests_period"', escape: false)
            ->assertSee('/dashboard#panel-requests', escape: false)
            ->assertSee('/dashboard#panel-ocr', escape: false);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard', ['requests_period' => '30', 'ocr_period' => '90']))
            ->assertSee('<input type="hidden" name="requests_period" value="30">', escape: false)
            ->assertSee('<input type="hidden" name="ocr_period" value="90">', escape: false);

        // One panel's bad period leaves the others as chosen.
        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard', ['trend_period' => '7', 'ocr_period' => '30']))
            ->assertSee('Choose one of the listed periods.');
        $this->assertSame(['365', '30'], [$response->viewData('scopes')['trend']['period'], $response->viewData('scopes')['ocr']['period']]);
    }

    public function test_change_requests_show_the_queue_now_and_the_decisions_in_the_period(): void
    {
        Carbon::setTestNow('2026-08-11 04:00:00');
        $staff = User::factory()->staff()->create();
        $admin = User::factory()->admin()->create();
        $service = app(ChangeRequestService::class);

        // Opened at a set time. A is approved after 3 hours, B rejected after
        // 10 minutes and E, long ago, after a day. C is withdrawn and D is
        // still waiting.
        $open = function (string $openedAt) use ($staff, $service) {
            Carbon::setTestNow($openedAt);
            $record = $this->record($staff, DocumentType::Birth);
            $field = $record->fields()->create([
                'name' => 'Child Full Name', 'ocr_text' => 'Ana Cruz', 'verified_value' => 'Ana Cruz', 'ocr_confidence' => 90.0,
            ]);

            return $service->open($record->load('fields'), [$field->getKey() => 'Anna Cruz'], 'A spelling fix.', $staff);
        };

        $a = $open('2026-08-01 02:00:00');
        Carbon::setTestNow('2026-08-01 05:00:00');
        $service->approve($a, $admin);

        $b = $open('2026-08-05 02:00:00');
        Carbon::setTestNow('2026-08-05 02:10:00');
        $service->reject($b, $admin, 'Not what the register says.');

        $c = $open('2026-08-06 02:00:00');
        $service->withdraw($c, $staff);

        $open('2026-08-07 02:00:00');

        $e = $open('2026-05-01 02:00:00');
        Carbon::setTestNow('2026-05-02 02:00:00');
        $service->approve($e, $admin);

        Carbon::setTestNow('2026-08-11 04:00:00');

        // Twelve months holds all three decisions.
        $response = $this->actingAs($admin)->get(route('dashboard'))->assertOk();
        $requests = $response->viewData('analytics')['change_requests'];

        $this->assertSame(1, $requests['waiting']);
        $this->assertSame('2026-08-07 02:00:00', $requests['oldest_waiting_at']->format('Y-m-d H:i:s'));
        $this->assertSame([2, 1, 1], [$requests['approved'], $requests['rejected'], $requests['withdrawn']]);
        $response->assertSee('waiting for a decision')
            ->assertSee('The oldest has waited 4 days.')
            ->assertSee(route('change-requests.index', ['status' => 'pending']))
            ->assertDontSee('Average wait');

        // The last 30 days leave out E, but not the queue, which has no period.
        $requests = $this->actingAs($admin)
            ->get(route('dashboard', ['requests_period' => '30']))
            ->viewData('analytics')['change_requests'];

        $this->assertSame(1, $requests['waiting']);
        $this->assertSame([1, 1, 1], [$requests['approved'], $requests['rejected'], $requests['withdrawn']]);
    }

    public function test_change_requests_say_so_when_nothing_is_waiting_or_decided(): void
    {
        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Nothing is waiting. You are all caught up.')
            ->assertSee('All requests')
            ->assertDontSee('The oldest has waited');

        $requests = $response->viewData('analytics')['change_requests'];
        $this->assertSame(
            [0, 0, 0, 0],
            [$requests['waiting'], $requests['approved'], $requests['rejected'], $requests['withdrawn']],
        );
    }

    public function test_recent_changes_list_the_newest_setup_changes_and_skip_sign_ins(): void
    {
        $log = function (string $action, string $description, string $at): void {
            (new AuditLog)->forceFill([
                'actor_name' => 'Super Admin',
                'actor_role' => 'super_admin',
                'action' => $action,
                'description' => $description,
                'created_at' => $at,
            ])->save();
        };

        // Eight changes, so only the newest five can be listed. Sign-ins and
        // archived records come later still, but they are not changes.
        foreach (range(1, 8) as $number) {
            $log('template.updated', "Edited template number {$number}.", "2026-08-0{$number} 02:00:00");
        }
        $log('auth.login', 'Signed in as Super Admin.', '2026-08-09 02:00:00');
        $log('record.submitted', 'Submitted and locked a Birth record.', '2026-08-09 03:00:00');

        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Recent changes')
            ->assertSee('Edited template number 8.')
            ->assertSee('Edited template number 4.')
            ->assertDontSee('Edited template number 3.')
            ->assertDontSee('Signed in as Super Admin.')
            ->assertDontSee('Submitted and locked a Birth record.')
            ->assertSee(route('audit.index'));

        $changes = $response->viewData('analytics')['recent_changes'];
        $this->assertCount(5, $changes);
        $this->assertSame('Edited template number 8.', $changes->first()->description);
    }

    public function test_recent_changes_have_an_empty_state(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('No changes yet');
    }

    public function test_super_admin_starts_a_new_document_from_the_dashboard(): void
    {
        $this->seed(DocumentTemplateSeeder::class);
        $superAdmin = User::factory()->superAdmin()->create();

        // With every type ready, the card names none of them.
        $this->actingAs($superAdmin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('href="'.route('documents.create').'" id="dashboard-new-document"', escape: false)
            ->assertSee('Start scanning')
            ->assertSee('3 types ready')
            ->assertDontSee('dashboard-scan-note');

        // Marriage loses its template, so the New Document page cannot start one.
        DocumentTemplate::activeFor(DocumentType::Marriage)->update(['is_active' => false]);

        $response = $this->actingAs($superAdmin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('2 of 3 ready')
            ->assertSee('Marriage has no template yet.');

        $this->assertSame(
            [['Birth', true], ['Death', true], ['Marriage', false]],
            array_map(fn (array $type) => [$type['label'], $type['ready']], $response->viewData('scanTypes')),
        );
    }

    public function test_the_new_document_card_shows_what_came_in_lately(): void
    {
        Carbon::setTestNow('2026-08-11 04:00:00');
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get(route('dashboard'))
            ->assertSeeInOrder(['<dt>added this month</dt>', '<dd data-dashboard-count>0</dd>', '<dt>last added</dt>', 'None yet'], escape: false);

        $staff = User::factory()->staff()->create();
        $this->record($staff, DocumentType::Death, ['submitted_at' => Carbon::parse('2026-07-20 04:00:00')]);
        $this->record($staff, DocumentType::Birth, ['submitted_at' => Carbon::parse('2026-08-08 04:00:00')]);

        // Only August's record counts this month. The newest is three days old,
        // and its exact time is given in Manila.
        $this->actingAs($superAdmin)
            ->get(route('dashboard'))
            ->assertSeeInOrder(['<dt>added this month</dt>', '<dd data-dashboard-count>1</dd>', '<dt>last added</dt>'], escape: false)
            ->assertSee('title="8 Aug 2026, 12:00 PM">3 days ago</time>', escape: false);
    }

    public function test_admin_gets_no_new_document_card_because_admin_cannot_scan(): void
    {
        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('dashboard-new-document')
            ->assertDontSee(route('documents.create'));

        $this->assertNull($response->viewData('scanTypes'));
    }
    public function test_super_admin_uses_the_same_focused_crm_dashboard(): void
    {
        User::factory()->staff()->create(['is_active' => false]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Average OCR confidence')
            ->assertSee('Human correction rate')
            ->assertSee('Active accounts')
            // One of the two accounts is active.
            ->assertSee('50.0%</strong> active', escape: false)
            ->assertDontSee('Template usage and OCR review signals');
    }

    public function test_ocr_performance_sets_the_model_in_use_against_base(): void
    {
        $this->evaluatedModel(['key' => 'finetuned-v2', 'label' => 'Finetuned v2', 'is_active' => true], 0.1, 0.3, 0.5);
        $this->evaluatedModel(['key' => 'base', 'label' => 'base'], 0.2, 0.6, 0.25);
        // Scored too, but not in use, so it stays off the chart.
        $this->evaluatedModel(['key' => 'finetuned-v1', 'label' => 'Finetuned v1'], 0.05, 0.1, 0.9);

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Finetuned v2: characters 90.0%, words 70.0%, exact match 50.0%; TrOCR Base: characters 80.0%, words 40.0%, exact match 25.0%');

        $series = $this->chartData($response)['ocrPerformance']['series'];
        $this->assertSame(
            [['model', 'Finetuned v2'], ['base', 'TrOCR Base']],
            array_map(fn (array $item) => [$item['role'], $item['name']], $series),
        );
        $this->assertEquals(['character_accuracy' => 90.0, 'word_accuracy' => 70.0, 'exact_match' => 50.0], $series[0]['scores']);
    }

    public function test_ocr_performance_never_draws_a_score_nobody_measured(): void
    {
        // The model in use has no test results; Base does.
        OcrModel::create(['key' => 'finetuned-v3', 'label' => 'Finetuned v3', 'is_active' => true]);
        $base = $this->evaluatedModel(['key' => 'base', 'label' => 'base'], 0.2, 0.6, 0.25);
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertSee('Finetuned v3</strong>', escape: false)
            ->assertSee('no test scores');
        $this->assertSame(['base'], array_column($this->chartData($response)['ocrPerformance']['series'], 'role'));

        // With nothing measured, the card says so and draws no chart at all.
        $base->delete();
        $response = $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertSee('No test scores yet')
            ->assertSee('Finetuned v3 has no test results yet.')
            ->assertDontSee('id="dashboard-ocr-radar-chart"', escape: false);
        $this->assertSame([], $this->chartData($response)['ocrPerformance']['series']);
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
     * What the dashboard hands its chart script.
     *
     * @return array<string, mixed>
     */
    private function chartData(TestResponse $response): array
    {
        preg_match('#<script id="dashboard-chart-data" type="application/json">(.*?)</script>#s', $response->getContent(), $match);

        return json_decode($match[1] ?? 'null', true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * An OCR model with complete results from the locked test set.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function evaluatedModel(array $attributes, float $cer, float $wer, float $exactMatch): OcrModel
    {
        return OcrModel::create([
            ...$attributes,
            'cer' => $cer,
            'wer' => $wer,
            'exact_match' => $exactMatch,
            'evaluation_dataset' => 'civil-records-v1',
            'evaluation_split' => 'test',
            'evaluation_sample_count' => 5000,
            'evaluation_manifest_sha256' => str_repeat('a', 64),
            'evaluation_weights_sha256' => str_repeat('b', 64),
            'evaluated_at' => '2026-10-13 10:00:00',
        ]);
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
