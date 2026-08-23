<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;

final class ErpWorkspaceNavigationService
{
    /**
     * @var array<string, array{slug: string, label: string, description: string}>
     */
    private const MODULES = [
        'retail_operations' => [
            'slug' => 'retail',
            'label' => 'Retail Operations',
            'description' => 'Manage products and retail operations for your shop.',
        ],
        'repair_operations' => [
            'slug' => 'repair',
            'label' => 'Repair Operations',
            'description' => 'Manage repair work and service operations for your shop.',
        ],
        'hr_employees' => [
            'slug' => 'hr',
            'label' => 'HR and Employees',
            'description' => 'Manage employees and HR operations for your shop.',
        ],
        'finance' => [
            'slug' => 'finance',
            'label' => 'Finance',
            'description' => 'Review finance operations and records for your shop.',
        ],
        'crm' => [
            'slug' => 'crm',
            'label' => 'CRM',
            'description' => 'Manage customers and customer relationships for your shop.',
        ],
        'inventory' => [
            'slug' => 'inventory',
            'label' => 'Inventory',
            'description' => 'Manage inventory and stock movement for your shop.',
        ],
        'procurement' => [
            'slug' => 'procurement',
            'label' => 'Procurement',
            'description' => 'Manage purchasing and supplier operations for your shop.',
        ],
        'logistics' => [
            'slug' => 'logistics',
            'label' => 'Logistics',
            'description' => 'Manage shipments and delivery operations for your shop.',
        ],
    ];

    public function __construct(private readonly ErpRouteCatalog $catalog) {}

    /**
     * @return array<string, array{slug: string, label: string, description: string, overview: array{label: string, url: string}, pages: array<int, array{label: string, routeName: string, url: string, groupKey: string|null, groupLabel: string|null, groupOrder: int|null, pageOrder: int}>}>
     */
    public function definitions(): array
    {
        $definitions = self::MODULES;

        foreach ($definitions as $moduleKey => &$definition) {
            $definition['pages'] = $this->pagesForKey($moduleKey);
        }
        unset($definition);

        return $definitions;
    }

    /**
     * @return array<int, string>
     */
    public static function slugs(): array
    {
        return array_values(array_map(
            static fn (array $definition): string => $definition['slug'],
            self::MODULES,
        ));
    }

    /**
     * @return array{key: string, slug: string, label: string, description: string, overview: array{label: string, url: string}, pages: array<int, array{label: string, routeName: string, url: string, groupKey: string|null, groupLabel: string|null, groupOrder: int|null, pageOrder: int}>}|null
     */
    public function forKey(string $moduleKey): ?array
    {
        $definition = self::MODULES[$moduleKey] ?? null;

        return is_array($definition) ? $this->payload($moduleKey, $definition) : null;
    }

    /**
     * @return array{key: string, slug: string, label: string, description: string, overview: array{label: string, url: string}, pages: array<int, array{label: string, routeName: string, url: string, groupKey: string|null, groupLabel: string|null, groupOrder: int|null, pageOrder: int}>}|null
     */
    public function forSlug(string $slug): ?array
    {
        foreach (self::MODULES as $moduleKey => $definition) {
            if ($definition['slug'] === $slug) {
                return $this->payload($moduleKey, $definition);
            }
        }

        return null;
    }

    public function urlForKey(string $moduleKey): ?string
    {
        $module = self::MODULES[$moduleKey] ?? null;

        if (! is_array($module) || ! Route::has('shop-owner.erp.module')) {
            return null;
        }

        return route('shop-owner.erp.module', ['module' => $module['slug']]);
    }

    /**
     * @param  array{slug: string, label: string, description: string}  $definition
     * @return array{key: string, slug: string, label: string, description: string, overview: array{label: string, url: string}, pages: array<int, array{label: string, routeName: string, url: string, groupKey: string|null, groupLabel: string|null, groupOrder: int|null, pageOrder: int}>}
     */
    private function payload(string $moduleKey, array $definition): array
    {
        return [
            'key' => $moduleKey,
            'slug' => $definition['slug'],
            'label' => $definition['label'],
            'description' => $definition['description'],
            'overview' => [
                'label' => 'Overview',
                'url' => route($this->canonicalModuleRoute($moduleKey)),
            ],
            'pages' => $this->pagesForKey($moduleKey),
        ];
    }

    private function canonicalModuleRoute(string $moduleKey): string
    {
        foreach ($this->catalog->all() as $routeName => $entry) {
            if (! str_starts_with((string) $routeName, 'shop-owner.shell.')
                || ($entry['classification'] ?? null) !== 'module'
                || ($entry['audience'] ?? null) !== 'shop_owner'
                || ($entry['owner_access'] ?? null) !== 'allowed'
                || ($entry['mode'] ?? null) !== 'single'
                || ($entry['module_keys'] ?? null) !== [$moduleKey]
                || ! Route::has((string) $routeName)) {
                continue;
            }

            return (string) $routeName;
        }

        throw new RuntimeException("Canonical module route is missing for {$moduleKey}.");
    }

    /**
     * @return array<int, array{label: string, routeName: string, url: string, groupKey: string|null, groupLabel: string|null, groupOrder: int|null, pageOrder: int}>
     */
    private function pagesForKey(string $moduleKey): array
    {
        $pages = [];

        foreach ($this->catalog->all() as $routeName => $entry) {
            $routeName = (string) $routeName;
            if (($entry['navigation_group'] ?? null) !== $moduleKey
                || ! $this->catalog->hasOwnerReadablePageContract($routeName)
                || ! str_starts_with($routeName, 'shop-owner.erp.')
                || str_starts_with($routeName, 'shop-owner.erp.api.')
                || ! Route::has($routeName)) {
                continue;
            }

            $label = $entry['navigation_label']
                ?? Str::headline(Str::afterLast($routeName, '.'));

            $pages[] = [
                'label' => (string) $label,
                'routeName' => $routeName,
                'url' => route($routeName),
                'groupKey' => isset($entry['navigation_page_group'])
                    ? (string) $entry['navigation_page_group']
                    : null,
                'groupLabel' => isset($entry['navigation_page_group_label'])
                    ? (string) $entry['navigation_page_group_label']
                    : null,
                'groupOrder' => isset($entry['navigation_page_group_order'])
                    ? (int) $entry['navigation_page_group_order']
                    : null,
                'pageOrder' => (int) ($entry['navigation_order'] ?? 1000),
                '_order' => (int) ($entry['navigation_order'] ?? 1000),
                '_index' => count($pages),
            ];
        }

        usort($pages, static function (array $left, array $right): int {
            return [$left['_order'], $left['_index']]
                <=> [$right['_order'], $right['_index']];
        });

        return array_map(static function (array $page): array {
            unset($page['_order']);
            unset($page['_index']);

            return $page;
        }, $pages);
    }
}
