<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SupplierPerformanceService
{
    /**
     * Update all supplier metrics.
     */
    public function updateSupplierMetrics(int $supplierId): Supplier
    {
        DB::beginTransaction();

        try {
            $supplier = Supplier::findOrFail($supplierId);

            // Count completed purchase orders
            $completedOrders = PurchaseOrder::where('supplier_id', $supplierId)
                ->completed()
                ->count();

            // Calculate total order value
            $totalOrderValue = PurchaseOrder::where('supplier_id', $supplierId)
                ->completed()
                ->sum('total_cost');

            // Get last order date
            $lastOrder = PurchaseOrder::where('supplier_id', $supplierId)
                ->whereNotNull('completed_date')
                ->orderBy('completed_date', 'desc')
                ->first();

            // Update supplier
            $supplier->purchase_order_count = $completedOrders;
            $supplier->total_order_value = $totalOrderValue;
            $supplier->last_order_date = $lastOrder ? $lastOrder->completed_date : null;
            $supplier->save();

            DB::commit();

            Log::info('Supplier metrics updated', [
                'supplier_id' => $supplierId,
                'purchase_order_count' => $completedOrders,
                'total_order_value' => $totalOrderValue,
                'last_order_date' => $supplier->last_order_date
            ]);

            return $supplier->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update supplier metrics', [
                'supplier_id' => $supplierId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Calculate on-time delivery rate for a supplier.
     */
    public function calculateOnTimeDeliveryRate(int $supplierId): float
    {
        $deliveredOrders = PurchaseOrder::where('supplier_id', $supplierId)
            ->whereNotNull('actual_delivery_date')
            ->whereNotNull('expected_delivery_date')
            ->get();

        if ($deliveredOrders->isEmpty()) {
            return 0.0;
        }

        $onTimeCount = 0;

        foreach ($deliveredOrders as $po) {
            $expected = \Carbon\Carbon::parse($po->expected_delivery_date);
            $actual = \Carbon\Carbon::parse($po->actual_delivery_date);

            if ($actual->lessThanOrEqualTo($expected)) {
                $onTimeCount++;
            }
        }

        $rate = ($onTimeCount / $deliveredOrders->count()) * 100;

        Log::info('On-time delivery rate calculated', [
            'supplier_id' => $supplierId,
            'total_deliveries' => $deliveredOrders->count(),
            'on_time_deliveries' => $onTimeCount,
            'rate' => $rate
        ]);

        return round($rate, 2);
    }

    /**
     * Get total order value for a supplier.
     */
    public function getTotalOrderValue(int $supplierId): float
    {
        return PurchaseOrder::where('supplier_id', $supplierId)
            ->completed()
            ->sum('total_cost');
    }

    /**
     * Get average order value for a supplier.
     */
    public function getAverageOrderValue(int $supplierId): float
    {
        $completedOrders = PurchaseOrder::where('supplier_id', $supplierId)
            ->completed()
            ->get();

        if ($completedOrders->isEmpty()) {
            return 0.0;
        }

        $totalValue = $completedOrders->sum('total_cost');
        $averageValue = $totalValue / $completedOrders->count();

        return round($averageValue, 2);
    }

    /**
     * Update performance rating for a supplier.
     */
    public function updatePerformanceRating(int $supplierId, float $rating): Supplier
    {
        DB::beginTransaction();

        try {
            if ($rating < 1.0 || $rating > 5.0) {
                throw new \Exception('Performance rating must be between 1.00 and 5.00.');
            }

            $supplier = Supplier::findOrFail($supplierId);
            $supplier->performance_rating = $rating;
            $supplier->save();

            DB::commit();

            Log::info('Supplier performance rating updated', [
                'supplier_id' => $supplierId,
                'rating' => $rating
            ]);

            return $supplier->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update supplier performance rating', [
                'supplier_id' => $supplierId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get comprehensive performance metrics for a supplier.
     */
    public function getPerformanceMetrics(int $supplierId): array
    {
        $supplier = Supplier::findOrFail($supplierId);

        $completedOrders = PurchaseOrder::where('supplier_id', $supplierId)
            ->completed()
            ->get();

        $activeOrders = PurchaseOrder::where('supplier_id', $supplierId)
            ->active()
            ->count();

        $cancelledOrders = PurchaseOrder::where('supplier_id', $supplierId)
            ->cancelled()
            ->count();

        $onTimeDeliveryRate = $this->calculateOnTimeDeliveryRate($supplierId);
        $totalOrderValue = $this->getTotalOrderValue($supplierId);
        $averageOrderValue = $this->getAverageOrderValue($supplierId);

        // Calculate average delivery time
        $deliveredOrders = PurchaseOrder::where('supplier_id', $supplierId)
            ->whereNotNull('actual_delivery_date')
            ->whereNotNull('ordered_date')
            ->get();

        $totalDeliveryDays = 0;
        foreach ($deliveredOrders as $po) {
            $ordered = \Carbon\Carbon::parse($po->ordered_date);
            $delivered = \Carbon\Carbon::parse($po->actual_delivery_date);
            $totalDeliveryDays += $ordered->diffInDays($delivered);
        }

        $averageDeliveryTime = $deliveredOrders->isNotEmpty() 
            ? round($totalDeliveryDays / $deliveredOrders->count(), 1) 
            : 0;

        return [
            'supplier_id' => $supplierId,
            'supplier_name' => $supplier->name,
            'performance_rating' => $supplier->performance_rating,
            'total_orders' => $completedOrders->count(),
            'active_orders' => $activeOrders,
            'cancelled_orders' => $cancelledOrders,
            'total_order_value' => $totalOrderValue,
            'average_order_value' => $averageOrderValue,
            'on_time_delivery_rate' => $onTimeDeliveryRate,
            'average_delivery_time_days' => $averageDeliveryTime,
            'last_order_date' => $supplier->last_order_date,
            'products_supplied' => $supplier->products_supplied,
        ];
    }

    /**
     * Get top performing suppliers for a shop owner.
     */
    public function getTopSuppliers(int $shopOwnerId, int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return Supplier::where('shop_owner_id', $shopOwnerId)
            ->where('is_active', true)
            ->whereNotNull('performance_rating')
            ->orderBy('performance_rating', 'desc')
            ->orderBy('total_order_value', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get suppliers requiring attention (low ratings, overdue orders, etc.).
     */
    public function getSuppliersRequiringAttention(int $shopOwnerId): array
    {
        // Low-rated suppliers (rating < 3.0)
        $lowRatedSuppliers = Supplier::where('shop_owner_id', $shopOwnerId)
            ->where('is_active', true)
            ->whereNotNull('performance_rating')
            ->where('performance_rating', '<', 3.0)
            ->get();

        // Suppliers with overdue orders
        $overdueOrders = PurchaseOrder::whereHas('supplier', function ($query) use ($shopOwnerId) {
                $query->where('shop_owner_id', $shopOwnerId);
            })
            ->overdue()
            ->with('supplier')
            ->get();

        $suppliersWithOverdueOrders = $overdueOrders->pluck('supplier')->unique('id')->values();

        // Suppliers with no recent orders (>90 days)
        $inactiveSuppliers = Supplier::where('shop_owner_id', $shopOwnerId)
            ->where('is_active', true)
            ->whereNotNull('last_order_date')
            ->where('last_order_date', '<', now()->subDays(90))
            ->get();

        return [
            'low_rated_suppliers' => $lowRatedSuppliers,
            'suppliers_with_overdue_orders' => $suppliersWithOverdueOrders,
            'inactive_suppliers' => $inactiveSuppliers,
        ];
    }

    /**
     * Calculate and update performance rating based on delivery metrics.
     */
    public function calculateAutomaticRating(int $supplierId): float
    {
        $onTimeRate = $this->calculateOnTimeDeliveryRate($supplierId);
        
        // Simple rating calculation based on on-time delivery
        // 100% on-time = 5.0, 80% on-time = 4.0, etc.
        $rating = ($onTimeRate / 100) * 5;
        
        // Minimum rating of 1.0
        $rating = max(1.0, $rating);
        
        // Round to 2 decimal places
        return round($rating, 2);
    }

    /**
     * Bulk update metrics for all suppliers.
     */
    public function bulkUpdateMetrics(int $shopOwnerId): array
    {
        $suppliers = Supplier::where('shop_owner_id', $shopOwnerId)
            ->where('is_active', true)
            ->get();

        $updated = 0;
        $failed = 0;

        foreach ($suppliers as $supplier) {
            try {
                $this->updateSupplierMetrics($supplier->id);
                $updated++;
            } catch (\Exception $e) {
                $failed++;
                Log::error('Failed to update supplier metrics in bulk update', [
                    'supplier_id' => $supplier->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        Log::info('Bulk supplier metrics update completed', [
            'shop_owner_id' => $shopOwnerId,
            'total_suppliers' => $suppliers->count(),
            'updated' => $updated,
            'failed' => $failed
        ]);

        return [
            'total_suppliers' => $suppliers->count(),
            'updated' => $updated,
            'failed' => $failed,
        ];
    }
}
