<?php

namespace Tests\Feature;

use App\Enums\RoleSlug;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The capability matrix from .kiro/steering/product.md, asserted end to end.
 *
 * This is the load-bearing test in the suite. The separation of duties it guards
 * is what makes the audit trail legally meaningful:
 *
 *   - Admin must never be able to enter or edit record data.
 *   - Staff must never reach oversight functions.
 *   - Templates and OCR model management are Super Admin only.
 *
 * If a change makes one of these fail, the change is wrong - not the test.
 */
class CapabilityMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every gated route, with the status each role must receive.
     *
     * @return array<string, array{string, int, int, int}>
     */
    public static function routeMatrix(): array
    {
        //                                  route              staff admin super
        return [
            'upload & process documents' => ['documents.create', 200, 403, 200],
            'search records archive' => ['records.index', 200, 200, 200],
            'change requests' => ['change-requests.index', 200, 200, 200],
            'analytics dashboard' => ['analytics.index', 403, 200, 200],
            'generate reports' => ['reports.index', 403, 200, 200],
            'manage user accounts' => ['users.index', 403, 200, 200],
            'view audit log' => ['audit.index', 403, 200, 200],
            'document template builder' => ['templates.index', 403, 403, 200],
            'OCR model management' => ['ocr.index', 403, 403, 200],
        ];
    }

    #[DataProvider('routeMatrix')]
    public function test_route_access_matches_the_capability_matrix(
        string $route,
        int $staff,
        int $admin,
        int $superAdmin,
    ): void {
        $expected = [
            RoleSlug::Staff->value => $staff,
            RoleSlug::Admin->value => $admin,
            RoleSlug::SuperAdmin->value => $superAdmin,
        ];

        foreach ($expected as $slug => $status) {
            $user = User::factory()->role(RoleSlug::from($slug))->create();

            $this->actingAs($user)
                ->get(route($route))
                ->assertStatus($status, "{$slug} on {$route} should be {$status}");
        }
    }

    /**
     * The abilities themselves, independent of routing. Guards against someone
     * loosening a gate while leaving the routes alone.
     *
     * @return array<string, array{string, bool, bool, bool}>
     */
    public static function abilityMatrix(): array
    {
        //                            ability                    staff admin super
        return [
            'documents.process' => ['documents.process', true, false, true],
            'records.submit' => ['records.submit', true, false, true],
            'change-requests.create' => ['change-requests.create', true, false, true],
            'records.view' => ['records.view', true, true, true],
            'change-requests.moderate' => ['change-requests.moderate', false, true, true],
            'analytics.view' => ['analytics.view', false, true, true],
            'users.manage' => ['users.manage', false, true, true],
            'audit.view' => ['audit.view', false, true, true],
            'reports.generate' => ['reports.generate', false, true, true],
            'templates.manage' => ['templates.manage', false, false, true],
            'ocr.manage' => ['ocr.manage', false, false, true],
        ];
    }

    #[DataProvider('abilityMatrix')]
    public function test_abilities_match_the_capability_matrix(
        string $ability,
        bool $staff,
        bool $admin,
        bool $superAdmin,
    ): void {
        foreach ([
            RoleSlug::Staff->value => $staff,
            RoleSlug::Admin->value => $admin,
            RoleSlug::SuperAdmin->value => $superAdmin,
        ] as $slug => $allowed) {
            $user = User::factory()->role(RoleSlug::from($slug))->create();

            $this->assertSame(
                $allowed,
                $user->can($ability),
                "{$slug} should ".($allowed ? 'have' : 'NOT have')." '{$ability}'",
            );
        }
    }

    /**
     * The single most important guarantee in the system: Admin does people and
     * oversight, never data entry.
     */
    public function test_admin_can_never_enter_record_data(): void
    {
        $admin = User::factory()->admin()->create();

        $this->assertFalse($admin->canEnterData());

        foreach (['documents.process', 'records.submit', 'change-requests.create'] as $ability) {
            $this->assertFalse(
                $admin->can($ability),
                "Admin must not have '{$ability}'. Data entry belongs to Staff.",
            );
        }
    }

    public function test_deactivated_accounts_lose_every_ability(): void
    {
        $user = User::factory()->superAdmin()->inactive()->create();

        foreach (array_column(self::abilityMatrix(), 0) as $ability) {
            $this->assertFalse(
                $user->can($ability),
                "A deactivated Super Admin must not retain '{$ability}'.",
            );
        }
    }

    public function test_only_super_admin_may_provision_admin_accounts(): void
    {
        $admin = User::factory()->admin()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        // Admin user management covers Staff only.
        $this->assertTrue($admin->can('users.manage-role', RoleSlug::Staff));
        $this->assertFalse($admin->can('users.manage-role', RoleSlug::Admin));
        $this->assertFalse($admin->can('users.manage-role', RoleSlug::SuperAdmin));

        foreach (RoleSlug::cases() as $slug) {
            $this->assertTrue($superAdmin->can('users.manage-role', $slug));
        }
    }

    public function test_admin_cannot_modify_a_super_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $staff = User::factory()->staff()->create();

        $this->assertFalse($admin->can('users.update', $superAdmin));
        $this->assertTrue($admin->can('users.update', $staff));
        $this->assertTrue($superAdmin->can('users.update', $superAdmin));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        foreach (array_column(self::routeMatrix(), 0) as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
    }
}
