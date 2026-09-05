<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class ProcurementDashboardService
{
    /** @var array<string, string> */
    private const REQUEST_STATUSES = [
        'draft' => 'Draft',
        'pending_finance' => 'Pending Finance',
        'pending_shop_owner' => 'Pending Shop Owner',
        'pending_finance_final' => 'Pending Finance Final',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];

    /** @var array<string, string> */
    private const ORDER_STATUSES = [
        'draft' => 'Draft',
        'sent' => 'Sent',
        'confirmed' => 'Confirmed',
        'in_transit' => 'In Transit',
        'partially_received' => 'Partially Received',
        'delivered' => 'Delivered',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    /** @var list<string> */
    private const AWAITING_REVIEW_STATUSES = [
        'pending_finance',
        'pending_shop_owner',
        'pending_finance_final',
    ];

    /**
     * @return array<string, mixed>
     */
    public function forShopOwner(int $shopOwnerId): array
    {
        $requestStatuses = $this->statusCounts(
            PurchaseRequest::query()->byShopOwner($shopOwnerId),
            self::REQUEST_STATUSES,
        );
        $orderStatuses = $this->statusCounts(
            PurchaseOrder::query()->byShopOwner($shopOwnerId),
            self::ORDER_STATUSES,
        );

        $requestTotal = PurchaseRequest::query()->byShopOwner($shopOwnerId)->count();
        $orderTotal = PurchaseOrder::query()->byShopOwner($shopOwnerId)->count();
        $awaitingReview = collect($requestStatuses)
            ->whereIn('key', self::AWAITING_REVIEW_STATUSES)
            ->sum('count');
        $openOrderValue = PurchaseOrder::query()
            ->byShopOwner($shopOwnerId)
            ->active()
            ->sum('total_cost');

        $periodStart = now()->startOfMonth()->subMonths(5);
        $periodEnd = now()->endOfMonth();
        $trendMonths = $this->trendMonths($periodStart);

        $requestDates = PurchaseRequest::query()
            ->byShopOwner($shopOwnerId)
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->pluck('created_at');
        $orderDates = PurchaseOrder::query()
            ->byShopOwner($shopOwnerId)
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->pluck('created_at');

        foreach ($requestDates as $createdAt) {
            $monthKey = Carbon::parse($createdAt)->format('Y-m');
            if (isset($trendMonths[$monthKey])) {
                $trendMonths[$monthKey]['purchase_requests']++;
            }
        }

        foreach ($orderDates as $createdAt) {
            $monthKey = Carbon::parse($createdAt)->format('Y-m');
            if (isset($trendMonths[$monthKey])) {
                $trendMonths[$monthKey]['purchase_orders']++;
            }
        }

        return [
            'title' => 'Procurement Dashboard',
            'description' => 'Keep purchasing requests organized, monitor supplier commitments, and review procurement activity for your shop.',
            'summary' => [
                'purchase_requests' => $requestTotal,
                'awaiting_review' => $awaitingReview,
                'purchase_orders' => $orderTotal,
                'open_order_value' => number_format((float) $openOrderValue, 2, '.', ''),
            ],
            'trend' => [
                'period_label' => 'Last 6 months',
                'months' => array_values($trendMonths),
            ],
            'request_statuses' => $requestStatuses,
            'order_statuses' => $orderStatuses,
            'recent_activity' => $this->recentActivity($shopOwnerId),
            'refreshed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, string>  $labels
     * @return array<int, array{key: string, label: string, count: int}>
     */
    private function statusCounts(Builder $query, array $labels): array
    {
        $counts = $query
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return collect($labels)
            ->map(static fn (string $label, string $key): array => [
                'key' => $key,
                'label' => $label,
                'count' => (int) ($counts[$key] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, array{label: string, start: string, end: string, purchase_requests: int, purchase_orders: int}>
     */
    private function trendMonths(Carbon $periodStart): array
    {
        $months = [];

        for ($offset = 0; $offset < 6; $offset++) {
            $month = $periodStart->copy()->addMonths($offset);
            $months[$month->format('Y-m')] = [
                'label' => $month->format('M Y'),
                'start' => $month->toDateString(),
                'end' => $month->copy()->endOfMonth()->toDateString(),
                'purchase_requests' => 0,
                'purchase_orders' => 0,
            ];
        }

        return $months;
    }

    /**
     * @return array<int, array{type: string, reference: string, description: string, status: string, amount: string, occurred_at: string, url: null}>
     */
    private function recentActivity(int $shopOwnerId): array
    {
        $requests = PurchaseRequest::query()
            ->byShopOwner($shopOwnerId)
            ->select(['pr_number', 'product_name', 'status', 'total_cost', 'created_at'])
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(fn (PurchaseRequest $request): array => [
                'type' => 'Purchase request',
                'reference' => (string) $request->pr_number,
                'description' => (string) $request->product_name,
                'status' => self::REQUEST_STATUSES[$request->status] ?? Str::headline((string) $request->status),
                'amount' => (string) $request->total_cost,
                'occurred_at' => $request->created_at?->toIso8601String() ?? '',
                'url' => null,
            ]);

        $orders = PurchaseOrder::query()
            ->byShopOwner($shopOwnerId)
            ->select(['po_number', 'product_name', 'status', 'total_cost', 'created_at'])
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(fn (PurchaseOrder $order): array => [
                'type' => 'Purchase order',
                'reference' => (string) $order->po_number,
                'description' => (string) $order->product_name,
                'status' => self::ORDER_STATUSES[$order->status] ?? Str::headline((string) $order->status),
                'amount' => (string) $order->total_cost,
                'occurred_at' => $order->created_at?->toIso8601String() ?? '',
                'url' => null,
            ]);

        return $requests
            ->concat($orders)
            ->sortByDesc('occurred_at')
            ->take(5)
            ->values()
            ->all();
    }
}
