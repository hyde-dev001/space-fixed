<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\InventoryItem;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseOrderService
{
    /**
     * Create a new purchase order from an approved PR.
     */
    public function createPurchaseOrder(array $data): PurchaseOrder
    {
        DB::beginTransaction();
        
        try {
            $purchaseRequest = PurchaseRequest::findOrFail($data['pr_id']);

            // Verify PR is approved
            if ($purchaseRequest->status !== 'approved') {
                throw new \Exception('Purchase request must be approved before creating a purchase order.');
            }

            // Generate PO number if not provided
            if (!isset($data['po_number'])) {
                $data['po_number'] = $this->generatePONumber($data['shop_owner_id']);
            }

            // Copy data from PR
            $data['supplier_id'] = $purchaseRequest->supplier_id;
            $data['product_name'] = $purchaseRequest->product_name;
            $data['inventory_item_id'] = $purchaseRequest->inventory_item_id;
            $data['quantity'] = $purchaseRequest->quantity;
            $data['unit_cost'] = $purchaseRequest->unit_cost;
            $data['total_cost'] = $purchaseRequest->total_cost;
            $data['status'] = 'draft';

            $purchaseOrder = PurchaseOrder::create($data);

            DB::commit();

            Log::info('Purchase order created', [
                'po_id' => $purchaseOrder->id,
                'po_number' => $purchaseOrder->po_number,
                'pr_number' => $purchaseRequest->pr_number
            ]);

            return $purchaseOrder->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create purchase order', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            throw $e;
        }
    }

    /**
     * Generate unique PO number for shop owner.
     */
    public function generatePONumber(int $shopOwnerId): string
    {
        $year = date('Y');
        
        $lastPO = PurchaseOrder::where('shop_owner_id', $shopOwnerId)
            ->where('po_number', 'LIKE', "PO-{$year}-%")
            ->orderBy('po_number', 'desc')
            ->first();

        if ($lastPO) {
            $lastNumber = intval(substr($lastPO->po_number, -3));
            $newNumber = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '001';
        }

        return "PO-{$year}-{$newNumber}";
    }

    /**
     * Update purchase order status.
     */
    public function updateStatus(int $poId, string $status, int $userId, array $data = []): PurchaseOrder
    {
        DB::beginTransaction();

        try {
            $purchaseOrder = PurchaseOrder::findOrFail($poId);

            if (!$purchaseOrder->canProgressStatus()) {
                throw new \Exception('Purchase order cannot progress from its current status.');
            }

            switch ($status) {
                case 'sent':
                    $purchaseOrder->sendToSupplier();
                    break;
                case 'confirmed':
                    $purchaseOrder->markAsConfirmed($userId);
                    break;
                case 'in_transit':
                    $purchaseOrder->markAsInTransit($userId);
                    break;
                case 'delivered':
                    $actualDate = $data['actual_delivery_date'] ?? now()->toDateString();
                    $purchaseOrder->markAsDelivered($userId, $actualDate);
                    $this->updateInventoryOnDelivery($poId);
                    $this->updateSupplierMetrics($purchaseOrder->supplier_id);
                    break;
                case 'completed':
                    $purchaseOrder->markAsCompleted($userId);
                    $this->updateSupplierMetrics($purchaseOrder->supplier_id);
                    break;
                default:
                    throw new \Exception('Invalid status transition.');
            }

            if (isset($data['notes'])) {
                $purchaseOrder->notes = $data['notes'];
                $purchaseOrder->save();
            }

            DB::commit();

            Log::info('Purchase order status updated', [
                'po_id' => $poId,
                'po_number' => $purchaseOrder->po_number,
                'new_status' => $status,
                'updated_by' => $userId
            ]);

            return $purchaseOrder->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update purchase order status', [
                'po_id' => $poId,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Send purchase order to supplier.
     */
    public function sendToSupplier(int $poId): PurchaseOrder
    {
        DB::beginTransaction();

        try {
            $purchaseOrder = PurchaseOrder::findOrFail($poId);

            if ($purchaseOrder->status !== 'draft') {
                throw new \Exception('Only draft purchase orders can be sent to supplier.');
            }

            $purchaseOrder->sendToSupplier();

            DB::commit();

            Log::info('Purchase order sent to supplier', [
                'po_id' => $poId,
                'po_number' => $purchaseOrder->po_number,
                'supplier_id' => $purchaseOrder->supplier_id
            ]);

            // Email sending will be handled by event listener in Phase 4

            return $purchaseOrder->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to send purchase order to supplier', [
                'po_id' => $poId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Mark purchase order as delivered.
     */
    public function markAsDelivered(int $poId, int $userId, string $actualDate): PurchaseOrder
    {
        DB::beginTransaction();

        try {
            $purchaseOrder = PurchaseOrder::findOrFail($poId);

            if ($purchaseOrder->status !== 'in_transit') {
                throw new \Exception('Only in-transit purchase orders can be marked as delivered.');
            }

            $purchaseOrder->markAsDelivered($userId, $actualDate);
            $this->updateInventoryOnDelivery($poId);
            $this->updateSupplierMetrics($purchaseOrder->supplier_id);

            DB::commit();

            Log::info('Purchase order marked as delivered', [
                'po_id' => $poId,
                'po_number' => $purchaseOrder->po_number,
                'actual_delivery_date' => $actualDate
            ]);

            return $purchaseOrder->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to mark purchase order as delivered', [
                'po_id' => $poId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Cancel a purchase order.
     */
    public function cancelPurchaseOrder(int $poId, int $userId, string $reason): PurchaseOrder
    {
        DB::beginTransaction();

        try {
            $purchaseOrder = PurchaseOrder::findOrFail($poId);

            if (in_array($purchaseOrder->status, ['delivered', 'completed', 'cancelled'])) {
                throw new \Exception('Purchase order cannot be cancelled in its current state.');
            }

            $purchaseOrder->cancel($userId, $reason);

            DB::commit();

            Log::info('Purchase order cancelled', [
                'po_id' => $poId,
                'po_number' => $purchaseOrder->po_number,
                'cancelled_by' => $userId,
                'reason' => $reason
            ]);

            return $purchaseOrder->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to cancel purchase order', [
                'po_id' => $poId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get procurement metrics for purchase orders.
     */
    public function getMetrics(int $shopOwnerId): array
    {
        $totalValue = PurchaseOrder::where('shop_owner_id', $shopOwnerId)->sum('total_cost');
        $completedValue = PurchaseOrder::where('shop_owner_id', $shopOwnerId)->completed()->sum('total_cost');

        return [
            'total_purchase_orders' => PurchaseOrder::where('shop_owner_id', $shopOwnerId)->count(),
            'active_orders' => PurchaseOrder::where('shop_owner_id', $shopOwnerId)->active()->count(),
            'completed_orders' => PurchaseOrder::where('shop_owner_id', $shopOwnerId)->completed()->count(),
            'cancelled_orders' => PurchaseOrder::where('shop_owner_id', $shopOwnerId)->cancelled()->count(),
            'overdue_orders' => PurchaseOrder::where('shop_owner_id', $shopOwnerId)->overdue()->count(),
            'draft_orders' => PurchaseOrder::where('shop_owner_id', $shopOwnerId)->draft()->count(),
            'total_value' => $totalValue,
            'completed_value' => $completedValue,
            'average_order_value' => PurchaseOrder::where('shop_owner_id', $shopOwnerId)->count() > 0 
                ? $totalValue / PurchaseOrder::where('shop_owner_id', $shopOwnerId)->count() 
                : 0,
        ];
    }

    /**
     * Check for overdue purchase orders.
     */
    public function checkOverduePOs(int $shopOwnerId = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = PurchaseOrder::overdue();

        if ($shopOwnerId) {
            $query->where('shop_owner_id', $shopOwnerId);
        }

        $overduePOs = $query->get();

        if ($overduePOs->isNotEmpty()) {
            Log::warning('Overdue purchase orders detected', [
                'count' => $overduePOs->count(),
                'shop_owner_id' => $shopOwnerId
            ]);
        }

        return $overduePOs;
    }

    /**
     * Update inventory when PO is delivered.
     */
    public function updateInventoryOnDelivery(int $poId): void
    {
        try {
            $purchaseOrder = PurchaseOrder::findOrFail($poId);

            if (!$purchaseOrder->inventory_item_id) {
                Log::info('No inventory item linked to PO, skipping inventory update', [
                    'po_id' => $poId
                ]);
                return;
            }

            $inventoryItem = InventoryItem::find($purchaseOrder->inventory_item_id);

            if (!$inventoryItem) {
                Log::warning('Inventory item not found', [
                    'po_id' => $poId,
                    'inventory_item_id' => $purchaseOrder->inventory_item_id
                ]);
                return;
            }

            // Update stock quantity
            $inventoryItem->stock_quantity += $purchaseOrder->quantity;
            $inventoryItem->save();

            Log::info('Inventory updated on PO delivery', [
                'po_id' => $poId,
                'inventory_item_id' => $inventoryItem->id,
                'quantity_added' => $purchaseOrder->quantity,
                'new_stock_quantity' => $inventoryItem->stock_quantity
            ]);

            // Stock movement creation will be handled by event listener in Phase 4

        } catch (\Exception $e) {
            Log::error('Failed to update inventory on delivery', [
                'po_id' => $poId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Update supplier metrics after PO completion.
     */
    protected function updateSupplierMetrics(int $supplierId): void
    {
        try {
            $supplier = Supplier::findOrFail($supplierId);

            // Count completed purchase orders
            $completedOrders = PurchaseOrder::where('supplier_id', $supplierId)
                ->completed()
                ->count();

            $totalOrderValue = PurchaseOrder::where('supplier_id', $supplierId)
                ->completed()
                ->sum('total_cost');

            $lastOrder = PurchaseOrder::where('supplier_id', $supplierId)
                ->whereNotNull('completed_date')
                ->orderBy('completed_date', 'desc')
                ->first();

            // Update supplier
            $supplier->purchase_order_count = $completedOrders;
            $supplier->total_order_value = $totalOrderValue;
            $supplier->last_order_date = $lastOrder ? $lastOrder->completed_date : null;
            $supplier->save();

            Log::info('Supplier metrics updated', [
                'supplier_id' => $supplierId,
                'purchase_order_count' => $completedOrders,
                'total_order_value' => $totalOrderValue
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update supplier metrics', [
                'supplier_id' => $supplierId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get delivery performance metrics.
     */
    public function getDeliveryPerformance(int $shopOwnerId): array
    {
        $deliveredOrders = PurchaseOrder::where('shop_owner_id', $shopOwnerId)
            ->whereNotNull('actual_delivery_date')
            ->whereNotNull('expected_delivery_date')
            ->get();

        $onTime = 0;
        $late = 0;
        $early = 0;

        foreach ($deliveredOrders as $po) {
            $expected = \Carbon\Carbon::parse($po->expected_delivery_date);
            $actual = \Carbon\Carbon::parse($po->actual_delivery_date);

            if ($actual->equalTo($expected)) {
                $onTime++;
            } elseif ($actual->lessThan($expected)) {
                $early++;
            } else {
                $late++;
            }
        }

        $total = $deliveredOrders->count();

        return [
            'total_deliveries' => $total,
            'on_time' => $onTime,
            'early' => $early,
            'late' => $late,
            'on_time_percentage' => $total > 0 ? round(($onTime / $total) * 100, 2) : 0,
            'late_percentage' => $total > 0 ? round(($late / $total) * 100, 2) : 0,
        ];
    }
}
