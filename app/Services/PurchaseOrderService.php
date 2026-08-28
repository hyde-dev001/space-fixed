<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService
{
    /**
     * Create a new purchase order from an approved PR.
     */
    public function createPurchaseOrder(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data): PurchaseOrder {
            $shopOwnerId = (int) $data['shop_owner_id'];
            $ids = array_values(array_unique(array_map('intval', $data['purchase_request_ids'])));
            $purchaseRequests = PurchaseRequest::query()
                ->with('inventoryItem')
                ->where('shop_owner_id', $shopOwnerId)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($purchaseRequests->count() !== count($ids)) {
                throw ValidationException::withMessages(['purchase_request_ids' => 'A selected purchase request was not found.']);
            }
            if ($purchaseRequests->contains(fn ($request) => $request->status !== 'approved')) {
                throw ValidationException::withMessages(['purchase_request_ids' => 'Every purchase request must have final Finance approval.']);
            }
            if ($purchaseRequests->pluck('supplier_id')->filter()->unique()->count() !== 1
                || $purchaseRequests->contains(fn ($request) => !$request->supplier_id)) {
                throw ValidationException::withMessages(['purchase_request_ids' => 'Purchase requests must use the same supplier.']);
            }

            $supplier = Supplier::query()
                ->whereKey($purchaseRequests->first()->supplier_id)
                ->where('shop_owner_id', $shopOwnerId)
                ->where('is_active', true)
                ->first();
            if (!$supplier) {
                throw ValidationException::withMessages(['purchase_request_ids' => 'The selected supplier is inactive or unavailable.']);
            }
            if (PurchaseOrderItem::whereIn('purchase_request_id', $ids)
                ->whereHas('purchaseOrder', fn ($query) => $query->where('status', '!=', 'cancelled'))
                ->exists()) {
                throw ValidationException::withMessages(['purchase_request_ids' => 'A selected purchase request already has an active purchase order.']);
            }

            $snapshots = $purchaseRequests->map(function (PurchaseRequest $request): array {
                $sizeSnapshot = PurchaseOrderItem::snapshotSizeTargets(
                    $request->inventoryItem,
                    $request->requested_size,
                    $request->requested_color
                );
                $hasInventorySizes = $request->inventoryItem?->sizes()->exists() ?? false;
                $hasRequestedVariant = filled($request->requested_size) || filled($request->requested_color);
                if (($hasRequestedVariant && $hasInventorySizes)
                    && $sizeSnapshot['eligible_size_ids'] === []) {
                    throw ValidationException::withMessages([
                        'purchase_request_ids' => "{$request->pr_number} has no eligible inventory variant for the requested size/color.",
                    ]);
                }
                if (blank($request->requested_size) && $request->inventoryItem?->category === 'shoes'
                    && $sizeSnapshot['eligible_size_ids'] === []) {
                    throw ValidationException::withMessages([
                        'purchase_request_ids' => "{$request->pr_number} has no eligible size rows to order.",
                    ]);
                }

                return [
                    'purchase_request_id' => $request->id,
                    'inventory_item_id' => $request->inventory_item_id,
                    'product_name' => $request->product_name,
                    'requested_size' => $request->requested_size,
                    'requested_color' => $request->requested_color,
                    'ordered_quantity' => $request->quantity,
                    'unit_cost' => $request->unit_cost,
                    'line_total' => $request->total_cost,
                    ...$sizeSnapshot,
                    'source' => 'manual',
                ];
            });

            $first = $purchaseRequests->first();
            $single = $purchaseRequests->count() === 1;
            $purchaseOrder = $this->createHeaderWithUniqueNumber([
                'pr_id' => $single ? $first->id : null,
                'shop_owner_id' => $shopOwnerId,
                'supplier_id' => $first->supplier_id,
                'product_name' => $single ? $first->product_name : $purchaseRequests->count() . ' items',
                'inventory_item_id' => $single ? $first->inventory_item_id : null,
                'requested_size' => $single ? $first->requested_size : null,
                'requested_color' => $single ? $first->requested_color : null,
                'quantity' => $snapshots->sum('ordered_quantity'),
                'received_quantity' => 0,
                'defective_quantity' => 0,
                'unit_cost' => $single ? $first->unit_cost : 0,
                'total_cost' => $snapshots->sum(fn ($item) => (float) $item['line_total']),
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? 'Net 30',
                'notes' => $data['notes'] ?? null,
                'status' => 'draft',
                'ordered_by' => $data['ordered_by'],
                'ordered_date' => now(),
            ]);

            $purchaseOrder->items()->createMany($snapshots->all());

            Log::info('Purchase order created', [
                'po_id' => $purchaseOrder->id,
                'po_number' => $purchaseOrder->po_number,
                'purchase_request_ids' => $ids,
            ]);

            return $purchaseOrder->fresh(['items.purchaseRequest', 'supplier', 'orderer']);
        });
    }

    private function createHeaderWithUniqueNumber(array $attributes): PurchaseOrder
    {
        $poNumber = $this->generatePONumber((int) $attributes['shop_owner_id']);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            try {
                return PurchaseOrder::create(['po_number' => $poNumber, ...$attributes]);
            } catch (QueryException $exception) {
                $message = strtolower($exception->getMessage());
                if (!str_contains($message, 'po_number') || $attempt === 9) {
                    throw $exception;
                }
                $poNumber = preg_replace_callback('/(\d+)$/',
                    fn ($match) => str_pad((string) ((int) $match[1] + 1), strlen($match[1]), '0', STR_PAD_LEFT),
                    $poNumber
                );
            }
        }

        throw new \RuntimeException('Unable to generate a purchase order number.');
    }

    /**
     * Generate unique PO number for shop owner.
     */
    public function generatePONumber(int $shopOwnerId): string
    {
        $year = date('Y');

        // ponytail: annual O(n) scan is portable and sufficient for SME volume; use a sequence table if throughput outgrows it.
        $maxSequence = 0;
        foreach (PurchaseOrder::where('po_number', 'LIKE', "PO-{$year}-%")->pluck('po_number') as $poNumber) {
            if (preg_match("/^PO-{$year}-(\\d+)$/", (string) $poNumber, $matches) === 1) {
                $maxSequence = max($maxSequence, (int) $matches[1]);
            }
        }

        return sprintf('PO-%s-%03d', $year, $maxSequence + 1);
    }

    /**
     * Update purchase order status.
     */
    public function updateStatus(int $poId, string $status, int $userId, array $data = []): PurchaseOrder
    {
        DB::beginTransaction();

        try {
            $purchaseOrder = PurchaseOrder::query()->lockForUpdate()->findOrFail($poId);

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
                case 'completed':
                    $purchaseOrder->markAsCompleted($userId);
                    $this->updateSupplierMetrics($purchaseOrder->supplier_id);
                    break;
                default:
                    throw ValidationException::withMessages(['status' => 'Invalid manual purchase-order transition.']);
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
            $purchaseOrder = PurchaseOrder::query()->lockForUpdate()->findOrFail($poId);

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
     * Cancel a purchase order.
     */
    public function cancelPurchaseOrder(int $poId, int $userId, string $reason): PurchaseOrder
    {
        DB::beginTransaction();

        try {
            $purchaseOrder = PurchaseOrder::query()->lockForUpdate()->findOrFail($poId);

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
        $orders = PurchaseOrder::query()->byShopOwner($shopOwnerId);
        $totalOrders = (clone $orders)->count();
        $totalValue = (clone $orders)->sum('total_cost');
        $completedValue = (clone $orders)->completed()->sum('total_cost');

        return [
            'total_purchase_orders' => $totalOrders,
            'active_orders' => (clone $orders)->active()->count(),
            'awaiting_closure_orders' => (clone $orders)->awaitingClosure()->count(),
            'completed_orders' => (clone $orders)->completed()->count(),
            'cancelled_orders' => (clone $orders)->cancelled()->count(),
            'overdue_orders' => (clone $orders)->overdue()->count(),
            'draft_orders' => (clone $orders)->draft()->count(),
            'total_value' => $totalValue,
            'completed_value' => $completedValue,
            'average_order_value' => $totalOrders > 0
                ? $totalValue / $totalOrders
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
