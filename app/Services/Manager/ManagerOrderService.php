<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class ManagerOrderService
{
    public function __construct(
        private readonly ManagerAuthorizationService $authorization,
        private readonly ManagerAssignmentEligibilityService $eligibility,
    ) {
    }

    public function list(User $manager, array $filters): LengthAwarePaginator
    {
        return $this->listForShopOwnerId($this->authorizedShopOwnerId($manager), $filters);
    }

    /**
     * Read the same normalized workload projection for an authenticated
     * Shop Owner. This is intentionally separate from Manager mutations.
     */
    public function listForShopOwner(ShopOwner $owner, array $filters): LengthAwarePaginator
    {
        return $this->listForShopOwnerId((int) $owner->getKey(), $filters);
    }

    private function listForShopOwnerId(int $shopOwnerId, array $filters): LengthAwarePaginator
    {
        $query = Order::query()
            ->with(['assignedStaff', 'assignedByUser'])
            ->where('shop_owner_id', $shopOwnerId);

        $this->applyFilters($query, $filters, $shopOwnerId);

        $sort = $this->sortColumn($filters['sort'] ?? 'created_at');
        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage = max(5, min((int) ($filters['per_page'] ?? 25), 100));

        $paginator = $query
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Order $order): array => $this->serialize($order, $shopOwnerId)),
        );

        return $paginator;
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $manager, int $orderId): array
    {
        return $this->showForShopOwnerId($this->authorizedShopOwnerId($manager), $orderId);
    }

    /** @return array<string, mixed> */
    public function showForShopOwner(ShopOwner $owner, int $orderId): array
    {
        return $this->showForShopOwnerId((int) $owner->getKey(), $orderId);
    }

    /** @return array<string, mixed> */
    private function showForShopOwnerId(int $shopOwnerId, int $orderId): array
    {
        $order = $this->findOrder($shopOwnerId, $orderId);

        return $this->serialize($order, $shopOwnerId);
    }

    /**
     * @return list<array{id: int, name: string, email: string, workload: int}>
     */
    public function eligibleReplacements(User $manager, int $orderId): array
    {
        $shopOwnerId = $this->authorizedShopOwnerId($manager);
        $order = $this->findOrder($shopOwnerId, $orderId);
        $currentStaffId = (int) ($order->assigned_staff_id ?? 0);

        return $this->replacementQuery($shopOwnerId, $currentStaffId)
            ->get()
            ->filter(fn (User $staff): bool => $this->eligibility->evaluate(
                assignee: $staff,
                shopOwnerId: $shopOwnerId,
                workType: 'order',
                activeWorkDate: now(),
            )['eligible'])
            ->map(fn (User $staff): array => [
                'id' => (int) $staff->id,
                'name' => (string) ($staff->name ?: trim(($staff->first_name ?? '') . ' ' . ($staff->last_name ?? ''))),
                'email' => (string) $staff->email,
                'workload' => (int) $staff->assignedOrders()
                    ->whereNotIn('status', ['delivered', 'completed', 'cancelled', 'refund'])
                    ->count(),
            ])
            ->values()
            ->all();
    }

    public function reassign(
        User $manager,
        int $orderId,
        int $replacementStaffId,
        string $reason,
    ): Order {
        $shopOwnerId = $this->authorizedShopOwnerId($manager);

        if (! $this->authorization->allows($manager, ManagerAuthorizationService::ORDER_REASSIGN, $shopOwnerId)) {
            throw new AccessDeniedHttpException('Manager order reassignment is not authorized.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => ['A reassignment reason is required.'],
            ]);
        }

        return DB::transaction(function () use ($manager, $shopOwnerId, $orderId, $replacementStaffId, $reason): Order {
            $order = Order::query()
                ->whereKey($orderId)
                ->where('shop_owner_id', $shopOwnerId)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                throw (new ModelNotFoundException())->setModel(Order::class, [$orderId]);
            }

            if ($this->isTerminal($order)) {
                throw ValidationException::withMessages([
                    'assignment' => ['Terminal orders cannot be reassigned.'],
                ]);
            }

            $currentStaffId = (int) ($order->assigned_staff_id ?? 0);
            if ($currentStaffId < 1) {
                throw ValidationException::withMessages([
                    'assignment' => ['Only orders with a current handler can be reassigned.'],
                ]);
            }

            $currentStaff = User::withTrashed()
                ->whereKey($currentStaffId)
                ->where('shop_owner_id', $shopOwnerId)
                ->first();

            if ($currentStaff === null) {
                throw ValidationException::withMessages([
                    'assignment' => ['The current handler is no longer available.'],
                ]);
            }

            $currentDecision = $this->eligibility->evaluate(
                assignee: $currentStaff,
                shopOwnerId: $shopOwnerId,
                workType: 'order',
                activeWorkDate: now(),
            );

            if ($currentDecision['eligible']) {
                throw ValidationException::withMessages([
                    'assignment' => ['Reassignment is only allowed when the current handler is inactive or unavailable.'],
                ]);
            }

            $replacement = $this->replacementQuery($shopOwnerId, $currentStaffId)
                ->whereKey($replacementStaffId)
                ->first();

            if ($replacement === null) {
                throw ValidationException::withMessages([
                    'replacement_staff_id' => ['The replacement staff member is not eligible for this shop.'],
                ]);
            }

            $replacementDecision = $this->eligibility->evaluate(
                assignee: $replacement,
                shopOwnerId: $shopOwnerId,
                workType: 'order',
                activeWorkDate: now(),
            );

            if (! $replacementDecision['eligible']) {
                throw ValidationException::withMessages([
                    'replacement_staff_id' => [$replacementDecision['reason_label'] ?? 'The replacement staff member is not eligible.'],
                ]);
            }

            $order->forceFill([
                'assigned_staff_id' => $replacement->id,
                'assigned_at' => now(),
                'assignment_method' => 'manual',
                'assigned_by' => $manager->id,
            ])->save();

            AuditLog::create([
                'shop_owner_id' => $shopOwnerId,
                'user_id' => $manager->id,
                'actor_user_id' => $manager->id,
                'action' => 'order_reassigned',
                'object_type' => 'order',
                'object_id' => $order->id,
                'target_type' => 'order',
                'target_id' => $order->id,
                'metadata' => [
                    'previous_state' => [
                        'assigned_staff_id' => $currentStaffId,
                    ],
                    'new_state' => [
                        'assigned_staff_id' => (int) $replacement->id,
                    ],
                    'previous_handler_id' => $currentStaffId,
                    'replacement_handler_id' => (int) $replacement->id,
                    'actor_id' => (int) $manager->id,
                    'reason' => $reason,
                    'reference_id' => 'order:' . $order->id,
                    'trigger_reason_code' => $currentDecision['reason_code'],
                    'trigger_reason_label' => $currentDecision['reason_label'],
                ],
            ]);

            return $order->fresh(['assignedStaff', 'assignedByUser']) ?? $order;
        });
    }

    private function authorizedShopOwnerId(User $manager): int
    {
        $shopOwnerId = $this->authorization->shopOwnerId($manager);

        if ($shopOwnerId === null) {
            throw new AccessDeniedHttpException('Manager shop scope is required.');
        }

        return $shopOwnerId;
    }

    private function findOrder(int $shopOwnerId, int $orderId): Order
    {
        return Order::query()
            ->with(['assignedStaff', 'assignedByUser'])
            ->where('shop_owner_id', $shopOwnerId)
            ->whereKey($orderId)
            ->firstOrFail();
    }

    private function replacementQuery(int $shopOwnerId, int $excludedStaffId)
    {
        return User::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('status', 'active')
            ->whereKeyNot($excludedStaffId)
            ->where(function ($query): void {
                $query
                    ->whereRaw('UPPER(role) = ?', ['STAFF'])
                    ->orWhereHas('roles', fn ($roleQuery) => $roleQuery->where('name', 'Staff'));
            })
            ->withCount([
                'assignedOrders as active_orders_count' => fn ($query) => $query
                    ->whereNotIn('status', ['delivered', 'completed', 'cancelled', 'refund']),
            ])
            ->orderBy('active_orders_count')
            ->orderBy('id');
    }

    private function applyFilters($query, array $filters, int $shopOwnerId): void
    {
        if (($status = trim((string) ($filters['status'] ?? ''))) !== '') {
            $query->where('status', $status);
        }

        if (isset($filters['handler_id']) && is_numeric($filters['handler_id'])) {
            $query->where('assigned_staff_id', (int) $filters['handler_id']);
        }

        $assignmentState = strtolower(trim((string) ($filters['assignment_state'] ?? '')));
        if ($assignmentState === 'unassigned') {
            $query->whereNull('assigned_staff_id');
        } elseif ($assignmentState === 'assigned') {
            $query->whereNotNull('assigned_staff_id');
        } elseif ($assignmentState === 'reassignment_required') {
            $ids = $this->reassignmentRequiredOrderIds($shopOwnerId);
            $query->whereIn('id', $ids === [] ? [-1] : $ids);
        }

        if (($startDate = trim((string) ($filters['date_from'] ?? ''))) !== '') {
            $query->whereDate('created_at', '>=', $startDate);
        }

        if (($endDate = trim((string) ($filters['date_to'] ?? ''))) !== '') {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $slaMinutes = $this->slaMinutes();
        $overdue = filter_var($filters['overdue'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($overdue && $slaMinutes !== null) {
            $query->where('created_at', '<=', now()->subMinutes($slaMinutes));
        } elseif ($overdue) {
            $query->whereRaw('1 = 0');
        }
    }

    /** @return list<int> */
    private function reassignmentRequiredOrderIds(int $shopOwnerId): array
    {
        return Order::query()
            ->with('assignedStaff')
            ->where('shop_owner_id', $shopOwnerId)
            ->whereNotNull('assigned_staff_id')
            ->get()
            ->filter(function (Order $order): bool {
                $handler = $order->assignedStaff;

                if (! $handler instanceof User) {
                    return true;
                }

                return ! $this->eligibility->evaluate(
                    assignee: $handler,
                    shopOwnerId: (int) $order->shop_owner_id,
                    workType: 'order',
                    activeWorkDate: now(),
                )['eligible'];
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function serialize(Order $order, int $shopOwnerId): array
    {
        $handler = $order->assignedStaff;
        $decision = $handler instanceof User
            ? $this->eligibility->evaluate(
                assignee: $handler,
                shopOwnerId: $shopOwnerId,
                workType: 'order',
                activeWorkDate: now(),
            )
            : ['eligible' => false, 'reason_code' => null, 'reason_label' => null];
        $assignmentState = $handler === null
            ? 'unassigned'
            : ($decision['eligible'] ? 'assigned' : 'reassignment_required');
        $ageMinutes = max(0, (int) ($order->created_at?->diffInMinutes(now()) ?? 0));

        return [
            'id' => (int) $order->id,
            'order_number' => (string) $order->order_number,
            'customer_name' => (string) ($order->customer_name ?? 'Guest'),
            'status' => $this->value($order->status),
            'assigned_staff' => $handler ? [
                'id' => (int) $handler->id,
                'name' => (string) ($handler->name ?: trim(($handler->first_name ?? '') . ' ' . ($handler->last_name ?? ''))),
                'status' => (string) ($handler->status ?? 'active'),
            ] : null,
            'age_minutes' => $ageMinutes,
            'overdue' => $this->isOverdue($ageMinutes),
            'lock_state' => $order->assigned_staff_id ? 'locked' : 'claimable',
            'assignment_state' => $assignmentState,
            'reassignment_reason_code' => $assignmentState === 'reassignment_required' ? $decision['reason_code'] : null,
            'reassignment_reason_label' => $assignmentState === 'reassignment_required' ? $decision['reason_label'] : null,
            'next_action' => $this->nextAction($order, $assignmentState),
            'created_at' => $order->created_at?->toISOString(),
            'updated_at' => $order->updated_at?->toISOString(),
        ];
    }

    private function nextAction(Order $order, string $assignmentState): string
    {
        if ($assignmentState === 'reassignment_required') {
            return 'Manager reassignment required';
        }

        if ($assignmentState === 'unassigned' && $this->value($order->status) === 'pending') {
            return 'Staff claim required';
        }

        if ($this->isTerminal($order)) {
            return 'No action required';
        }

        return 'Current handler to continue';
    }

    private function isOverdue(int $ageMinutes): bool
    {
        $slaMinutes = $this->slaMinutes();

        return $slaMinutes !== null && $ageMinutes >= $slaMinutes;
    }

    private function slaMinutes(): ?int
    {
        $configured = config('manager.order_sla_minutes');

        return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : null;
    }

    private function sortColumn(string $requested): string
    {
        return match ($requested) {
            'order_number', 'status', 'assigned_staff_id', 'created_at', 'updated_at' => $requested,
            default => 'created_at',
        };
    }

    private function isTerminal(Order $order): bool
    {
        return in_array($this->value($order->status), ['delivered', 'completed', 'cancelled', 'refund'], true);
    }

    private function value(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        return strtolower(trim((string) $value));
    }
}
