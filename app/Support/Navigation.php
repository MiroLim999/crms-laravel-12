<?php

namespace App\Support;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Sidebar definition, filtered by ability.
 *
 * Replaces SNEAT's static verticalMenu.json: the menu is derived from the same
 * gates that guard the routes, so a user can never see a link they cannot follow.
 *
 * Hiding a nav item is presentation only. The route still carries its own
 * `can:` middleware - this is not a security boundary.
 */
class Navigation
{
    /**
     * @return list<array{header: string|null, items: list<array<string, mixed>>}>
     */
    public static function sections(): array
    {
        $sections = [
            [
                'header' => null,
                'items' => [
                    self::item('Dashboard', 'dashboard', 'bx-home-smile'),
                ],
            ],
            [
                'header' => 'Digitization',
                'items' => [
                    self::item('New Document', 'documents.create', 'bx-scan', 'documents.process'),
                    self::item('Records Archive', 'records.index', 'bx-archive', 'records.view'),
                    self::item('Change Requests', 'change-requests.index', 'bx-git-pull-request', anyOf: [
                        'change-requests.create',
                        'change-requests.moderate',
                    ]),
                ],
            ],
            [
                'header' => 'Oversight',
                'items' => [
                    self::item('Analytics', 'analytics.index', 'bx-bar-chart-alt-2', 'analytics.view'),
                    self::item('Reports', 'reports.index', 'bx-file', 'reports.generate'),
                    self::item('User Accounts', 'users.index', 'bx-user', 'users.manage'),
                    self::item('Audit Log', 'audit.index', 'bx-history', 'audit.view'),
                ],
            ],
            [
                'header' => 'System',
                'items' => [
                    self::item('Document Templates', 'templates.index', 'bx-layout', 'templates.manage'),
                    self::item('OCR Models', 'ocr.index', 'bx-brain', 'ocr.manage'),
                ],
            ],
        ];

        // Drop items the user cannot reach, then drop any section left empty.
        return collect($sections)
            ->map(fn (array $section) => [
                ...$section,
                'items' => array_values(array_filter($section['items'], fn ($item) => $item['visible'])),
            ])
            ->filter(fn (array $section) => $section['items'] !== [])
            ->values()
            ->all();
    }

    /**
     * @param  string|null  $ability  Single required ability.
     * @param  list<string>  $anyOf  Visible if the user has at least one of these.
     * @return array<string, mixed>
     */
    private static function item(
        string $label,
        string $route,
        string $icon,
        ?string $ability = null,
        array $anyOf = [],
    ): array {
        $visible = match (true) {
            $anyOf !== [] => collect($anyOf)->contains(fn (string $a) => Gate::allows($a)),
            $ability !== null => Gate::allows($ability),
            default => true,
        };

        return [
            'label' => $label,
            'route' => $route,
            'icon' => $icon,
            'visible' => $visible && Route::has($route),
            'active' => self::isActive($route),
        ];
    }

    /**
     * A nav item is active for its own route and any nested route beneath it,
     * so `records.show` still highlights "Records Archive".
     */
    private static function isActive(string $route): bool
    {
        $current = Route::currentRouteName() ?? '';
        $prefix = str_contains($route, '.')
            ? substr($route, 0, strrpos($route, '.'))
            : $route;

        return $current === $route || str_starts_with($current, $prefix.'.');
    }
}
