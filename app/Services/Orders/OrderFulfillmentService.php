<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\Logistics\SourceShipmentService;
use App\Services\NotificationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class OrderFulfillmentService
{
    public function __construct(
        private readonly OrderTransitionPolicy $transitionPolicy,
        private readonly SourceShipmentService $sourceShipmentService,
        private readonly NotificationService $notificationService,
    ) {}

    public function markProcessing(Order $order, Authenticatable $actor): Order
    {
        return DB::transaction(function () use ($order, $actor): Order {
            $lockedOrder = $this->lockShopOrder($order, $actor);

            $this->assertAllowed(
                $this->transitionPolicy->canMarkProcessing($lockedOrder),
                $lockedOrder,
                OrderStatus::PROCESSING,
            );

            $this->persistStatus($lockedOrder, OrderStatus::PROCESSING, $actor);

            return $this->fresh($lockedOrder);
        });
    }

    public function markShipped(Order $order, Authenticatable $actor, array $shippingData): Order
    {
        return DB::transaction(function () use ($order, $actor, $shippingData): Order {
            $lockedOrder = $this->lockShopOrder($order, $actor);

            $this->assertAllowed(
                $this->transitionPolicy->canMarkShipped($lockedOrder),
                $lockedOrder,
                OrderStatus::SHIPPED,
            );

            $this->applyShippingData($lockedOrder, $shippingData);
            $this->persistStatus($lockedOrder, OrderStatus::SHIPPED, $actor);
            $this->sourceShipmentService->ensureRetailOrderShipment($this->fresh($lockedOrder));

            return $this->fresh($lockedOrder);
        });
    }

    public function completeDirectly(Order $order, Authenticatable $actor): Order
    {
        return DB::transaction(function () use ($order, $actor): Order {
            $lockedOrder = $this->lockShopOrder($order, $actor);

            $this->assertAllowed(
                $this->transitionPolicy->canCompleteDirectly(
                    $lockedOrder,
                    hasAuthoritativeDirectFulfillment: true,
                ),
                $lockedOrder,
                OrderStatus::COMPLETED,
            );

            if (! $this->hasAuthoritativeDirectFulfillment($lockedOrder)) {
                throw ValidationException::withMessages([
                    'status' => ['Direct completion requires authoritative pickup or direct-fulfillment evidence.'],
                ]);
            }

            $this->persistStatus($lockedOrder, OrderStatus::COMPLETED, $actor);

            return $this->fresh($lockedOrder);
        });
    }

    public function confirmDelivered(Order $order, Authenticatable $actor): Order
    {
        return DB::transaction(function () use ($order, $actor): Order {
            $lockedOrder = $this->lockCustomerOrder($order, $actor);

            $this->assertAllowed(
                $this->transitionPolicy->canConfirmDelivered($lockedOrder),
                $lockedOrder,
                OrderStatus::DELIVERED,
            );

            $paymentMethod = strtolower((string) ($lockedOrder->payment_method ?? ''));
            $isCodOrder = in_array($paymentMethod, ['cod', 'cash_on_delivery', 'cash on delivery'], true);

            if ($isCodOrder && ! in_array((string) ($lockedOrder->payment_status ?? 'pending'), ['paid', 'completed'], true)) {
                $lockedOrder->payment_status = 'paid';
                $lockedOrder->paid_at = now();
                $lockedOrder->payment_failed_at = null;
                $lockedOrder->payment_failure_reason = null;
                $lockedOrder->payment_expired_at = null;
            }

            $this->persistStatus($lockedOrder, OrderStatus::DELIVERED, $actor, notifyCustomer: false);

            try {
                $this->notificationService->sendToShopOwner(
                    shopOwnerId: (int) $lockedOrder->shop_owner_id,
                    type: NotificationType::ORDER_DELIVERED,
                    title: 'Order Delivered Successfully',
                    message: "Order #{$lockedOrder->order_number} has been delivered to customer",
                    data: [
                        'order_id' => $lockedOrder->id,
                        'order_number' => $lockedOrder->order_number,
                        'customer_name' => $lockedOrder->customer_name,
                        'total' => number_format((float) $lockedOrder->total_amount, 2),
                    ],
                    actionUrl: '/shop-owner/job-orders-retail',
                    priority: 'high',
                );
            } catch (\Throwable $exception) {
                Log::error('Failed to send delivery notification to shop owner', [
                    'shop_owner_id' => $lockedOrder->shop_owner_id,
                    'order_id' => $lockedOrder->id,
                    'error' => $exception->getMessage(),
                ]);
            }

            return $this->fresh($lockedOrder);
        });
    }

    public function correctTerminalOutcome(
        Order $order,
        ShopOwner $actor,
        OrderStatus $target,
        string $reason,
    ): Order {
        return DB::transaction(function () use ($order, $actor, $target, $reason): Order {
            $lockedOrder = Order::query()
                ->whereKey($order->getKey())
                ->where('shop_owner_id', $actor->getAuthIdentifier())
                ->lockForUpdate()
                ->firstOrFail();

            $reason = trim($reason);
            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' => ['A reason is required for terminal-outcome correction.'],
                ]);
            }

            if (! in_array($target, [OrderStatus::DELIVERED, OrderStatus::COMPLETED], true)) {
                throw ValidationException::withMessages([
                    'target' => ['Only delivered and completed are valid terminal outcomes.'],
                ]);
            }

            $previousStatus = $this->statusValue($lockedOrder);
            if (! in_array($previousStatus, [OrderStatus::DELIVERED->value, OrderStatus::COMPLETED->value], true)
                || $previousStatus === $target->value) {
                throw ValidationException::withMessages([
                    'target' => ['Terminal correction is only allowed between delivered and completed.'],
                ]);
            }

            $lockedOrder->status = $target;
            $lockedOrder->save();

            AuditLog::create([
                'shop_owner_id' => $actor->getAuthIdentifier(),
                'actor_user_id' => $actor->getAuthIdentifier(),
                'action' => 'order_terminal_outcome_corrected',
                'object_type' => 'order',
                'object_id' => $lockedOrder->getKey(),
                'target_type' => 'order',
                'target_id' => $lockedOrder->getKey(),
                'metadata' => [
                    'previous_status' => $previousStatus,
                    'new_status' => $target->value,
                    'reason' => $reason,
                    'actor_id' => $actor->getAuthIdentifier(),
                    'actor_type' => $actor::class,
                ],
            ]);

            return $this->fresh($lockedOrder);
        });
    }

    private function lockShopOrder(Order $order, Authenticatable $actor): Order
    {
        $shopOwnerId = match (true) {
            $actor instanceof ShopOwner => (int) $actor->getAuthIdentifier(),
            $actor instanceof User => $this->staffShopOwnerId($actor),
            default => 0,
        };

        if ($shopOwnerId < 1) {
            throw new AuthorizationException('Only a shop owner or authorized shop staff may mutate Order fulfillment.');
        }

        return Order::query()
            ->whereKey($order->getKey())
            ->where('shop_owner_id', $shopOwnerId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function staffShopOwnerId(User $actor): int
    {
        if ($actor->shop_owner_id) {
            return (int) $actor->shop_owner_id;
        }

        return in_array(strtoupper((string) $actor->role), ['STAFF', 'MANAGER'], true)
            ? (int) $actor->getAuthIdentifier()
            : 0;
    }

    private function lockCustomerOrder(Order $order, Authenticatable $actor): Order
    {
        if (! $actor instanceof User) {
            throw new AuthorizationException('Only the customer may confirm delivery.');
        }

        return Order::query()
            ->whereKey($order->getKey())
            ->where('customer_id', $actor->getAuthIdentifier())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function persistStatus(
        Order $order,
        OrderStatus $target,
        Authenticatable $actor,
        bool $notifyCustomer = true,
    ): void {
        $oldStatus = $this->statusValue($order);
        $order->status = $target;
        $order->save();

        activity()
            ->causedBy($actor)
            ->performedOn($order)
            ->withProperties([
                'order_number' => $order->order_number,
                'customer_name' => $order->customer_name ?? 'N/A',
                'old_status' => $oldStatus,
                'new_status' => $target->value,
                'total_amount' => $order->total_amount,
                'updated_by_name' => $actor->name ?? $actor->shop_name ?? 'System',
                'updated_by_role' => $actor instanceof ShopOwner ? 'Shop Owner' : ($actor->role ?? 'Staff'),
                'tracking_number' => $order->tracking_number,
                'carrier_company' => $order->carrier_company,
            ])
            ->log("Order status updated from {$oldStatus} to {$target->value}");

        if ($notifyCustomer && $order->customer_id && $oldStatus !== $target->value) {
            $this->notificationService->sendToUser(
                userId: (int) $order->customer_id,
                type: NotificationType::ORDER_STATUS_UPDATE,
                title: 'Order Status Updated',
                message: "Order {$order->order_number} is now {$target->value}.",
                data: [
                    'order_id' => (int) $order->id,
                    'order_number' => (string) $order->order_number,
                    'status' => $target->value,
                ],
                actionUrl: '/my-orders',
                shopId: (int) $order->shop_owner_id,
                priority: 'high',
            );
        }
    }

    private function applyShippingData(Order $order, array $shippingData): void
    {
        foreach (['tracking_number', 'carrier_company', 'carrier_name', 'carrier_phone', 'tracking_link', 'eta'] as $field) {
            if (array_key_exists($field, $shippingData)) {
                $order->{$field} = $shippingData[$field];
            }
        }
    }

    private function hasAuthoritativeDirectFulfillment(Order $order): bool
    {
        return (bool) $order->pickup_enabled;
    }

    private function assertAllowed(bool $allowed, Order $order, OrderStatus $target): void
    {
        if ($allowed) {
            return;
        }

        $current = ucfirst($this->statusValue($order));
        $targetLabel = ucfirst($target->value);
        $message = $order->status?->isFinal()
            ? "The order is already {$current} and cannot be moved back to {$targetLabel}."
            : "Order cannot move from {$this->statusValue($order)} to {$target->value}.";

        throw ValidationException::withMessages(['status' => [$message]]);
    }

    private function statusValue(Order $order): string
    {
        $status = $order->getAttribute('status');

        return $status instanceof OrderStatus ? $status->value : (string) $status;
    }

    private function fresh(Order $order): Order
    {
        return $order->fresh() ?? $order;
    }
}
