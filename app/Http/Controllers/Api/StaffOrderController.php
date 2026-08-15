<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryDispute;
use App\Models\Logistics\Shipment;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\User;
use App\Services\Logistics\DeliveryScheduleService;
use App\Services\OrderRefundService;
use App\Services\Orders\OrderFulfillmentService;
use App\Services\RetailPosRefundSummaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class StaffOrderController extends Controller
{
    private function canAccessStaffOrders($user): bool
    {
        if (! $user) {
            return false;
        }

        // Primary gate: explicit permission used by route middleware.
        if (method_exists($user, 'can') && $user->can('access-staff-job-orders')) {
            return true;
        }

        // Fallback for legacy role-column based records.
        return in_array(strtoupper((string) ($user->role ?? '')), ['STAFF', 'MANAGER'], true);
    }

    public function __construct(
        private readonly OrderRefundService $orderRefundService,
        private readonly OrderFulfillmentService $orderFulfillmentService,
        private readonly RetailPosRefundSummaryService $retailPosRefundSummaryService,
        private readonly DeliveryScheduleService $deliveryScheduleService,
    ) {}

    public function index(Request $request)
    {
        $user = Auth::guard('user')->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Check if user can access staff job orders.
        if (! $this->canAccessStaffOrders($user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Get the shop owner ID for this STAFF user
        $shopOwnerId = $user->shop_owner_id ?? $user->id;

        $includeRefundItems = Schema::hasTable('order_refund_items');

        // Fetch orders ONLY for this shop with their items and related data
        $orders = Order::with([
            'items.product',
            'customer',
            'address',
            'shopOwner.logisticsSetting',
            'deliveryDisputes' => fn ($disputeQuery) => $disputeQuery->latest('id'),
            'refunds' => function ($refundQuery) use ($includeRefundItems) {
                if ($includeRefundItems) {
                    $refundQuery->with('items.orderItem');
                }

                $refundQuery->orderByDesc('id');
            },
        ])
            ->where('shop_owner_id', $shopOwnerId)
            ->orderBy('created_at', 'desc')
            ->get();

        $retailPosRefundSummaries = $this->retailPosRefundSummaryService->buildForOrders(
            (int) $shopOwnerId,
            $orders->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );
        $orderShipments = $this->latestShipmentLookup(
            (int) $shopOwnerId,
            'order',
            $orders->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'retail_delivery',
        );
        $refundShipments = $this->latestShipmentLookup(
            (int) $shopOwnerId,
            'order_refund',
            $orders->map(fn ($order) => $order->refunds->first()?->id)
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all(),
            'refund_return',
        );
        $deliveryCancellations = Shipment::query()
            ->with(['events' => fn ($events) => $events
                ->where('event_type', 'delivery_cancelled')
                ->where('visibility', 'customer')
                ->latest('id')])
            ->where('shop_owner_id', $shopOwnerId)
            ->where('source_type', 'order')
            ->whereIn('source_id', $orders->pluck('id'))
            ->where('status', 'cancelled')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('source_id')
            ->map(fn ($shipments) => $shipments->first());

        $orders = $orders->map(function ($order) use ($retailPosRefundSummaries, $includeRefundItems, $deliveryCancellations, $orderShipments, $refundShipments) {
            $itemSubtotal = (float) ($order->total_amount ?? 0);
            $shippingFee = (float) ($order->shipping_fee ?? 0);
            $hasStoredVat = $order->vat_amount !== null;
            $vatAmount = $hasStoredVat ? round((float) $order->vat_amount, 2) : null;
            $vatRate = $hasStoredVat && ((float) ($order->vat_rate ?? 0)) > 0
                ? round((float) $order->vat_rate, 2)
                : null;
            $latestRefund = $order->refunds->first();
            $refundDispute = $latestRefund
                ? $order->deliveryDisputes->first(fn ($dispute) => (int) ($dispute->order_refund_id ?? 0) === (int) $latestRefund->id)
                : null;
            $cancelledShipment = $deliveryCancellations->get($order->id);
            $activeDispute = $order->deliveryDisputes
                ->first(fn ($dispute) => in_array((string) $dispute->status, ['open', 'investigating'], true));

            $latestRefundItems = [];
            if ($includeRefundItems && $latestRefund) {
                $latestRefundItems = $latestRefund->items
                    ->map(function ($line) {
                        return [
                            'order_item_id' => (int) ($line->order_item_id ?? 0),
                            'product_name' => (string) ($line->orderItem->product_name ?? 'Item'),
                            'requested_qty' => (int) ($line->requested_qty ?? 0),
                            'approved_qty' => (int) ($line->approved_qty ?? $line->requested_qty ?? 0),
                            'inspection_disposition' => (string) ($line->inspection_disposition ?? 'pending'),
                            'line_amount' => (float) ($line->line_amount ?? 0),
                        ];
                    })
                    ->filter(fn (array $line) => (int) ($line['order_item_id'] ?? 0) > 0)
                    ->values()
                    ->all();
            }

            return [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'customer_name' => $order->customer_name ?? $order->customer?->name ?? 'Guest',
                'customer_email' => $order->customer_email ?? $order->customer?->email ?? '',
                'customer_phone' => $order->customer_phone ?? '',
                'shipping_address' => (string) ($order->full_shipping_address ?? ''),
                'shipping_address_line' => $order->shipping_address_line,
                'shipping_barangay' => $order->shipping_barangay,
                'shipping_city' => $order->shipping_city,
                'shipping_province' => $order->shipping_province,
                'shipping_region' => $order->shipping_region,
                'shipping_postal_code' => $order->shipping_postal_code,
                'total_amount' => $itemSubtotal,
                'shipping_fee' => $shippingFee,
                'vat_amount' => $vatAmount,
                'vat_rate' => $vatRate,
                'grand_total' => $itemSubtotal + $shippingFee + ($vatAmount ?? 0.0),
                'status' => $order->status,
                'customer_receipt_status' => (string) ($order->customer_receipt_status ?? 'pending'),
                'customer_received_at' => optional($order->customer_received_at)->toISOString(),
                'customer_receipt_disputed_at' => optional($order->customer_receipt_disputed_at)->toISOString(),
                'active_delivery_dispute' => $activeDispute ? [
                    'id' => (int) $activeDispute->id,
                    'status' => (string) $activeDispute->status,
                    'reason' => (string) $activeDispute->reason,
                    'notes' => $activeDispute->notes,
                    'reported_at' => optional($activeDispute->reported_at)->toISOString(),
                ] : null,
                'cancellation_reason' => $order->cancellation_reason,
                'cancellation_note' => $order->cancellation_note,
                'cancellation_other_reason_note' => $order->cancellation_other_reason_note,
                'payment_status' => $order->payment_status ?? 'pending',
                'payment_method' => $order->payment_method ?? '',
                'tracking_number' => $order->tracking_number ?? '',
                'carrier_company' => $order->carrier_company ?? '',
                'carrier_name' => $order->carrier_name ?? '',
                'carrier_phone' => $order->carrier_phone ?? '',
                'tracking_link' => $order->tracking_link ?? '',
                'eta' => $order->eta ?? null,
                'shop_owned_coverage' => $this->shopOwnedCoverage($order),
                'delivery_cancellation' => $cancelledShipment ? [
                    'status' => 'cancelled',
                    'message' => $cancelledShipment->events->first()?->message,
                ] : null,
                'retail_pos_refund' => $retailPosRefundSummaries[(int) $order->id] ?? null,
                'logistics' => $this->serializeShipmentSummary(
                    $orderShipments->get($order->id),
                    ['outbound'],
                    $this->orderLogisticsFallback($order),
                ),
                'latest_refund' => $latestRefund
                    ? $this->serializeLatestRefund(
                        $latestRefund,
                        $latestRefundItems,
                        $refundShipments->get($latestRefund->id),
                        $refundDispute,
                    )
                    : null,
                'created_at' => $order->created_at->toISOString(),
                'updated_at' => $order->updated_at->toISOString(),
                'items' => $order->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'product_name' => $item->product_name,
                        'product_slug' => $item->product_slug,
                        'product_image' => $item->product_image,
                        'price' => $item->price,
                        'quantity' => $item->quantity,
                        'subtotal' => $item->subtotal,
                        'size' => $item->size,
                        'color' => $item->color,
                    ];
                }),
                'shop' => $order->shopOwner ? [
                    'id' => $order->shopOwner->id,
                    'shop_name' => $order->shopOwner->shop_name,
                ] : null,
            ];
        });

        return response()->json($orders);
    }

    public function show($id)
    {
        $user = Auth::guard('user')->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Check if user can access staff job orders.
        if (! $this->canAccessStaffOrders($user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Get the shop owner ID for this STAFF user
        $shopOwnerId = $user->shop_owner_id ?? $user->id;

        $includeRefundItems = Schema::hasTable('order_refund_items');

        // Fetch order ONLY if it belongs to this shop
        $order = Order::with([
            'items.product',
            'customer',
            'address',
            'shopOwner.logisticsSetting',
            'deliveryDisputes' => fn ($disputeQuery) => $disputeQuery->latest('id'),
            'refunds' => function ($refundQuery) use ($includeRefundItems) {
                if ($includeRefundItems) {
                    $refundQuery->with('items.orderItem');
                }

                $refundQuery->orderByDesc('id');
            },
        ])
            ->where('shop_owner_id', $shopOwnerId)
            ->where('id', $id)
            ->first();

        if (! $order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        $itemSubtotal = (float) ($order->total_amount ?? 0);
        $shippingFee = (float) ($order->shipping_fee ?? 0);
        $hasStoredVat = $order->vat_amount !== null;
        $vatAmount = $hasStoredVat ? round((float) $order->vat_amount, 2) : null;
        $vatRate = $hasStoredVat && ((float) ($order->vat_rate ?? 0)) > 0
            ? round((float) $order->vat_rate, 2)
            : null;
        $latestRefund = $order->refunds->first();
        $refundDispute = $latestRefund
            ? $order->deliveryDisputes->first(fn ($dispute) => (int) ($dispute->order_refund_id ?? 0) === (int) $latestRefund->id)
            : null;
        $activeDispute = $order->deliveryDisputes
            ->first(fn ($dispute) => in_array((string) $dispute->status, ['open', 'investigating'], true));
        $latestRefundItems = [];
        if ($includeRefundItems && $latestRefund) {
            $latestRefundItems = $latestRefund->items
                ->map(function ($line) {
                    return [
                        'order_item_id' => (int) ($line->order_item_id ?? 0),
                        'product_name' => (string) ($line->orderItem->product_name ?? 'Item'),
                        'requested_qty' => (int) ($line->requested_qty ?? 0),
                        'approved_qty' => (int) ($line->approved_qty ?? $line->requested_qty ?? 0),
                        'inspection_disposition' => (string) ($line->inspection_disposition ?? 'pending'),
                        'line_amount' => (float) ($line->line_amount ?? 0),
                    ];
                })
                ->filter(fn (array $line) => (int) ($line['order_item_id'] ?? 0) > 0)
                ->values()
                ->all();
        }

        $retailPosRefundSummary = $this->retailPosRefundSummaryService->buildForOrders((int) $shopOwnerId, [(int) $order->id]);
        $orderShipment = $this->latestShipmentLookup(
            (int) $shopOwnerId,
            'order',
            [(int) $order->id],
            'retail_delivery',
        )->get($order->id);
        $refundShipment = $latestRefund
            ? $this->latestShipmentLookup(
                (int) $shopOwnerId,
                'order_refund',
                [(int) $latestRefund->id],
                'refund_return',
            )->get($latestRefund->id)
            : null;

        return response()->json([
            'id' => $order->id,
            'order_number' => $order->order_number,
            'customer_name' => $order->customer_name ?? $order->customer?->name ?? 'Guest',
            'customer_email' => $order->customer_email ?? $order->customer?->email ?? '',
            'customer_phone' => $order->customer_phone ?? '',
            'shipping_address' => (string) ($order->full_shipping_address ?? ''),
            'shipping_address_line' => $order->shipping_address_line,
            'shipping_barangay' => $order->shipping_barangay,
            'shipping_city' => $order->shipping_city,
            'shipping_province' => $order->shipping_province,
            'shipping_region' => $order->shipping_region,
            'shipping_postal_code' => $order->shipping_postal_code,
            'total_amount' => $itemSubtotal,
            'shipping_fee' => $shippingFee,
            'vat_amount' => $vatAmount,
            'vat_rate' => $vatRate,
            'grand_total' => $itemSubtotal + $shippingFee + ($vatAmount ?? 0.0),
            'status' => $order->status,
            'customer_receipt_status' => (string) ($order->customer_receipt_status ?? 'pending'),
            'customer_received_at' => optional($order->customer_received_at)->toISOString(),
            'customer_receipt_disputed_at' => optional($order->customer_receipt_disputed_at)->toISOString(),
            'active_delivery_dispute' => $activeDispute ? [
                'id' => (int) $activeDispute->id,
                'status' => (string) $activeDispute->status,
                'reason' => (string) $activeDispute->reason,
                'notes' => $activeDispute->notes,
                'reported_at' => optional($activeDispute->reported_at)->toISOString(),
            ] : null,
            'cancellation_reason' => $order->cancellation_reason,
            'cancellation_note' => $order->cancellation_note,
            'cancellation_other_reason_note' => $order->cancellation_other_reason_note,
            'payment_status' => $order->payment_status ?? 'pending',
            'payment_method' => $order->payment_method ?? '',
            'tracking_number' => $order->tracking_number ?? '',
            'carrier_company' => $order->carrier_company ?? '',
            'carrier_name' => $order->carrier_name ?? '',
            'carrier_phone' => $order->carrier_phone ?? '',
            'tracking_link' => $order->tracking_link ?? '',
            'eta' => $order->eta ?? null,
            'shop_owned_coverage' => $this->shopOwnedCoverage($order),
            'retail_pos_refund' => $retailPosRefundSummary[(int) $order->id] ?? null,
            'logistics' => $this->serializeShipmentSummary(
                $orderShipment,
                ['outbound'],
                $this->orderLogisticsFallback($order),
            ),
            'latest_refund' => $latestRefund
                ? $this->serializeLatestRefund($latestRefund, $latestRefundItems, $refundShipment, $refundDispute)
                : null,
            'created_at' => $order->created_at->toISOString(),
            'updated_at' => $order->updated_at->toISOString(),
            'items' => $order->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'product_slug' => $item->product_slug,
                    'product_image' => $item->product_image,
                    'price' => $item->price,
                    'quantity' => $item->quantity,
                    'subtotal' => $item->subtotal,
                    'size' => $item->size,
                    'color' => $item->color,
                ];
            }),
            'shop' => $order->shopOwner ? [
                'id' => $order->shopOwner->id,
                'shop_name' => $order->shopOwner->shop_name,
            ] : null,
        ]);
    }

    private function serializeLatestRefund(
        OrderRefund $refund,
        array $items,
        ?Shipment $shipment,
        ?DeliveryDispute $dispute = null,
    ): array
    {
        return [
            'id' => (int) $refund->id,
            'status' => (string) $refund->status,
            'reason_code' => $refund->reason_code,
            'reason_note' => $refund->reason_note,
            'other_reason_note' => $refund->other_reason_note,
            'shop_owner_status' => (string) ($refund->shop_owner_status ?? 'pending'),
            'finance_status' => (string) ($refund->finance_status ?? 'pending'),
            'return_status' => (string) ($refund->return_status ?? 'awaiting_approval'),
            'return_source' => (string) ($refund->return_source ?? 'customer'),
            'customer_return_tracking_number' => $refund->customer_return_tracking_number,
            'customer_return_carrier' => $refund->customer_return_carrier,
            'customer_return_rider_name' => $refund->customer_return_rider_name,
            'customer_return_rider_phone' => $refund->customer_return_rider_phone,
            'customer_return_tracking_link' => $refund->customer_return_tracking_link,
            'customer_return_shipped_at' => optional($refund->customer_return_shipped_at)->toDateTimeString(),
            'staff_return_tracking_number' => $refund->staff_return_tracking_number,
            'staff_return_carrier' => $refund->staff_return_carrier,
            'staff_return_rider_name' => $refund->staff_return_rider_name,
            'staff_return_rider_phone' => $refund->staff_return_rider_phone,
            'staff_return_tracking_link' => $refund->staff_return_tracking_link,
            'staff_return_shipped_at' => optional($refund->staff_return_shipped_at)->toDateTimeString(),
            'return_arranged_by_staff_at' => optional($refund->return_arranged_by_staff_at)->toDateTimeString(),
            'return_confirmed_at' => optional($refund->return_confirmed_at)->toDateTimeString(),
            'refund_executed_at' => optional($refund->refund_executed_at)->toDateTimeString(),
            'rejected_at' => optional($refund->rejected_at)->toDateTimeString(),
            'rejection_reason' => $refund->rejection_reason,
            'flow_type' => (string) ($refund->flow_type ?? ''),
            'payout_amount_value' => $this->orderRefundService->resolvePayoutAmount($refund),
            'evidence_media' => is_array($refund->evidence_media) ? $refund->evidence_media : [],
            'customer_dispute_evidence' => $this->serializeCustomerDisputeEvidence($dispute),
            'items' => $items,
            'return_logistics' => $this->serializeShipmentSummary(
                $shipment,
                ['inbound', 'return_to_shop'],
                $this->refundLogisticsFallback($refund),
            ),
        ];
    }

    private function serializeCustomerDisputeEvidence(?DeliveryDispute $dispute): array
    {
        if (! $dispute) {
            return [];
        }

        return collect($dispute->evidence_media ?? [])
            ->filter(fn ($media) => is_array($media)
                && is_string($media['id'] ?? null)
                && is_string($media['path'] ?? null)
                && str_starts_with($media['path'], 'delivery-dispute-evidence/')
                && ! str_contains($media['path'], '..')
                && ! str_contains($media['path'], '\\')
                && Storage::disk('local')->exists($media['path']))
            ->map(fn (array $media) => [
                'id' => $media['id'],
                'kind' => ($media['kind'] ?? 'image') === 'video' ? 'video' : 'image',
                'mime_type' => $media['mime_type'] ?? null,
                'original_name' => $media['original_name'] ?? null,
                'url' => route('api.logistics.delivery-disputes.evidence', [
                    'dispute' => $dispute->id,
                    'mediaId' => $media['id'],
                ]),
            ])
            ->values()
            ->all();
    }

    private function latestShipmentLookup(
        int $shopOwnerId,
        string $sourceType,
        array $sourceIds,
        string $purpose,
    ): Collection {
        if ($sourceIds === []) {
            return collect();
        }

        return Shipment::query()
            ->with([
                'legs.shippingMethod',
                'legs.assignments.riderProfile',
                'legs.proofs',
            ])
            ->where('shop_owner_id', $shopOwnerId)
            ->where('source_type', $sourceType)
            ->whereIn('source_id', $sourceIds)
            ->where('purpose', $purpose)
            ->orderByDesc('id')
            ->get()
            ->groupBy('source_id')
            ->map(fn ($shipments) => $shipments->first());
    }

    private function serializeShipmentSummary(
        ?Shipment $shipment,
        array $legTypes,
        array $fallback = [],
    ): ?array {
        if (! $shipment) {
            return null;
        }

        $leg = $shipment->legs
            ->whereIn('leg_type', $legTypes)
            ->sortByDesc('sequence')
            ->first();
        $assignment = $leg?->assignments
            ->whereIn('status', ['assigned', 'accepted', 'completed'])
            ->sortByDesc('id')
            ->first();
        $proofs = $leg && $leg->status->value === 'delivered'
            ? $leg->proofs
                ->filter(fn ($proof) => $proof->review_status === 'approved'
                    && trim((string) ($proof->file_path ?? '')) !== '')
                ->map(fn ($proof) => [
                    'id' => (int) $proof->id,
                    'handoff_type' => $proof->handoff_type,
                    'proof_type' => $proof->proof_type,
                    'file_url' => "/api/logistics/proofs/{$proof->id}/file",
                ])
                ->values()
                ->all()
            : [];

        return [
            'shipment_id' => (int) $shipment->id,
            'shipment_status' => $shipment->status->value,
            'leg_id' => $leg ? (int) $leg->id : null,
            'leg_type' => $leg?->leg_type,
            'leg_status' => $leg?->status->value,
            'carrier' => $leg?->shippingMethod?->name ?? $fallback['carrier'] ?? null,
            'rider_name' => $assignment?->riderProfile?->name ?? $fallback['rider_name'] ?? null,
            'rider_phone' => $assignment?->riderProfile?->phone ?? $fallback['rider_phone'] ?? null,
            'tracking_number' => $leg?->tracking_number ?? $fallback['tracking_number'] ?? null,
            'tracking_url' => $leg?->tracking_url ?? $fallback['tracking_url'] ?? null,
            'proofs' => $proofs,
        ];
    }

    private function orderLogisticsFallback(Order $order): array
    {
        return [
            'carrier' => $order->carrier_company,
            'rider_name' => $order->carrier_name,
            'rider_phone' => $order->carrier_phone,
            'tracking_number' => $order->tracking_number,
            'tracking_url' => $order->tracking_link,
        ];
    }

    private function refundLogisticsFallback(OrderRefund $refund): array
    {
        $prefix = (string) ($refund->return_source ?? 'customer') === 'staff' ? 'staff_return_' : 'customer_return_';

        return [
            'carrier' => $refund->{$prefix.'carrier'},
            'rider_name' => $refund->{$prefix.'rider_name'},
            'rider_phone' => $refund->{$prefix.'rider_phone'},
            'tracking_number' => $refund->{$prefix.'tracking_number'},
            'tracking_url' => $refund->{$prefix.'tracking_link'},
        ];
    }

    public function updateStatus(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'status' => 'required|string',
                'tracking_number' => 'nullable|string|max:255',
                'carrier_company' => 'nullable|string|max:255',
                'carrier_name' => 'nullable|string|max:255',
                'carrier_phone' => 'nullable|string|max:50',
                'tracking_link' => 'nullable|url|max:500',
                'eta' => 'nullable|string|max:255',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation failed for order status update', [
                'errors' => $e->errors(),
                'input' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        $user = Auth::guard('user')->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Check if user can access staff job orders.
        if (! $this->canAccessStaffOrders($user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Get the shop owner ID for this STAFF user
        $shopOwnerId = $user->shop_owner_id ?? $user->id;

        // Fetch order ONLY if it belongs to this shop
        $order = Order::where('shop_owner_id', $shopOwnerId)
            ->where('id', $id)
            ->first();

        if (! $order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        $carrierCompany = $validated['carrier_company'] ?? $order->carrier_company;
        $isShopOwned = strtolower(trim((string) $carrierCompany)) === 'shop-owned logistics';
        if ($isShopOwned) {
            $validated['carrier_company'] = 'Shop-owned logistics';
        }

        if ($validated['status'] === 'shipped' && $isShopOwned) {
            $order->unsetRelation('address');
            $order->unsetRelation('shopOwner');
            $coverage = $this->shopOwnedCoverage($order);

            if (! $coverage['available']) {
                $message = 'Shop-owned logistics is unavailable for this delivery address.';

                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'errors' => ['carrier_company' => [$message]],
                    'shop_owned_coverage' => $coverage,
                ], 422);
            }
        }

        try {
            $updatedOrder = match ($validated['status']) {
                'processing' => $this->orderFulfillmentService->markProcessing($order, $user),
                'shipped' => $this->orderFulfillmentService->markShipped(
                    $order,
                    $user,
                    array_intersect_key($validated, array_flip([
                        'tracking_number',
                        'carrier_company',
                        'carrier_name',
                        'carrier_phone',
                        'tracking_link',
                        'eta',
                    ])),
                ),
                'completed' => $this->orderFulfillmentService->completeDirectly($order, $user),
                default => throw ValidationException::withMessages([
                    'status' => ['Use a named processing, shipping, or direct-completion action for Order fulfillment.'],
                ]),
            };
        } catch (ValidationException $exception) {
            return $this->transitionErrorResponse($exception);
        }

        return response()->json([
            'success' => true,
            'message' => 'Order status updated successfully',
            'order' => [
                'id' => $updatedOrder->id,
                'order_number' => $updatedOrder->order_number,
                'status' => $updatedOrder->status,
                'tracking_number' => $updatedOrder->tracking_number,
                'updated_at' => $updatedOrder->updated_at->toISOString(),
            ],
        ]);
    }

    private function transitionErrorResponse(ValidationException $exception)
    {
        $errors = $exception->errors();
        $message = collect($errors)->flatten()->first() ?? 'Order transition is not allowed.';
        $status = str_starts_with($message, 'The order is already') ? 409 : 422;

        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }

    private function shopOwnedCoverage(Order $order): array
    {
        try {
            $order->loadMissing(['address', 'shopOwner.logisticsSetting']);
            $address = $order->address;

            return $this->deliveryScheduleService->coverage(
                $order->shopOwner,
                $address?->latitude !== null ? (float) $address->latitude : null,
                $address?->longitude !== null ? (float) $address->longitude : null,
            );
        } catch (\Throwable $exception) {
            Log::warning('Staff order logistics coverage failed.', [
                'order_id' => $order->id,
                'shop_owner_id' => $order->shop_owner_id,
                'exception' => $exception->getMessage(),
            ]);

            return [
                'available' => false,
                'reason' => 'logistics_unavailable',
                'distance_km' => null,
                'coverage_radius_km' => null,
            ];
        }
    }

    public function confirmReturnReceived(Request $request, $id)
    {
        $validated = $request->validate([
            'return_notes' => 'nullable|string|max:1000',
            'line_dispositions' => 'required|array|min:1',
            'line_dispositions.*.order_item_id' => 'required|integer|min:1',
            'line_dispositions.*.approved_qty' => 'required|integer|min:1',
            'line_dispositions.*.inspection_disposition' => 'required|string|in:resellable,damaged',
        ]);

        $user = Auth::guard('user')->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        if (! $this->canAccessStaffOrders($user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $shopOwnerId = $user->shop_owner_id ?? $user->id;

        $order = Order::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('id', $id)
            ->first();

        if (! $order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        $refund = OrderRefund::query()
            ->where('order_id', $order->id)
            ->where('shop_owner_id', $shopOwnerId)
            ->where('flow_type', 'request_approval')
            ->whereIn('status', ['requested', 'pending_approval', 'processing', 'succeeded'])
            ->latest('id')
            ->first();

        if (! $refund) {
            return response()->json([
                'success' => false,
                'message' => 'No active refund request found for this order.',
            ], 404);
        }

        $result = $this->orderRefundService->confirmReturnReceived(
            refund: $refund,
            staffId: (int) $user->id,
            notes: $validated['return_notes'] ?? null,
            lineDispositions: $validated['line_dispositions'] ?? null,
        );

        if (($result['result'] ?? null) === 'invalid_state') {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Return cannot be confirmed in current state.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'] ?? 'Return has been confirmed.',
            'refund' => $result['refund'],
            'refund_ready_for_finance_release' => ((string) (($result['refund']->finance_status ?? 'pending')) === 'approved')
                && ((string) (($result['refund']->return_status ?? 'pending_customer_shipment')) === 'received'),
        ]);
    }

    public function arrangeReturnPickup(Request $request, $id)
    {
        $isShopOwned = $request->input('delivery_method') === 'shop_owned';
        $validated = $request->validate([
            'delivery_method' => 'nullable|in:shop_owned,third_party',
            'tracking_number' => ($isShopOwned ? 'nullable' : 'required').'|string|max:255',
            'carrier_company' => ($isShopOwned ? 'nullable' : 'required').'|string|max:255',
            'rider_name' => ($isShopOwned ? 'nullable' : 'required').'|string|max:255',
            'rider_phone' => ($isShopOwned ? 'nullable' : 'required').'|string|max:30',
            'tracking_link' => ($isShopOwned ? 'nullable' : 'required').'|url|max:500',
            'note' => 'nullable|string|max:1000',
            'shipped_at' => 'nullable|date',
        ]);

        if ($isShopOwned) {
            $validated['carrier_company'] = 'Shop-owned logistics';
        }

        $user = Auth::guard('user')->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        if (! $this->canAccessStaffOrders($user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $shopOwnerId = $user->shop_owner_id ?? $user->id;

        $order = Order::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('id', $id)
            ->first();

        if (! $order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        $refund = OrderRefund::query()
            ->where('order_id', $order->id)
            ->where('shop_owner_id', $shopOwnerId)
            ->where('flow_type', 'request_approval')
            ->whereIn('status', ['requested', 'pending_approval', 'processing', 'succeeded'])
            ->latest('id')
            ->first();

        if (! $refund) {
            return response()->json([
                'success' => false,
                'message' => 'No active refund request found for this order.',
            ], 404);
        }

        $result = $this->orderRefundService->arrangeStaffReturnPickup(
            refund: $refund,
            pickupData: $validated,
            staffId: (int) $user->id,
        );

        if (($result['result'] ?? null) === 'invalid_state') {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Return pickup cannot be arranged right now.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'] ?? 'Return pickup arranged successfully.',
            'refund' => $result['refund'],
        ]);
    }

    public function approveRefund(Request $request, $id)
    {
        return $this->reviewRefund($request, $id, true);
    }

    public function rejectRefund(Request $request, $id)
    {
        return $this->reviewRefund($request, $id, false);
    }

    private function reviewRefund(Request $request, $id, bool $approve)
    {
        $user = Auth::guard('user')->user();
        abort_unless($this->canAccessStaffOrders($user), 403);

        $validated = $request->validate($approve
            ? ['approval_note' => 'nullable|string|max:1000']
            : ['rejection_reason' => 'required|string|max:1000']);
        $shopOwnerId = (int) ($user->shop_owner_id ?? $user->id);
        $order = Order::query()->where('shop_owner_id', $shopOwnerId)->findOrFail($id);
        $refund = OrderRefund::query()
            ->where('order_id', $order->id)
            ->where('shop_owner_id', $shopOwnerId)
            ->where('flow_type', 'request_approval')
            ->whereIn('status', ['requested', 'pending_approval'])
            ->latest('id')
            ->firstOrFail();

        $result = $approve
            ? $this->orderRefundService->approveRequestedRefund($refund, 'staff', (int) $user->id, $validated['approval_note'] ?? null)
            : $this->orderRefundService->rejectRequestedRefund($refund, $validated['rejection_reason'], 'staff', (int) $user->id);

        if (in_array((string) ($result['result'] ?? ''), ['failed', 'invalid_state', 'invalid_stage', 'already_approved', 'already_rejected'], true)) {
            return response()->json(['success' => false, 'message' => $result['message']], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'refund' => $result['refund'],
        ]);
    }

    public function complete(Request $request, $id)
    {
        $user = Auth::guard('user')->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Check if user can access staff job orders.
        if (! $this->canAccessStaffOrders($user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Get the shop owner ID for this STAFF user
        $shopOwnerId = $user->shop_owner_id ?? $user->id;

        // Fetch order ONLY if it belongs to this shop
        $order = Order::with(['items', 'customer', 'shopOwner'])
            ->where('shop_owner_id', $shopOwnerId)
            ->where('id', $id)
            ->first();

        if (! $order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        try {
            $order = $this->orderFulfillmentService->completeDirectly($order, $user);
        } catch (ValidationException $exception) {
            return $this->transitionErrorResponse($exception);
        }

        Log::info('Order completed', [
            'order_id' => $id,
            'order_number' => $order->order_number,
            'total_amount' => $order->total_amount,
            'user_id' => $user->id,
            'user_role' => $user->role,
        ]);

        // TODO: Generate invoice if needed
        // You can implement invoice generation logic here

        return response()->json([
            'success' => true,
            'message' => 'Order completed successfully',
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'customer_name' => $order->customer_name ?? $order->customer?->name ?? 'Guest',
                'customer_email' => $order->customer_email ?? $order->customer?->email ?? '',
                'total_amount' => $order->total_amount,
                'status' => $order->status,
            ],
        ]);
    }

    /**
     * Activate pickup confirmation for an order
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function activatePickup(Request $request, $id)
    {
        try {
            $user = Auth::guard('user')->user();

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            // Check if user can access staff job orders.
            if (! $this->canAccessStaffOrders($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $order = Order::find($id);

            if (! $order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found',
                ], 404);
            }

            // Get the shop owner ID for this STAFF user
            $shopOwnerId = $user->shop_owner_id ?? $user->id;

            // Verify order belongs to this shop
            if ($order->shop_owner_id != $shopOwnerId) {
                return response()->json([
                    'success' => false,
                    'message' => 'This order does not belong to your shop',
                ], 403);
            }

            // Check if status is shipped
            $currentStatus = $order->status instanceof \App\Enums\OrderStatus ? $order->status->value : $order->status;
            if ($currentStatus !== 'shipped') {
                return response()->json([
                    'success' => false,
                    'message' => 'Pickup can only be activated when order is shipped. Current status: '.$currentStatus,
                ], 400);
            }

            // Check if pickup is already enabled
            if ($order->pickup_enabled) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pickup confirmation is already activated',
                ], 400);
            }

            // Enable pickup confirmation
            $order->update([
                'pickup_enabled' => true,
                'pickup_enabled_at' => now(),
                'pickup_enabled_by' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Pickup confirmation activated. Customer can now confirm they received their order.',
                'order' => $order->fresh(),
            ]);

        } catch (\Exception $e) {
            Log::error('Error activating pickup for order: '.$e->getMessage(), [
                'order_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to activate pickup confirmation',
            ], 500);
        }
    }
}
