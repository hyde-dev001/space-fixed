<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessScaling;

use App\Services\ErpRouteCatalog;
use Tests\TestCase;

final class ErpRouteCatalogTest extends TestCase
{
    public function test_capability_keys_are_stable_and_method_normalized(): void
    {
        $this->assertSame('GET:erp.hr', ErpRouteCatalog::capabilityKey('get', 'erp.hr'));
        $this->assertSame('POST:erp.hr', ErpRouteCatalog::capabilityKey('POST', 'erp.hr'));
    }

    public function test_route_lookup_requires_a_cataloged_method(): void
    {
        $catalog = app(ErpRouteCatalog::class);

        $this->assertSame('erp.hr', $catalog->forRoute('GET', 'erp.hr')['route_name']);
        $this->assertNull($catalog->forRoute('POST', 'erp.hr'));
        $this->assertNull($catalog->forRoute('GET', 'missing.route'));
    }

    public function test_employee_route_is_the_canonical_client_key_until_a_pair_exists(): void
    {
        $catalog = app(ErpRouteCatalog::class);

        $this->assertSame('GET:erp.hr', $catalog->canonicalClientKey('GET', 'erp.hr'));
        $this->assertNull($catalog->ownerExposure('GET', 'erp.hr'));
        $this->assertIsString($catalog->employeeRule('erp.hr'));
    }

    public function test_owner_page_entries_can_declare_a_nested_navigation_group(): void
    {
        $catalog = app(ErpRouteCatalog::class);

        $attendance = $catalog->entry('shop-owner.erp.hr.attendance');

        $this->assertIsArray($attendance);
        $this->assertSame('attendance-monitoring', $attendance['navigation_page_group']);
        $this->assertSame('Attendance Monitoring', $attendance['navigation_page_group_label']);
        $this->assertSame(20, $attendance['navigation_page_group_order']);
        $this->assertSame(30, $attendance['navigation_order']);
    }

    public function test_owner_readable_pages_require_a_complete_catalog_contract(): void
    {
        $catalog = app(ErpRouteCatalog::class);
        $invoicePage = $catalog->entry('shop-owner.erp.finance.invoices');

        $this->assertIsArray($invoicePage);
        $this->assertSame('shop_owner', $invoicePage['actor_guard']);
        $this->assertSame('allowed', $invoicePage['owner_access']);
        $this->assertSame('finance', $invoicePage['navigation_group']);
        $this->assertSame('Invoices', $invoicePage['navigation_label']);
        $this->assertTrue($catalog->hasOwnerReadablePageContract('shop-owner.erp.finance.invoices'));

        $this->assertTrue($catalog->hasOwnerReadablePageContract('shop-owner.erp.retail.products'));
        $this->assertTrue($catalog->hasOwnerReadablePageContract('shop-owner.erp.retail.orders'));
        $this->assertFalse($catalog->hasOwnerReadablePageContract('shop-owner.erp.retail.dashboard'));
        $this->assertFalse($catalog->hasOwnerReadablePageContract('shop-owner.erp.crm.dashboard'));

        $productStore = $catalog->entry('shop_owner.products.store');
        $this->assertIsArray($productStore);
        $this->assertSame('allowed', $productStore['owner_access']);
        $this->assertSame(['individual'], $productStore['registration_types']);
        $orderUpdate = $catalog->entry('shop_owner.orders.update-status');
        $this->assertIsArray($orderUpdate);
        $this->assertSame('allowed', $orderUpdate['owner_access']);
        $this->assertSame(['individual'], $orderUpdate['registration_types']);
        $this->assertSame('denied', $catalog->entry('erp.manager.audit-logs')['owner_access']);
        $this->assertSame('denied', $catalog->entry('api.manager.audit-logs')['owner_access']);
        $this->assertNull($catalog->ownerExposure('GET', 'api.manager.audit-logs'));
    }

    public function test_owner_readable_page_contract_fails_closed_for_missing_denied_or_unclassified_data_surfaces(): void
    {
        $routes = config('shop_modules.routes');

        foreach (['missing', 'denied', 'unclassified'] as $case) {
            $configuredRoutes = $routes;
            $configuredRoutes['shop-owner.erp.crm.dashboard']['supporting_routes'] = ['testing.owner-data-surface'];

            if ($case !== 'missing') {
                $configuredRoutes['testing.owner-data-surface'] = $routes['shop-owner.erp.api.crm.dashboard-stats'];

                if ($case === 'denied') {
                    $configuredRoutes['testing.owner-data-surface']['owner_access'] = 'denied';
                }

                if ($case === 'unclassified') {
                    unset($configuredRoutes['testing.owner-data-surface']['classification']);
                }
            }

            config(['shop_modules.routes' => $configuredRoutes]);

            $this->assertFalse(
                app(ErpRouteCatalog::class)->hasOwnerReadablePageContract('shop-owner.erp.crm.dashboard'),
                $case,
            );
        }

        config(['shop_modules.routes' => $routes]);
    }

    public function test_owner_readable_page_contract_ignores_non_get_mutation_support(): void
    {
        $routes = config('shop_modules.routes');
        $configuredRoutes = $routes;
        $configuredRoutes['shop-owner.erp.crm.customers']['supporting_routes'][] = 'testing.owner-mutation';
        $configuredRoutes['testing.owner-mutation'] = $routes['shop-owner.erp.api.crm.dashboard-stats'];
        $configuredRoutes['testing.owner-mutation']['methods'] = ['POST'];
        $configuredRoutes['testing.owner-mutation']['owner_access'] = 'denied';
        config(['shop_modules.routes' => $configuredRoutes]);

        $this->assertTrue(
            app(ErpRouteCatalog::class)->hasOwnerReadablePageContract('shop-owner.erp.crm.customers'),
        );

        config(['shop_modules.routes' => $routes]);
    }

    public function test_owner_readable_page_contract_fails_closed_for_an_unregistered_get_support(): void
    {
        $routes = config('shop_modules.routes');
        $configuredRoutes = $routes;
        $configuredRoutes['shop-owner.erp.crm.dashboard']['supporting_routes'] = ['testing.unregistered-owner-read'];
        $configuredRoutes['testing.unregistered-owner-read'] = $routes['shop-owner.erp.api.crm.dashboard-stats'];
        config(['shop_modules.routes' => $configuredRoutes]);

        $this->assertFalse(
            app(ErpRouteCatalog::class)->hasOwnerReadablePageContract('shop-owner.erp.crm.dashboard'),
        );

        config(['shop_modules.routes' => $routes]);
    }

    public function test_owner_readable_page_contract_fails_closed_when_a_configured_get_support_is_patch_only(): void
    {
        $routes = config('shop_modules.routes');
        $configuredRoutes = $routes;
        $configuredRoutes['shop-owner.erp.crm.dashboard']['supporting_routes'] = ['shop_owner.orders.update-status'];
        $configuredRoutes['shop_owner.orders.update-status']['methods'] = ['GET'];
        $configuredRoutes['shop_owner.orders.update-status']['owner_access'] = 'allowed';
        $configuredRoutes['shop_owner.orders.update-status']['owner_denial_reason'] = null;
        config(['shop_modules.routes' => $configuredRoutes]);

        $this->assertFalse(
            app(ErpRouteCatalog::class)->hasOwnerReadablePageContract('shop-owner.erp.crm.dashboard'),
        );

        config(['shop_modules.routes' => $routes]);
    }
}
