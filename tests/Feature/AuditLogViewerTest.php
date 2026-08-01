<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The audit viewer reads the trail and nothing more. Its most important property
 * is what it cannot do.
 */
class AuditLogViewerTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_are_denied(): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->get(route('audit.index'))
            ->assertForbidden();
    }

    public function test_admin_and_super_admin_may_view_it(): void
    {
        foreach ([User::factory()->admin()->create(), User::factory()->superAdmin()->create()] as $user) {
            $this->actingAs($user)->get(route('audit.index'))->assertOk();
        }
    }

    public function test_it_paginates(): void
    {
        $actor = User::factory()->staff()->create();
        $this->entries($actor, 30);

        $first = $this->actingAs(User::factory()->admin()->create())
            ->get(route('audit.index'))
            ->assertOk()
            ->viewData('entries');

        $this->assertSame(30, $first->total());
        $this->assertCount(25, $first->items());
        $this->assertTrue($first->hasMorePages());

        $second = $this->actingAs(User::factory()->admin()->create())
            ->get(route('audit.index', ['page' => 2]))
            ->assertOk()
            ->viewData('entries');

        $this->assertCount(5, $second->items());
    }

    public function test_it_filters_by_actor_action_and_free_text(): void
    {
        $staff = User::factory()->staff()->create(['name' => 'Nina Staffer']);
        $other = User::factory()->staff()->create(['name' => 'Otto Staffer']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($staff);
        app(AuditLogger::class)->log('record.submitted', description: 'Submitted a birth certificate.');

        $this->actingAs($other);
        app(AuditLogger::class)->log('change_request.created', description: 'Requested a surname fix.');

        $byActor = $this->actingAs($admin)
            ->get(route('audit.index', ['actor' => $staff->getKey()]))
            ->assertOk()
            ->viewData('entries');
        $this->assertSame(1, $byActor->total());
        $this->assertSame('record.submitted', $byActor->first()->action);

        $byAction = $this->actingAs($admin)
            ->get(route('audit.index', ['action' => 'change_request.created']))
            ->assertOk()
            ->viewData('entries');
        $this->assertSame(1, $byAction->total());

        $bySearch = $this->actingAs($admin)
            ->get(route('audit.index', ['q' => 'surname']))
            ->assertOk()
            ->viewData('entries');
        $this->assertSame(1, $bySearch->total());
        $this->assertSame($other->getKey(), $bySearch->first()->user_id);
    }

    public function test_before_and_after_values_are_shown(): void
    {
        $staff = User::factory()->staff()->create();
        $this->actingAs($staff);

        app(AuditLogger::class)->log(
            'user.updated',
            $staff,
            old: ['name' => 'Before Name'],
            new: ['name' => 'After Name'],
        );

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('audit.index'))
            ->assertOk()
            ->assertSee('Before Name')
            ->assertSee('After Name');
    }

    /**
     * The trail is append-only, so no route may exist that could rewrite it. This
     * asserts the absence directly rather than trusting the view markup.
     */
    public function test_no_route_can_modify_the_trail(): void
    {
        $auditRoutes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'audit'))
            ->map(fn ($route) => $route->methods()[0].' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame(['GET audit'], $auditRoutes);
    }

    private function entries(User $actor, int $count): void
    {
        $this->actingAs($actor);

        for ($i = 0; $i < $count; $i++) {
            app(AuditLogger::class)->log('record.submitted', description: "Submitted record {$i}.");
        }
    }
}
