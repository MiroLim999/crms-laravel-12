<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * CRMS has one account, the Super Admin (docs/SUPER-ADMIN-ONLY-PLAN.md). It opens
 * every page; a guest is sent to sign in; the pages that only existed for the
 * removed roles are gone.
 */
class CapabilityMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function pages(): array
    {
        return [
            'dashboard' => ['dashboard'],
            'new document' => ['documents.create'],
            'records archive' => ['records.index'],
            'corrections' => ['change-requests.index'],
            'reports' => ['reports.index'],
            'audit log' => ['audit.index'],
            'template builder' => ['templates.index'],
            'OCR workspace' => ['ocr.index'],
            'settings' => ['settings.edit'],
        ];
    }

    #[DataProvider('pages')]
    public function test_the_account_opens_every_page(string $route): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route($route))
            ->assertOk();
    }

    #[DataProvider('pages')]
    public function test_a_guest_is_sent_to_sign_in(string $route): void
    {
        $this->get(route($route))->assertRedirect(route('login'));
    }

    public function test_the_account_has_every_ability(): void
    {
        $user = User::factory()->create();

        foreach ([
            'documents.process', 'change-requests.create', 'records.view', 'audit.view',
            'reports.generate', 'templates.manage', 'ocr.manage',
        ] as $ability) {
            $this->assertTrue($user->can($ability), "The account should have '{$ability}'.");
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function removedPages(): array
    {
        return [
            'user accounts' => ['/users'],
            'new account' => ['/users/create'],
            'old analytics link' => ['/analytics'],
        ];
    }

    #[DataProvider('removedPages')]
    public function test_pages_of_the_removed_roles_are_gone(string $url): void
    {
        $this->actingAs(User::factory()->create())
            ->get($url)
            ->assertNotFound();
    }

    public function test_the_sidebar_has_no_user_accounts_and_shows_corrections(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('User Accounts')
            ->assertSee('Corrections')
            ->assertSee('Reports &amp; history', false)
            ->assertDontSee('Signed in as');
    }
}
