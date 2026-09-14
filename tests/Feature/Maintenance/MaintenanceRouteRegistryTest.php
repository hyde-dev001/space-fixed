<?php

namespace Tests\Feature\Maintenance;

use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MaintenanceRouteRegistryTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function criticalRoutes(): array
    {
        return array_map(
            static fn (string $name): array => [$name],
            [
                'checkout.create-order',
                'orders.request-refund',
                'payments.paymongo.create',
                'api.orders.update-payment-link',
                'api.orders.retry-payment-session',
                'api.customer.repairs.update-payment-link',
                'api.customer.repairs.retry-payment-session',
                'api.customer.repairs.refunds.store',
                'api.repair-pos.checkout',
                'api.repair-pos.refunds.store',
                'api.retail-pos.checkout',
                'api.retail-pos.refunds.store',
                'api.repairer.conversations.activate-payment',
                'api.repairer.repairs.activate-payment',
                'shop_owner.repairs.activate-payment',
                'shop_owner.conversations.activate-payment',
                'shop_owner.premium.checkout',
                'shop_owner.premium.upgrade.confirm',
                'hr.payroll.process',
                'hr.payroll.thirteenth.release',
                'hr.payroll.batch.generate',
                'hr.payroll.batch.retry',
                'finance.payslip_approval.disburse',
                'procurement.purchase-requests.store',
                'procurement.purchase-requests.submit-finance',
                'logistics.api.batches.store',
                'logistics.api.legs.schedule',
                'logistics.api.batches.offer',
                'api.finance.approvals.approve',
                'api.finance.approvals.reject',
                'api.leave.approve',
                'api.leave.reject',
                'api.manager.suspension_requests.review',
                'finance.expenses.approve',
                'finance.expenses.reject',
                'finance.payslip_approval.approve',
                'finance.payslip_approval.batch_approve',
                'finance.payslip_approval.final_approve',
                'finance.payslip_approval.reject',
                'finance.price-changes.approve',
                'finance.price-changes.reject',
                'finance.purchase-requests.approve',
                'finance.purchase-requests.reject',
                'finance.refunds.approve',
                'finance.refunds.reject',
                'finance.repair-price-changes.approve',
                'finance.repair-price-changes.approve-final',
                'finance.repair-price-changes.reject',
                'hr.leave.approve',
                'hr.leave.reject',
                'hr.overtime.approve',
                'hr.overtime.reject',
                'hr.payroll.approve',
                'hr.salary_changes.approve',
                'hr.salary_changes.reject',
                'inventory.request-material-approvals.approve',
                'inventory.request-material-approvals.reject',
                'procurement.purchase-requests.approve',
                'procurement.purchase-requests.reject',
                'procurement.replenishment-requests.accept',
                'procurement.replenishment-requests.reject',
                'procurement.stock-requests.approve',
                'procurement.stock-requests.reject',
                'shop-owner.employees.activate',
                'shop_owner.expenses.approve',
                'shop_owner.expenses.reject',
                'shop_owner.finance.expenses.approve',
                'shop_owner.finance.expenses.reject',
                'shop_owner.payslip_approval.batch_final_approve',
                'shop_owner.payslip_approval.final_approve',
                'shop_owner.price-changes.approve',
                'shop_owner.price-changes.reject',
                'shop_owner.purchase-requests.approve',
                'shop_owner.purchase-requests.reject',
                'shop_owner.refunds.approve',
                'shop_owner.refunds.reject',
                'shop_owner.repair-price-changes.approve',
                'shop_owner.repair-price-changes.reject',
                'shop_owner.repair-services.owner.approve',
                'shop_owner.repair-services.owner.reject',
                'shop_owner.repair-refunds.approve',
                'shop_owner.repair-refunds.reject',
                'shop_owner.repairs.approve-high-value',
                'shop_owner.repairs.reject-high-value',
                'shop_owner.repairs.approve-rejection',
                'shop_owner.repairs.reject-rejection',
                'shop_owner.salary-changes.approve',
                'shop_owner.salary-changes.reject',
                'shop_owner.suspension_requests.review',
            ],
        );
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function namedRouteUris(): array
    {
        return [
            'webhooks.paymongo' => ['webhooks.paymongo', 'api/webhooks/paymongo'],
            'payments.paymongo.create' => ['payments.paymongo.create', 'api/paymongo-proxy'],
            'api.orders.update-payment-link' => ['api.orders.update-payment-link', 'api/orders/{id}/update-payment-link'],
            'api.orders.retry-payment-session' => ['api.orders.retry-payment-session', 'api/orders/{id}/retry-payment-session'],
            'api.customer.repairs.update-payment-link' => ['api.customer.repairs.update-payment-link', 'api/customer/repairs/{id}/update-payment-link'],
            'api.customer.repairs.retry-payment-session' => ['api.customer.repairs.retry-payment-session', 'api/customer/repairs/{id}/retry-payment-session'],
            'api.customer.repairs.refunds.store' => ['api.customer.repairs.refunds.store', 'api/customer/repairs/{id}/refunds'],
            'api.repair-pos.checkout' => ['api.repair-pos.checkout', 'api/repair-pos/checkout'],
            'api.repair-pos.refunds.store' => ['api.repair-pos.refunds.store', 'api/repair-pos/refunds'],
            'api.retail-pos.checkout' => ['api.retail-pos.checkout', 'api/retail-pos/checkout'],
            'api.retail-pos.refunds.store' => ['api.retail-pos.refunds.store', 'api/retail-pos/refunds'],
            'api.repairer.conversations.activate-payment' => ['api.repairer.conversations.activate-payment', 'api/repairer/conversations/{conversation}/activate-payment'],
            'api.repairer.repairs.activate-payment' => ['api.repairer.repairs.activate-payment', 'api/repairer/repairs/{id}/activate-payment'],
            'api.finance.approvals.approve' => ['api.finance.approvals.approve', 'api/finance/approvals/{id}/approve'],
            'api.finance.approvals.reject' => ['api.finance.approvals.reject', 'api/finance/approvals/{id}/reject'],
            'shop_owner.repair-services.owner.approve' => ['shop_owner.repair-services.owner.approve', 'api/repair-services/{id}/owner/approve'],
            'shop_owner.repair-services.owner.reject' => ['shop_owner.repair-services.owner.reject', 'api/repair-services/{id}/owner/reject'],
            'shop_owner.repairs.approve-rejection' => ['shop_owner.repairs.approve-rejection', 'api/shop-owner/repairs/{id}/approve-rejection'],
            'shop_owner.repairs.reject-rejection' => ['shop_owner.repairs.reject-rejection', 'api/shop-owner/repairs/{id}/reject-rejection'],
            'logistics.api.batches.store' => ['logistics.api.batches.store', 'api/logistics/batches'],
            'logistics.api.legs.schedule' => ['logistics.api.legs.schedule', 'api/logistics/legs/schedule'],
            'logistics.api.batches.offer' => ['logistics.api.batches.offer', 'api/logistics/batches/{batch}/offer'],
        ];
    }

    #[DataProvider('criticalRoutes')]
    public function test_critical_initiation_routes_have_stable_post_names(string $name): void
    {
        $route = $this->routeByName($name);

        $this->assertNotNull($route, "Missing route [{$name}].");
        $this->assertContains('POST', $route->methods());
    }

    #[DataProvider('namedRouteUris')]
    public function test_new_route_names_preserve_the_canonical_uri(string $name, string $uri): void
    {
        $route = $this->routeByName($name);

        $this->assertNotNull($route, "Missing route [{$name}].");
        $this->assertSame($uri, $route->uri());
        $this->assertContains('POST', $route->methods());
    }

    private function routeByName(string $name): ?Route
    {
        return collect(app('router')->getRoutes()->getRoutes())
            ->first(static fn (Route $route): bool => $route->getName() === $name);
    }
}
