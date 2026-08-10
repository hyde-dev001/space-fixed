<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Route;

final class ErpWorkspaceNavigationService
{
    /**
     * @var array<string, array{slug: string, label: string, description: string, pages: array<int, array{label: string, routeName: string}>}>
     */
    private const MODULES = [
        'retail_operations' => [
            'slug' => 'retail',
            'label' => 'Retail Operations',
            'description' => 'Manage products and retail operations for your shop.',
            'pages' => [
                ['label' => 'Products', 'routeName' => 'shop-owner.erp.retail.products'],
            ],
        ],
        'repair_operations' => [
            'slug' => 'repair',
            'label' => 'Repair Operations',
            'description' => 'Manage repair work and service operations for your shop.',
            'pages' => [
                ['label' => 'Repair Dashboard', 'routeName' => 'shop-owner.erp.staff.repair-dashboard'],
            ],
        ],
        'hr_employees' => [
            'slug' => 'hr',
            'label' => 'HR and Employees',
            'description' => 'Manage employees and HR operations for your shop.',
            'pages' => [
                ['label' => 'Audit Logs', 'routeName' => 'shop-owner.erp.hr.audit-logs'],
            ],
        ],
        'finance' => [
            'slug' => 'finance',
            'label' => 'Finance',
            'description' => 'Review finance operations and records for your shop.',
            'pages' => [
                ['label' => 'Audit Logs', 'routeName' => 'shop-owner.erp.finance.audit-logs'],
            ],
        ],
        'crm' => [
            'slug' => 'crm',
            'label' => 'CRM',
            'description' => 'Manage customers and customer relationships for your shop.',
            'pages' => [
                ['label' => 'Dashboard', 'routeName' => 'shop-owner.erp.crm.dashboard'],
                ['label' => 'Customers', 'routeName' => 'shop-owner.erp.crm.customers'],
                ['label' => 'Customer Reviews', 'routeName' => 'shop-owner.erp.crm.customer-reviews'],
            ],
        ],
        'inventory' => [
            'slug' => 'inventory',
            'label' => 'Inventory',
            'description' => 'Manage inventory and stock movement for your shop.',
            'pages' => [
                ['label' => 'Dashboard', 'routeName' => 'shop-owner.erp.inventory.inventory-dashboard'],
                ['label' => 'Product Inventory', 'routeName' => 'shop-owner.erp.inventory.product-inventory'],
                ['label' => 'Stock Movement', 'routeName' => 'shop-owner.erp.inventory.stock-movement'],
            ],
        ],
        'procurement' => [
            'slug' => 'procurement',
            'label' => 'Procurement',
            'description' => 'Manage purchasing and supplier operations for your shop.',
            'pages' => [
                ['label' => 'Suppliers Management', 'routeName' => 'shop-owner.erp.procurement.suppliers-management'],
            ],
        ],
        'logistics' => [
            'slug' => 'logistics',
            'label' => 'Logistics',
            'description' => 'Manage shipments and delivery operations for your shop.',
            'pages' => [
                ['label' => 'Dashboard', 'routeName' => 'shop-owner.erp.logistics.dashboard'],
                ['label' => 'Shipments', 'routeName' => 'shop-owner.erp.logistics.shipments'],
                ['label' => 'Riders', 'routeName' => 'shop-owner.erp.logistics.riders'],
            ],
        ],
    ];

    /**
     * @return array<string, array{slug: string, label: string, description: string, pages: array<int, array{label: string, routeName: string}>}>
     */
    public function definitions(): array
    {
        return self::MODULES;
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
     * @return array{key: string, slug: string, label: string, description: string, pages: array<int, array{label: string, routeName: string, url: string}>}|null
     */
    public function forKey(string $moduleKey): ?array
    {
        $definition = self::MODULES[$moduleKey] ?? null;

        return is_array($definition) ? $this->payload($moduleKey, $definition) : null;
    }

    /**
     * @return array{key: string, slug: string, label: string, description: string, pages: array<int, array{label: string, routeName: string, url: string}>}|null
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
     * @param  array{slug: string, label: string, description: string, pages: array<int, array{label: string, routeName: string}>}  $definition
     * @return array{key: string, slug: string, label: string, description: string, pages: array<int, array{label: string, routeName: string, url: string}>}
     */
    private function payload(string $moduleKey, array $definition): array
    {
        $pages = [];
        foreach ($definition['pages'] as $page) {
            if (! Route::has($page['routeName'])) {
                continue;
            }

            $pages[] = [
                'label' => $page['label'],
                'routeName' => $page['routeName'],
                'url' => route($page['routeName']),
            ];
        }

        return [
            'key' => $moduleKey,
            'slug' => $definition['slug'],
            'label' => $definition['label'],
            'description' => $definition['description'],
            'pages' => $pages,
        ];
    }
}
