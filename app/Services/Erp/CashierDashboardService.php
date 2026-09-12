<?php

declare(strict_types=1);

namespace App\Services\Erp;

use App\Models\PosRefund;
use App\Models\PosTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class CashierDashboardService
{
    private const PAYMENT_STATUSES = [
        'paid',
        'partially_refunded',
        'refunded',
    ];

    private const MODULE_TYPES = [
        'retail',
        'repair',
    ];

    /**
     * Build a shop-scoped POS operations snapshot.
     *
     * @return array<string, mixed>
     */
    public function snapshot(int $shopOwnerId): array
    {
        $capturedAt = CarbonImmutable::now();
        $transactions = PosTransaction::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->whereIn('module_type', self::MODULE_TYPES);
        $todayStart = $capturedAt->startOfDay();
        $todayEnd = $capturedAt->endOfDay();

        $todayTransactions = (clone $transactions)
            ->whereBetween('paid_at', [$todayStart, $todayEnd])
            ->whereIn('status', self::PAYMENT_STATUSES);
        $todaySales = (float) (clone $todayTransactions)->sum('paid_amount');
        $refundQueue = PosRefund::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->whereIn('module_type', self::MODULE_TYPES)
            ->whereIn('status', ['requested', 'approved', 'processing', 'failed'])
            ->count();

        $statusBreakdown = (clone $transactions)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->orderByDesc('total')
            ->get()
            ->map(fn (object $row): array => [
                'status' => (string) $row->status,
                'count' => (int) $row->total,
            ])
            ->values()
            ->all();

        return [
            'title' => 'Cashier Dashboard',
            'description' => 'Keep payments, refunds, and in-store transactions moving with a clear daily view.',
            'refreshed_at' => $capturedAt->toIso8601String(),
            'summary' => [
                'today_transactions' => (int) $todayTransactions->count(),
                'today_sales' => number_format($todaySales, 2, '.', ''),
                'pending_payments' => (int) (clone $transactions)->where('status', 'pending')->count(),
                'refund_queue' => (int) $refundQueue,
            ],
            'trend' => [
                'period_label' => 'Daily settled sales',
                'points' => $this->dailyTrend($transactions, $capturedAt),
            ],
            'status_breakdown' => $statusBreakdown,
            'recent_transactions' => (clone $transactions)
                ->select(['id', 'transaction_no', 'module_type', 'total_amount', 'paid_amount', 'status', 'created_at'])
                ->latest('created_at')
                ->limit(8)
                ->get()
                ->map(fn (PosTransaction $transaction): array => [
                    'id' => (int) $transaction->id,
                    'transaction_no' => (string) $transaction->transaction_no,
                    'module_type' => (string) $transaction->module_type,
                    'total_amount' => number_format((float) $transaction->total_amount, 2, '.', ''),
                    'paid_amount' => number_format((float) $transaction->paid_amount, 2, '.', ''),
                    'status' => (string) $transaction->status,
                    'created_at' => $transaction->created_at?->toISOString(),
                ])
                ->values()
                ->all(),
            'links' => [
                'point_of_sale' => '/erp/cashier/point-of-sale',
            ],
        ];
    }

    /**
     * @return array<int, array{label: string, date: string, transactions: int, sales: string}>
     */
    private function dailyTrend(Builder $transactions, CarbonImmutable $capturedAt): array
    {
        $points = [];

        for ($offset = 6; $offset >= 0; $offset--) {
            $day = $capturedAt->subDays($offset);
            $start = $day->startOfDay();
            $end = $day->endOfDay();
            $dayQuery = (clone $transactions)
                ->whereBetween('paid_at', [$start, $end])
                ->whereIn('status', self::PAYMENT_STATUSES);

            $points[] = [
                'label' => $day->format('D'),
                'date' => $day->toDateString(),
                'transactions' => (int) (clone $dayQuery)->count(),
                'sales' => number_format((float) (clone $dayQuery)->sum('paid_amount'), 2, '.', ''),
            ];
        }

        return $points;
    }
}
