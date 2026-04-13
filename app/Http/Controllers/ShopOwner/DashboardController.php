<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use App\Models\PosRefund;
use App\Models\RepairRequest;
use App\Enums\OrderStatus;
use App\Enums\ApprovalStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class DashboardController extends Controller
{
    private const VAT_RATE_PERCENT = 12.0;
    private const VAT_DIVISOR = 1.12;

    private function repairRevenueExpression(): string
    {
        $vatDivisor = self::VAT_DIVISOR;

        return "
            CASE
                WHEN (COALESCE(total_paid_amount, 0) > 0 OR COALESCE(total_refunded_amount, 0) > 0)
                    THEN CASE
                        WHEN (COALESCE(total_paid_amount, 0) - COALESCE(total_refunded_amount, 0)) < 0 THEN 0
                        ELSE ((COALESCE(total_paid_amount, 0) - COALESCE(total_refunded_amount, 0)) / {$vatDivisor})
                    END
                WHEN payment_status = 'completed'
                    THEN (COALESCE(final_total, total, 0) / {$vatDivisor})
                WHEN payment_status = 'paid'
                    THEN CASE
                        WHEN COALESCE(payment_policy_snapshot, payment_policy, 'deposit_50') = 'deposit_50'
                            THEN ((COALESCE(final_total, total, 0) * 0.5) / {$vatDivisor})
                        ELSE (COALESCE(final_total, total, 0) / {$vatDivisor})
                    END
                ELSE 0
            END
        ";
    }

    private function computeRepairRevenue($query): float
    {
        return (float) $this->applyRepairRevenueEligibility($query)
            ->sum(DB::raw($this->repairRevenueExpression()));
    }

    private function applyRepairRevenueEligibility($query)
    {
        return $query->where(function ($innerQuery) {
            $innerQuery->whereNull('is_warranty_job')
                ->orWhere('is_warranty_job', false);
        });
    }

    private function retailGrossRevenueExpression(): string
    {
        $hasGrandTotal = Schema::hasColumn('orders', 'grand_total');
        $hasShippingFee = Schema::hasColumn('orders', 'shipping_fee');
        $hasVatAmount = Schema::hasColumn('orders', 'vat_amount');

        $fallbackParts = ['COALESCE(total_amount, 0)'];
        if ($hasShippingFee) {
            $fallbackParts[] = 'COALESCE(shipping_fee, 0)';
        }
        if ($hasVatAmount) {
            $fallbackParts[] = 'COALESCE(vat_amount, 0)';
        }

        $fallbackExpression = '(' . implode(' + ', $fallbackParts) . ')';

        if (!$hasGrandTotal) {
            return $fallbackExpression;
        }

        return "
            CASE
                WHEN COALESCE(grand_total, 0) > 0
                    THEN COALESCE(grand_total, 0)
                ELSE {$fallbackExpression}
            END
        ";
    }

    private function applyRetailRevenueDateWindow($query, ?Carbon $from = null, ?Carbon $to = null, ?Carbon $onDate = null): void
    {
        if ($onDate) {
            $query->whereDate('created_at', $onDate->toDateString());
            return;
        }

        if ($from) {
            $query->where('created_at', '>=', $from->copy()->startOfDay());
        }

        if ($to) {
            $query->where('created_at', '<=', $to->copy()->endOfDay());
        }
    }

    private function computeRetailNetRevenue(int $shopOwnerId, ?Carbon $from = null, ?Carbon $to = null, ?Carbon $onDate = null): float
    {
        $ordersQuery = Order::query()
            ->select('id')
            ->selectRaw($this->retailGrossRevenueExpression() . ' as gross_amount')
            ->where('shop_owner_id', $shopOwnerId)
            ->where('status', '!=', OrderStatus::CANCELLED->value);

        $this->applyRetailRevenueDateWindow($ordersQuery, $from, $to, $onDate);

        $orders = $ordersQuery->get();
        if ($orders->isEmpty()) {
            return 0.0;
        }

        $orderIds = $orders->pluck('id')->map(fn ($id) => (int) $id)->all();

        $refundAmountByOrder = $this->getSucceededRefundAmountByOrder($orderIds);

        $netRevenue = 0.0;
        foreach ($orders as $order) {
            $orderId = (int) ($order->id ?? 0);
            $grossAmount = max(0.0, (float) ($order->gross_amount ?? 0.0));
            if ($orderId <= 0 || $grossAmount <= 0) {
                continue;
            }

            $totalRefunded = max(0.0, (float) ($refundAmountByOrder[$orderId] ?? 0.0));
            $netRevenue += max(0.0, $grossAmount - min($grossAmount, $totalRefunded));
        }

        return round($netRevenue, 2);
    }

    private function getSucceededRefundAmountByOrder(array $orderIds): array
    {
        if (empty($orderIds)) {
            return [];
        }

        $onlineRefundByOrder = [];
        $onlineRefunds = OrderRefund::query()
            ->select(['id', 'order_id', 'amount'])
            ->whereIn('order_id', $orderIds)
            ->where('status', 'succeeded')
            ->get();

        if ($onlineRefunds->isNotEmpty()) {
            $lineRefundByRefundId = collect();

            if (Schema::hasTable('order_refund_items')) {
                $lineRefundByRefundId = DB::table('order_refund_items')
                    ->select('order_refund_id', DB::raw('SUM(COALESCE(line_amount, 0)) as line_total'))
                    ->whereIn('order_refund_id', $onlineRefunds->pluck('id')->all())
                    ->groupBy('order_refund_id')
                    ->pluck('line_total', 'order_refund_id');
            }

            foreach ($onlineRefunds as $refund) {
                $lineAmount = (float) ($lineRefundByRefundId[$refund->id] ?? 0.0);
                $effectiveRefundAmount = $lineAmount > 0
                    ? $lineAmount
                    : (float) ($refund->amount ?? 0.0);

                if ($effectiveRefundAmount <= 0) {
                    continue;
                }

                $orderId = (int) ($refund->order_id ?? 0);
                if ($orderId <= 0) {
                    continue;
                }

                $onlineRefundByOrder[$orderId] = (float) (($onlineRefundByOrder[$orderId] ?? 0.0) + $effectiveRefundAmount);
            }
        }

        $posRefundByOrder = [];
        if (Schema::hasTable('pos_refunds')) {
            $posRefundRows = PosRefund::query()
                ->select('module_reference_id')
                ->selectRaw('SUM(COALESCE(approved_amount, requested_amount, 0)) as total_refunded')
                ->where('module_type', 'retail')
                ->where('status', 'succeeded')
                ->whereIn('module_reference_id', $orderIds)
                ->groupBy('module_reference_id')
                ->get();

            foreach ($posRefundRows as $row) {
                $moduleReferenceId = (int) ($row->module_reference_id ?? 0);
                if ($moduleReferenceId <= 0) {
                    continue;
                }

                $posRefundByOrder[$moduleReferenceId] = (float) ($row->total_refunded ?? 0.0);
            }
        }

        $combined = [];
        foreach ($orderIds as $orderId) {
            $id = (int) $orderId;
            if ($id <= 0) {
                continue;
            }

            $combined[$id] = round(
                max(0.0, (float) ($onlineRefundByOrder[$id] ?? 0.0) + (float) ($posRefundByOrder[$id] ?? 0.0)),
                2,
            );
        }

        return $combined;
    }

    /**
     * Get dashboard statistics for shop owner
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStats()
    {
        $shopOwner = Auth::guard('shop_owner')->user();
        
        if (!$shopOwner) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $shopOwnerId = $shopOwner->id;

        // Get date ranges
        $today = Carbon::today();
        $thisMonth = Carbon::now()->startOfMonth();
        $lastMonth = Carbon::now()->subMonth()->startOfMonth();
        $lastMonthEnd = Carbon::now()->subMonth()->endOfMonth();

        // Total Revenue (all time) - Include both retail orders and repair services
        $retailRevenue = $this->computeRetailNetRevenue($shopOwnerId);
        
        $repairRevenue = $this->computeRepairRevenue(
            RepairRequest::where('shop_owner_id', $shopOwnerId)
        );
        
        $totalRevenue = $retailRevenue + $repairRevenue;

        // This Month Revenue - Include both retail and repair
        $thisMonthRetailRevenue = $this->computeRetailNetRevenue($shopOwnerId, $thisMonth, $today);
        
        $thisMonthRepairRevenue = $this->computeRepairRevenue(
            RepairRequest::where('shop_owner_id', $shopOwnerId)
                ->whereMonth('created_at', Carbon::now()->month)
                ->whereYear('created_at', Carbon::now()->year)
        );
        
        $thisMonthRevenue = $thisMonthRetailRevenue + $thisMonthRepairRevenue;

        // Last Month Revenue - Include both retail and repair
        $lastMonthRetailRevenue = $this->computeRetailNetRevenue($shopOwnerId, $lastMonth, $lastMonthEnd);
        
        $lastMonthRepairRevenue = $this->computeRepairRevenue(
            RepairRequest::where('shop_owner_id', $shopOwnerId)
                ->whereMonth('created_at', Carbon::now()->subMonth()->month)
                ->whereYear('created_at', Carbon::now()->subMonth()->year)
        );
        
        $lastMonthRevenue = $lastMonthRetailRevenue + $lastMonthRepairRevenue;

        // Revenue Growth
        $revenueGrowth = $lastMonthRevenue > 0 
            ? (($thisMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100 
            : 0;

        // Total Orders (include both retail and repair)
        $totalRetailOrders = Order::where('shop_owner_id', $shopOwnerId)->count();
        $totalRepairOrders = RepairRequest::where('shop_owner_id', $shopOwnerId)->count();
        $totalOrders = $totalRetailOrders + $totalRepairOrders;

        // This Month Orders (include both retail and repair)
        $thisMonthRetailOrders = Order::where('shop_owner_id', $shopOwnerId)
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year)
            ->count();
        
        $thisMonthRepairOrders = RepairRequest::where('shop_owner_id', $shopOwnerId)
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year)
            ->count();
        
        $thisMonthOrders = $thisMonthRetailOrders + $thisMonthRepairOrders;

        // Last Month Orders (include both retail and repair)
        $lastMonthRetailOrders = Order::where('shop_owner_id', $shopOwnerId)
            ->whereMonth('created_at', Carbon::now()->subMonth()->month)
            ->whereYear('created_at', Carbon::now()->subMonth()->year)
            ->count();
        
        $lastMonthRepairOrders = RepairRequest::where('shop_owner_id', $shopOwnerId)
            ->whereMonth('created_at', Carbon::now()->subMonth()->month)
            ->whereYear('created_at', Carbon::now()->subMonth()->year)
            ->count();
        
        $lastMonthOrders = $lastMonthRetailOrders + $lastMonthRepairOrders;

        // Orders Growth
        $ordersGrowth = $lastMonthOrders > 0 
            ? (($thisMonthOrders - $lastMonthOrders) / $lastMonthOrders) * 100 
            : 0;

        // Total Products
        $totalProducts = Product::where('shop_owner_id', $shopOwnerId)->count();

        // Active Products (in stock)
        $activeProducts = Product::where('shop_owner_id', $shopOwnerId)
            ->where('stock_quantity', '>', 0)
            ->count();

        // Low Stock Products (stock < 10)
        $lowStockProducts = Product::where('shop_owner_id', $shopOwnerId)
            ->where('stock_quantity', '<', 10)
            ->where('stock_quantity', '>', 0)
            ->count();

        // Out of Stock Products
        $outOfStockProducts = Product::where('shop_owner_id', $shopOwnerId)
            ->where('stock_quantity', '<=', 0)
            ->count();

        // Pending Orders
        $pendingOrders = Order::where('shop_owner_id', $shopOwnerId)
            ->where('status', OrderStatus::PENDING)
            ->count();

        // Processing Orders
        $processingOrders = Order::where('shop_owner_id', $shopOwnerId)
            ->where('status', OrderStatus::PROCESSING)
            ->count();

        // Shipped Orders
        $shippedOrders = Order::where('shop_owner_id', $shopOwnerId)
            ->where('status', OrderStatus::SHIPPED)
            ->count();

        // Completed Orders
        $completedOrders = Order::where('shop_owner_id', $shopOwnerId)
            ->where('status', OrderStatus::COMPLETED)
            ->count();

        // Cancelled Orders
        $cancelledOrders = Order::where('shop_owner_id', $shopOwnerId)
            ->where('status', OrderStatus::CANCELLED)
            ->count();

        // Refunded vs partially refunded orders (full/partial derived from succeeded refund amounts).
        $ordersForRefundState = Order::query()
            ->select('id', 'status', 'payment_status')
            ->selectRaw($this->retailGrossRevenueExpression() . ' as gross_amount')
            ->where('shop_owner_id', $shopOwnerId)
            ->get();

        $refundAmountByOrder = $this->getSucceededRefundAmountByOrder(
            $ordersForRefundState->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );

        $refundedOrders = 0;
        $partiallyRefundedOrders = 0;

        foreach ($ordersForRefundState as $orderRow) {
            $orderId = (int) ($orderRow->id ?? 0);
            if ($orderId <= 0) {
                continue;
            }

            $gross = max(0.0, (float) ($orderRow->gross_amount ?? 0.0));
            $refundedAmount = max(0.0, (float) ($refundAmountByOrder[$orderId] ?? 0.0));
            $rawPaymentStatus = $orderRow->payment_status ?? '';
            $rawOrderStatus = $orderRow->status ?? '';
            $paymentStatus = strtolower((string) ($rawPaymentStatus instanceof \BackedEnum ? $rawPaymentStatus->value : $rawPaymentStatus));
            $orderStatus = strtolower((string) ($rawOrderStatus instanceof \BackedEnum ? $rawOrderStatus->value : $rawOrderStatus));

            if ($gross > 0 && $refundedAmount > 0) {
                if ($refundedAmount >= ($gross - 0.01)) {
                    $refundedOrders++;
                } else {
                    $partiallyRefundedOrders++;
                }

                continue;
            }

            $fallbackFullyRefunded = $paymentStatus === 'refunded' || $orderStatus === 'refund';
            if ($fallbackFullyRefunded) {
                $refundedOrders++;
                continue;
            }

            $fallbackPartiallyRefunded = $paymentStatus === 'partially_refunded' || $orderStatus === 'partially_refunded';
            if ($fallbackPartiallyRefunded) {
                $partiallyRefundedOrders++;
            }
        }

        // Top Selling Products (last 30 days)
        $topProducts = OrderItem::select('product_id', 'product_name', 'product_slug', 'product_image')
            ->selectRaw('SUM(quantity) as total_quantity')
            ->selectRaw('SUM(subtotal) as total_revenue')
            ->whereHas('order', function($query) use ($shopOwnerId) {
                $query->where('shop_owner_id', $shopOwnerId)
                    ->whereIn('status', ['processing', 'shipped', 'completed', 'delivered'])
                    ->where(function ($innerQuery) {
                        $innerQuery->whereNull('payment_status')
                            ->orWhere('payment_status', '!=', 'refunded');
                    })
                    ->where('created_at', '>=', Carbon::now()->subDays(30));
            })
            ->groupBy('product_id', 'product_name', 'product_slug', 'product_image')
            ->orderByDesc('total_quantity')
            ->limit(5)
            ->get();

        // Recent Orders (last 10)
        $recentOrdersRaw = Order::where('shop_owner_id', $shopOwnerId)
            ->with(['customer', 'items.product'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $recentRefundAmountByOrder = $this->getSucceededRefundAmountByOrder(
            $recentOrdersRaw->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );

        $recentOrders = $recentOrdersRaw->map(function($order) use ($recentRefundAmountByOrder) {
                $paymentStatus = $order->payment_status instanceof \BackedEnum
                    ? $order->payment_status->value
                    : (string) ($order->payment_status ?? '');

                $orderStatus = $order->status instanceof \BackedEnum
                    ? $order->status->value
                    : (string) ($order->status ?? '');

                $grossAmount = (float) ($order->grand_total ?? 0);
                if ($grossAmount <= 0) {
                    $grossAmount = (float) ($order->total_amount ?? 0)
                        + (float) ($order->shipping_fee ?? 0)
                        + (float) ($order->vat_amount ?? 0);
                }

                $refundedAmount = max(0.0, (float) ($recentRefundAmountByOrder[(int) $order->id] ?? 0.0));
                $normalizedPaymentStatus = strtolower(trim($paymentStatus));
                $normalizedOrderStatus = strtolower(trim($orderStatus));
                $isFullyRefunded = false;
                $isPartiallyRefunded = false;

                if ($grossAmount > 0 && $refundedAmount > 0) {
                    $isFullyRefunded = $refundedAmount >= ($grossAmount - 0.01);
                    $isPartiallyRefunded = !$isFullyRefunded;
                } else {
                    $isFullyRefunded = $normalizedPaymentStatus === 'refunded' || $normalizedOrderStatus === 'refund';
                    $isPartiallyRefunded = !$isFullyRefunded
                        && (
                            $normalizedPaymentStatus === 'partially_refunded'
                            || $normalizedOrderStatus === 'partially_refunded'
                        );
                }

                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer_name' => $order->customer_name ?? $order->customer?->name ?? 'Guest',
                    'customer_email' => $order->customer_email ?? $order->customer?->email ?? '',
                    'total_amount' => $order->total_amount,
                    'status' => $isFullyRefunded
                        ? 'refunded'
                        : ($isPartiallyRefunded ? 'partially_refunded' : $orderStatus),
                    'items_count' => $order->items->count(),
                    'order_items' => $order->items->map(function($item) {
                        return [
                            'id' => $item->id,
                            'product_id' => $item->product_id,
                            'quantity' => $item->quantity,
                            'price' => $item->price,
                            'product' => $item->product ? [
                                'id' => $item->product->id,
                                'name' => $item->product->name,
                                'images' => $item->product->images,
                            ] : null,
                        ];
                    }),
                    'created_at' => $order->created_at->toISOString(),
                ];
            });

        // Revenue trend (last 7 days) - Include both retail and repair
        $revenueTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            
            $retailRevenue = $this->computeRetailNetRevenue($shopOwnerId, null, null, $date);
            
            $repairRevenue = $this->computeRepairRevenue(
                RepairRequest::where('shop_owner_id', $shopOwnerId)
                    ->whereDate('created_at', $date)
            );
            
            $revenueTrend[] = [
                'date' => $date->format('M d'),
                'revenue' => floatval($retailRevenue + $repairRevenue),
            ];
        }

        // Unique Customers
        $uniqueCustomers = Order::where('shop_owner_id', $shopOwnerId)
            ->distinct('customer_id')
            ->whereNotNull('customer_id')
            ->count('customer_id');

        // Guest Orders (no customer_id)
        $guestOrders = Order::where('shop_owner_id', $shopOwnerId)
            ->whereNull('customer_id')
            ->count();

        // Total Customers (unique + guests)
        $totalCustomers = $uniqueCustomers + $guestOrders;

        // Repeat Customers (customers with more than 1 order)
        $repeatCustomers = Order::where('shop_owner_id', $shopOwnerId)
            ->whereNotNull('customer_id')
            ->select('customer_id')
            ->groupBy('customer_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        // Average Order Value
        $avgOrderValue = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;

        return response()->json([
            'revenue' => [
                'total' => floatval($totalRevenue),
                'this_month' => floatval($thisMonthRevenue),
                'last_month' => floatval($lastMonthRevenue),
                'growth' => round($revenueGrowth, 2),
                'growth_percentage' => round($revenueGrowth, 2),
                'average_order' => round($avgOrderValue, 2),
            ],
            'orders' => [
                'total' => $totalOrders,
                'this_month' => $thisMonthOrders,
                'last_month' => $lastMonthOrders,
                'growth' => round($ordersGrowth, 2),
                'growth_percentage' => round($ordersGrowth, 2),
                'pending' => $pendingOrders,
                'processing' => $processingOrders,
                'shipped' => $shippedOrders,
                'completed' => $completedOrders,
                'cancelled' => $cancelledOrders,
                'refunded' => $refundedOrders,
                'partially_refunded' => $partiallyRefundedOrders,
            ],
            'products' => [
                'total' => $totalProducts,
                'active' => $activeProducts,
                'low_stock' => $lowStockProducts,
                'out_of_stock' => $outOfStockProducts,
            ],
            'customers' => [
                'total' => $totalCustomers,
                'unique' => $uniqueCustomers,
                'guests' => $guestOrders,
                'repeat' => $repeatCustomers,
                'unique_customers' => $uniqueCustomers,
                'guest_orders' => $guestOrders,
                'repeat_customers' => $repeatCustomers,
            ],
            'top_products' => $topProducts->map(function($item) {
                return [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'product_slug' => $item->product_slug,
                    'product_image' => $item->product_image,
                    'total_quantity' => $item->total_quantity,
                    'total_revenue' => floatval($item->total_revenue),
                ];
            }),
            'recent_orders' => $recentOrders,
            'revenue_trend' => $revenueTrend,
        ]);
    }

    /**
     * Get low stock alerts for shop owner
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLowStockAlerts()
    {
        $shopOwner = Auth::guard('shop_owner')->user();
        
        if (!$shopOwner) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $lowStockProducts = Product::where('shop_owner_id', $shopOwner->id)
            ->where('stock_quantity', '<', 10)
            ->orderBy('stock_quantity', 'asc')
            ->get()
            ->map(function($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'stock_quantity' => $product->stock_quantity,
                    'price' => $product->price,
                    'image' => $product->image,
                    'status' => $product->stock_quantity <= 0 ? 'out_of_stock' : 'low_stock',
                ];
            });

        return response()->json($lowStockProducts);
    }
}
