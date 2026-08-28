<?php

declare(strict_types=1);

namespace App\Services\OwnerShell;

use App\Http\Controllers\Erp\InventoryDashboardController;
use App\Models\CRM\CustomerReview;
use App\Models\Employee;
use App\Models\Finance\Expense;
use App\Models\Finance\Invoice;
use App\Models\HR\LeaveRequest;
use App\Models\InventoryItem;
use App\Models\Logistics\DeliveryBatch;
use App\Models\Logistics\Shipment;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\RepairRequest;
use App\Models\RepairService;

final class CanonicalOwnerOverviewService
{
    public function __construct(
        private readonly InventoryDashboardController $inventoryDashboard,
    ) {}

    /**
     * @return array{title: string, description: string, cards: array<int, array{label: string, value: int|float|string, description: string}>}
     */
    public function forModule(string $moduleKey, int $shopOwnerId): array
    {
        return [
            'title' => 'Operational dashboard',
            'description' => 'Key metrics from the existing owner-safe module read models.',
            'cards' => match ($moduleKey) {
                'retail_operations' => [
                    $this->card('Products', InventoryItem::query()->where('shop_owner_id', $shopOwnerId)->where('is_active', true)->count(), 'Active products'),
                    $this->card('Orders', Order::query()->where('shop_owner_id', $shopOwnerId)->count(), 'Orders for this shop'),
                ],
                'repair_operations' => [
                    $this->card('Open repair jobs', RepairRequest::query()->where('shop_owner_id', $shopOwnerId)->whereNotIn('status', ['completed', 'cancelled'])->count(), 'Repair work not yet closed'),
                    $this->card('Services and packages', RepairService::query()->where('shop_owner_id', $shopOwnerId)->count(), 'Configured repair services'),
                ],
                'hr_employees' => [
                    $this->card('Employees', Employee::query()->where('shop_owner_id', $shopOwnerId)->count(), 'Employee directory records'),
                    $this->card('Active employees', Employee::query()->where('shop_owner_id', $shopOwnerId)->where('status', 'active')->count(), 'Currently active accounts'),
                    $this->card('Pending leave requests', LeaveRequest::query()->where('shop_owner_id', $shopOwnerId)->where('status', 'pending')->count(), 'Requests requiring attention'),
                ],
                'finance' => [
                    $this->card('Invoices', Invoice::query()->where('shop_id', $shopOwnerId)->count(), 'Invoices available to review'),
                    $this->card('Expenses', Expense::query()->where('shop_id', $shopOwnerId)->count(), 'Expenses available to review'),
                ],
                'crm' => [
                    $this->card('Customer orders', Order::query()->where('shop_owner_id', $shopOwnerId)->count(), 'Orders connected to customers'),
                    $this->card('Customer reviews', CustomerReview::query()->where('shop_owner_id', $shopOwnerId)->count(), 'Reviews for this shop'),
                ],
                'inventory' => $this->inventoryCards($shopOwnerId),
                'procurement' => [
                    $this->card('Purchase requests', PurchaseRequest::query()->where('shop_owner_id', $shopOwnerId)->count(), 'Requests in the procurement flow'),
                    $this->card('Purchase orders', PurchaseOrder::query()->where('shop_owner_id', $shopOwnerId)->count(), 'Orders placed for this shop'),
                ],
                'logistics' => [
                    $this->card('Shipments', Shipment::query()->where('shop_owner_id', $shopOwnerId)->count(), 'Retail and repair shipments'),
                    $this->card('Batches', DeliveryBatch::query()->where('shop_owner_id', $shopOwnerId)->count(), 'Delivery batches'),
                ],
                default => [],
            },
        ];
    }

    /**
     * @return array<int, array{label: string, value: int|float|string, description: string}>
     */
    private function inventoryCards(int $shopOwnerId): array
    {
        $metrics = $this->inventoryDashboard->getMetrics($shopOwnerId);

        return [
            $this->card('Products', (int) ($metrics['total_items'] ?? 0), 'Active inventory items'),
            $this->card('Low stock', (int) ($metrics['low_stock_count'] ?? 0), 'Items below reorder level'),
            $this->card('Supplier orders', (int) ($metrics['active_supplier_orders'] ?? 0), 'Active supplier orders'),
        ];
    }

    /**
     * @param int|float|string $value
     * @return array{label: string, value: int|float|string, description: string}
     */
    private function card(string $label, int|float|string $value, string $description): array
    {
        return compact('label', 'value', 'description');
    }
}
