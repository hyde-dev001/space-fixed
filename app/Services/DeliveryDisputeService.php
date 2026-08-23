<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\DeliveryDispute;
use App\Models\Logistics\Shipment;
use App\Models\Order;
use App\Models\OrderRefund;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DeliveryDisputeService
{
    public const REASONS = [
        'item_not_received',
        'damaged',
        'incomplete',
        'wrong_item',
        'other',
    ];

    public const RESOLUTIONS = [
        'customer_confirmed',
        'refund_required',
        'replacement_required',
        'return_required',
        'report_rejected',
    ];

    public function __construct(
        private readonly OrderRefundService $orderRefundService,
        private readonly NotificationService $notificationService,
    ) {}

    public function canReport(Order $order): bool
    {
        $status = $this->orderStatus($order);

        return $this->isShopOwnedDelivery($order)
            && in_array($status, ['delivered', 'completed'], true)
            && $order->isCancellationRefundWindowOpen()
            && ! $this->hasBlockingRefund($order)
            && ! $this->hasActiveDispute($order);
    }

    /**
     * @param array<int, array<string, mixed>> $evidenceMedia
     */
    public function report(
        Order $order,
        int $customerId,
        string $reason,
        ?string $notes = null,
        array $evidenceMedia = [],
    ): array
    {
        if (! in_array($reason, self::REASONS, true)) {
            throw ValidationException::withMessages(['reason' => 'Choose a valid delivery issue reason.']);
        }
        if (! $this->isShopOwnedDelivery($order)) {
            throw ValidationException::withMessages(['order' => 'Delivery reports are only available for shop-owned logistics orders.']);
        }
        if (! $this->hasValidEvidenceMedia($evidenceMedia)) {
            throw ValidationException::withMessages(['media' => 'Upload exactly 5 images and 1 opening-parcel video.']);
        }

        return DB::transaction(function () use ($order, $customerId, $reason, $notes, $evidenceMedia): array {
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->where('customer_id', $customerId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->isShopOwnedDelivery($lockedOrder)) {
                throw ValidationException::withMessages(['order' => 'Delivery reports are only available for shop-owned logistics orders.']);
            }

            if (! in_array($this->orderStatus($lockedOrder), ['delivered', 'completed'], true)) {
                throw ValidationException::withMessages(['order' => 'Only delivered orders can be reported.']);
            }

            if (! $lockedOrder->isCancellationRefundWindowOpen()) {
                throw ValidationException::withMessages(['order' => 'The reporting window for this order has ended.']);
            }

            if ($this->hasBlockingRefund($lockedOrder)) {
                throw ValidationException::withMessages(['order' => 'This order already has an active or completed refund workflow.']);
            }

            $active = $this->activeQuery((int) $lockedOrder->id)->lockForUpdate()->first();
            if ($active) {
                return ['result' => 'existing', 'dispute' => $active->fresh()];
            }

            $shipment = $this->latestRetailShipment((int) $lockedOrder->id);
            $leg = $shipment?->legs->last();
            $dispute = DeliveryDispute::query()->create([
                'shop_owner_id' => $lockedOrder->shop_owner_id,
                'order_id' => $lockedOrder->id,
                'shipment_id' => $shipment?->id,
                'shipment_leg_id' => $leg?->id,
                'customer_id' => $customerId,
                'status' => 'open',
                'reason' => $reason,
                'notes' => $notes,
                'evidence_media' => array_values($evidenceMedia),
                'reported_at' => now(),
            ]);

            $lockedOrder->update([
                'customer_receipt_status' => 'disputed',
                'customer_receipt_disputed_at' => now(),
            ]);

            $this->notifyAfterCommit(function () use ($dispute, $lockedOrder): void {
                $this->notificationService->sendToErpRole(
                    'Logistics Dispatcher',
                    (int) $lockedOrder->shop_owner_id,
                    NotificationType::LOGISTICS_EXCEPTION,
                    'Customer Delivery Report',
                    "A customer reported a delivery issue for order #{$lockedOrder->order_number}.",
                    [
                        'dispute_id' => (int) $dispute->id,
                        'order_id' => (int) $lockedOrder->id,
                        'order_number' => $lockedOrder->order_number,
                        'reason' => $dispute->reason,
                    ],
                    '/erp/logistics/shipments?status=customer_disputes',
                    'high',
                );
            });

            return ['result' => 'reported', 'dispute' => $dispute->fresh()];
        });
    }

    public function investigate(DeliveryDispute $dispute, Model $actor): DeliveryDispute
    {
        return DB::transaction(function () use ($dispute, $actor): DeliveryDispute {
            $locked = DeliveryDispute::query()->lockForUpdate()->findOrFail($dispute->id);
            if ($locked->status === 'investigating') {
                return $locked->fresh();
            }
            if ($locked->status !== 'open') {
                throw ValidationException::withMessages(['status' => 'Only open disputes can be investigated.']);
            }

            $locked->update([
                'status' => 'investigating',
                'investigated_at' => now(),
                'investigated_by_type' => $actor::class,
                'investigated_by_id' => $actor->getKey(),
            ]);

            return $locked->fresh();
        });
    }

    public function resolve(
        DeliveryDispute $dispute,
        Model $actor,
        string $resolution,
        ?string $resolutionNote = null,
    ): array {
        if (! in_array($resolution, self::RESOLUTIONS, true)) {
            throw ValidationException::withMessages(['resolution' => 'Choose a valid dispute resolution.']);
        }

        return DB::transaction(function () use ($dispute, $actor, $resolution, $resolutionNote): array {
            $locked = DeliveryDispute::query()->lockForUpdate()->findOrFail($dispute->id);
            if (in_array((string) $locked->status, ['resolved', 'rejected'], true)) {
                return ['result' => 'existing', 'dispute' => $locked->fresh('orderRefund')];
            }
            if ($locked->status !== 'investigating') {
                throw ValidationException::withMessages(['status' => 'Only disputes under investigation can be resolved.']);
            }
            if ($resolution === 'customer_confirmed' && $locked->reason !== 'item_not_received') {
                throw ValidationException::withMessages(['resolution' => 'Customer confirmation is only valid for an item-not-received dispute.']);
            }

            $order = Order::query()->lockForUpdate()->findOrFail($locked->order_id);
            $locked->setRelation('order', $order);

            $refund = null;
            if (in_array($resolution, ['refund_required', 'return_required'], true)) {
                $refund = $this->ensureRefundRequest($locked);
                if (! $refund) {
                    throw ValidationException::withMessages([
                        'resolution' => 'A paid refund workflow could not be created for this order. Keep the dispute open while payment eligibility is reviewed.',
                    ]);
                }
            }

            $locked->update([
                'status' => $resolution === 'report_rejected' ? 'rejected' : 'resolved',
                'resolution' => $resolution,
                'resolution_note' => $resolutionNote,
                'resolved_by_type' => $actor::class,
                'resolved_by_id' => $actor->getKey(),
                'resolved_at' => now(),
                'order_refund_id' => $refund?->id,
            ]);

            if ($resolution === 'customer_confirmed') {
                $order->update([
                    'customer_receipt_status' => 'confirmed',
                    'customer_received_at' => $order->customer_received_at ?? now(),
                ]);
            } elseif ($resolution === 'report_rejected') {
                $order->update([
                    'customer_receipt_status' => $order->customer_received_at ? 'confirmed' : 'pending',
                ]);
            } else {
                $order->update(['customer_receipt_status' => 'disputed']);
            }

            if ((int) ($locked->customer_id ?? 0) > 0) {
                $this->notifyAfterCommit(function () use ($locked, $order, $resolution, $refund): void {
                    $this->notificationService->sendToUser(
                        (int) $locked->customer_id,
                        NotificationType::ORDER_STATUS_UPDATE,
                        'Delivery Report Update',
                        $this->customerResolutionMessage($resolution),
                        [
                            'dispute_id' => (int) $locked->id,
                            'order_id' => (int) $order->id,
                            'order_number' => $order->order_number,
                            'resolution' => $resolution,
                            'refund_id' => $refund?->id,
                        ],
                        '/my-orders?tab=return_refund',
                        (int) $order->shop_owner_id,
                    );
                });
            }

            return [
                'result' => 'resolved',
                'dispute' => $locked->fresh(['order', 'orderRefund']),
                'refund' => $refund,
            ];
        });
    }

    private function ensureRefundRequest(DeliveryDispute $dispute): ?OrderRefund
    {
        $existing = OrderRefund::query()
            ->where('order_id', $dispute->order_id)
            ->whereIn('status', ['requested', 'pending_approval', 'processing', 'succeeded'])
            ->latest('id')
            ->first();
        if ($existing) {
            return $existing;
        }

        $order = $dispute->order->loadMissing(['items', 'shopOwner']);
        $paymentMethod = strtolower((string) ($order->payment_method ?? ''));
        $isOnlinePayment = ! in_array($paymentMethod, ['cod', 'cash_on_delivery', 'cash on delivery'], true);
        if (! $isOnlinePayment || ! in_array((string) ($order->payment_status ?? 'pending'), ['paid', 'completed'], true)) {
            return null;
        }

        $amount = max(
            (float) ($order->grand_total ?? 0),
            (float) ($order->total_amount ?? 0) + (float) ($order->shipping_fee ?? 0) + (float) ($order->vat_amount ?? 0),
            (float) ($order->total_amount ?? 0) + (float) ($order->shipping_fee ?? 0),
            (float) ($order->total ?? 0),
            (float) ($order->total_amount ?? 0),
        );
        if ($amount <= 0) {
            return null;
        }

        $lines = $order->items->map(fn ($item) => [
            'order_item_id' => (int) $item->id,
            'product_id' => (int) $item->product_id,
            'product_variant_id' => $item->product_variant_id ? (int) $item->product_variant_id : null,
            'requested_qty' => (int) $item->quantity,
            'approved_qty' => (int) $item->quantity,
            'unit_price_snapshot' => round((float) $item->price, 2),
            'line_amount' => 0,
            'inspection_disposition' => 'pending',
            'inventory_action' => 'pending',
        ])->all();

        $reservation = $this->orderRefundService->reserveOrderRefund($order, [
            'customer_id' => $order->customer_id,
            'shop_owner_id' => $order->shop_owner_id,
            'flow_type' => 'request_approval',
            'status' => 'requested',
            'shop_owner_status' => 'pending',
            'finance_status' => 'pending',
            'return_status' => 'awaiting_approval',
            'payment_gateway' => 'paymongo',
            'paymongo_payment_id' => $order->paymongo_payment_id,
            'currency' => 'PHP',
            'requested_refund_method' => 'original_payment_method',
            'reason_code' => 'delivery_dispute',
            'reason_note' => 'Refund request created from customer delivery dispute.',
            'idempotency_key' => "delivery-dispute-refund:{$dispute->id}",
            'requested_at' => now(),
        ], $lines, $amount);

        $refund = $reservation['refund'] ?? null;
        if ($refund && ($reservation['result'] ?? null) === 'reserved') {
            $refundId = (int) $refund->id;
            $this->notifyAfterCommit(function () use ($refundId): void {
                $refund = OrderRefund::query()->find($refundId);
                if ($refund) {
                    $this->orderRefundService->notifyRefundApprovalRequested($refund);
                }
            });
        }

        return $refund;
    }

    private function customerResolutionMessage(string $resolution): string
    {
        return match ($resolution) {
            'customer_confirmed' => 'The dispatcher reviewed your report and recorded the order as received.',
            'refund_required', 'return_required' => 'Your report requires a refund or return. The refund request is now awaiting the existing approval and return instructions.',
            'replacement_required' => 'Your report was resolved as replacement required. The shop will coordinate the next replacement step.',
            'report_rejected' => 'The dispatcher reviewed your report and rejected it based on the available delivery records.',
            default => 'Your delivery report has been updated.',
        };
    }

    private function notifyAfterCommit(callable $callback): void
    {
        DB::afterCommit(function () use ($callback): void {
            try {
                $callback();
            } catch (\Throwable $exception) {
                Log::warning('Delivery dispute notification failed after commit.', [
                    'error' => $exception->getMessage(),
                ]);
            }
        });
    }

    private function activeQuery(int $orderId)
    {
        return DeliveryDispute::query()
            ->where('order_id', $orderId)
            ->whereIn('status', ['open', 'investigating']);
    }

    private function hasActiveDispute(Order $order): bool
    {
        if ($order->relationLoaded('deliveryDisputes')) {
            return $order->deliveryDisputes->contains(fn (DeliveryDispute $dispute): bool => $dispute->isActive());
        }

        return $this->activeQuery((int) $order->id)->exists();
    }

    private function hasBlockingRefund(Order $order): bool
    {
        if (strtolower((string) ($order->payment_status ?? 'pending')) === 'refunded') {
            return true;
        }

        return OrderRefund::query()
            ->where('order_id', $order->id)
            ->whereIn('status', ['requested', 'pending_approval', 'processing', 'succeeded'])
            ->exists();
    }

    private function latestRetailShipment(int $orderId): ?Shipment
    {
        return Shipment::query()
            ->where('source_type', 'order')
            ->where('purpose', 'retail_delivery')
            ->where('source_id', $orderId)
            ->latest('id')
            ->with(['legs' => fn ($query) => $query->orderBy('sequence')->orderBy('id')])
            ->first();
    }

    private function orderStatus(Order $order): string
    {
        return $order->status instanceof \App\Enums\OrderStatus
            ? $order->status->value
            : (string) $order->status;
    }

    private function isShopOwnedDelivery(Order $order): bool
    {
        return strtolower(trim((string) $order->carrier_company)) === 'shop-owned logistics';
    }

    /**
     * @param array<int, array<string, mixed>> $evidenceMedia
     */
    private function hasValidEvidenceMedia(array $evidenceMedia): bool
    {
        if (count($evidenceMedia) !== 6) {
            return false;
        }

        $imageCount = 0;
        $videoCount = 0;

        foreach ($evidenceMedia as $media) {
            if (! is_array($media)
                || ! isset($media['id'], $media['path'], $media['kind'], $media['mime_type'], $media['original_name'], $media['size'])
                || ! is_string($media['id'])
                || ! is_string($media['path'])
                || ! str_starts_with($media['path'], 'delivery-dispute-evidence/')
                || str_contains($media['path'], '..')
                || str_contains($media['path'], '\\')) {
                return false;
            }

            if ($media['kind'] === 'video') {
                $videoCount++;
            } elseif ($media['kind'] === 'image') {
                $imageCount++;
            } else {
                return false;
            }
        }

        return $imageCount === 5 && $videoCount === 1;
    }
}
