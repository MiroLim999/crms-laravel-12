<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PHP reads ?q[]=x as an array, and anyone can edit a URL. A filter that
 * arrives as an array must be handled like any other bad value, never with an
 * error page.
 */
class ArrayQueryParameterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every page and parameter that used to give a 500, with what it gives now.
     *
     * @return array<string, array{string, string, int}>
     */
    public static function arrayParameters(): array
    {
        //                                    route                    parameter  status
        return [
            'navbar search on the dashboard' => ['dashboard', 'q', 200],
            'navbar search on reports' => ['reports.index', 'q', 200],
            'records search' => ['records.index', 'q', 200],
            'change request search' => ['change-requests.index', 'q', 302],
            'change request status' => ['change-requests.index', 'status', 302],
            'user search' => ['users.index', 'q', 302],
            'user role' => ['users.index', 'role', 302],
            'user status' => ['users.index', 'status', 302],
            'template builder type' => ['templates.create', 'type', 404],
            'scan workspace type' => ['documents.workspace', 'type', 302],
        ];
    }

    #[DataProvider('arrayParameters')]
    public function test_an_array_parameter_gets_an_answer_not_an_error_page(
        string $route,
        string $parameter,
        int $status,
    ): void {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route($route, [$parameter => ['x']]))
            ->assertStatus($status);
    }
}
