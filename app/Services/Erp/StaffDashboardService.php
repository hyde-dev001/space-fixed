<?php

declare(strict_types=1);

namespace App\Services\Erp;

use App\Enums\OrderStatus;
use App\Models\HR\AttendanceRecord;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class StaffDashboardService
{
    private const OPEN_ORDER_STATUSES = [
        'pending',
        'processing',
        'shipped',
    ];

    private const TERMINAL_ORDER_STATUSES = [
        'completed',
        'delivered',
        'cancelled',
        'refund',
    ];

    /**
     * Build the authenticated staff member's shop-scoped dashboard snapshot.
     *
     * @return array<string, mixed>
     */
    public function snapshot(int $shopOwnerId, int $userId): array
    {
        $capturedAt = CarbonImmutable::now();
        $assignedOrders = Order::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('assigned_staff_id', $userId);

        $assignedOpenWork = (clone $assignedOrders)
            ->whereNotIn('status', self::TERMINAL_ORDER_STATUSES)
            ->count();
        $activeOrders = (clone $assignedOrders)
            ->whereIn('status', self::OPEN_ORDER_STATUSES)
            ->count();
        $completedToday = (clone $assignedOrders)
            ->whereIn('status', ['completed', 'delivered'])
            ->whereDate('updated_at', $capturedAt->toDateString())
            ->count();

        $staffUser = User::query()
            ->whereKey($userId)
            ->where('shop_owner_id', $shopOwnerId)
            ->first();
        $employeeId = $staffUser?->employee()
            ->where('shop_owner_id', $shopOwnerId)
            ->value('id');
        $attendance = $employeeId
            ? AttendanceRecord::query()
                ->forShopOwner($shopOwnerId)
                ->forEmployee((int) $employeeId)
                ->whereDate('date', $capturedAt->toDateString())
                ->latest('id')
                ->first()
            : null;
        $attendanceStatus = (string) ($attendance?->status ?? 'not_recorded');

        return [
            'title' => 'Staff Dashboard',
            'description' => 'Focus on the work assigned to you and keep customer orders moving.',
            'refreshed_at' => $capturedAt->toIso8601String(),
            'summary' => [
                'assigned_open_work' => (int) $assignedOpenWork,
                'active_orders' => (int) $activeOrders,
                'completed_today' => (int) $completedToday,
                'attendance_status' => $attendanceStatus,
            ],
            'attendance' => [
                'status' => $attendanceStatus,
                'label' => $this->label($attendanceStatus),
                'recorded_at' => $attendance?->created_at?->toISOString(),
            ],
            'trend' => [
                'period_label' => 'Assigned orders received',
                'points' => $this->orderTrend($assignedOrders, $capturedAt),
            ],
            'recent_work' => (clone $assignedOrders)
                ->select(['id', 'order_number', 'status', 'created_at'])
                ->latest('created_at')
                ->limit(6)
                ->get()
                ->map(fn (Order $order): array => [
                    'id' => (int) $order->id,
                    'reference' => (string) ($order->order_number ?: 'Order #' . $order->id),
                    'status' => $this->statusValue($order->status),
                    'created_at' => $order->created_at?->toISOString(),
                ])
                ->values()
                ->all(),
            'links' => [
                'orders' => '/erp/staff/job-orders',
                'customers' => '/erp/staff/customers',
                'attendance' => '/erp/time-in',
            ],
        ];
    }

    /**
     * @return array<int, array{label: string, start: string, assigned_orders: int}>
     */
    private function orderTrend(Builder $assignedOrders, CarbonImmutable $capturedAt): array
    {
        $month = $capturedAt->startOfMonth()->subMonths(5);
        $points = [];

        for ($index = 0; $index < 6; $index++) {
            $start = $month->addMonths($index)->startOfMonth();
            $end = $start->endOfMonth();
            $points[] = [
                'label' => $start->format('M'),
                'start' => $start->toDateString(),
                'assigned_orders' => (int) (clone $assignedOrders)
                    ->whereBetween('created_at', [$start, $end])
                    ->count(),
            ];
        }

        return $points;
    }

    private function statusValue(mixed $status): string
    {
        return $status instanceof OrderStatus ? $status->value : (string) $status;
    }

    private function label(string $value): string
    {
        return $value === 'not_recorded'
            ? 'Not recorded'
            : str($value)->replace('_', ' ')->title()->toString();
    }
}
