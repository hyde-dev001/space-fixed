<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use App\Models\Logistics\Shipment;
use App\Models\User;
use App\Services\OrderRefundService;
use App\Services\RetailPosRefundSummaryService;
use App\Services\Logistics\DeliveryScheduleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class StaffOrderController extends Controller
{
    private function canAccessStaffOrders($user): bool
    {
        if (!$user) {
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
        private readonly RetailPosRefundSummaryService $retailPosRefundSummaryService,
        private readonly DeliveryScheduleService $deliveryScheduleService,
    ) {
    }

    public function index(Request $request)
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Check if user can access staff job orders.
        if (!$this->canAccessStaffOrders($user)) {
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

        $orders = $orders->map(function ($order) use ($retailPosRefundSummaries, $includeRefundItems, $deliveryCancellations) {
                $itemSubtotal = (float) ($order->total_amount ?? 0);
                $shippingFee = (float) ($order->shipping_fee ?? 0);
                $hasStoredVat = $order->vat_amount !== null;
                $vatAmount = $hasStoredVat ? round((float) $order->vat_amount, 2) : null;
                $vatRate = $hasStoredVat && ((float) ($order->vat_rate ?? 0)) > 0
                    ? round((float) $order->vat_rate, 2)
                    : null;
                $latestRefund = $order->refunds->first();
                $cancelledShipment = $deliveryCancellations->get($order->id);

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
                    'latest_refund' => $latestRefund ? [
                        'id' => (int) $latestRefund->id,
                        'status' => (string) $latestRefund->status,
                        'reason_code' => $latestRefund->reason_code,
                        'reason_note' => $latestRefund->reason_note,
                        'other_reason_note' => $latestRefund->other_reason_note,
                        'shop_owner_status' => (string) ($latestRefund->shop_owner_status ?? 'pending'),
                        'finance_status' => (string) ($latestRefund->finance_status ?? 'pending'),
                        'return_status' => (string) ($latestRefund->return_status ?? 'awaiting_approval'),
                        'return_source' => (string) ($latestRefund->return_source ?? 'customer'),
                        'customer_return_tracking_number' => $latestRefund->customer_return_tracking_number,
                        'customer_return_carrier' => $latestRefund->customer_return_carrier,
                        'customer_return_rider_name' => $latestRefund->customer_return_rider_name,
                        'customer_return_rider_phone' => $latestRefund->customer_return_rider_phone,
                        'customer_return_tracking_link' => $latestRefund->customer_return_tracking_link,
                        'customer_return_shipped_at' => optional($latestRefund->customer_return_shipped_at)->toDateTimeString(),
                        'staff_return_tracking_number' => $latestRefund->staff_return_tracking_number,
                        'staff_return_carrier' => $latestRefund->staff_return_carrier,
                        'staff_return_rider_name' => $latestRefund->staff_return_rider_name,
                        'staff_return_rider_phone' => $latestRefund->staff_return_rider_phone,
                        'staff_return_tracking_link' => $latestRefund->staff_return_tracking_link,
                        'staff_return_shipped_at' => optional($latestRefund->staff_return_shipped_at)->toDateTimeString(),
                        'return_arranged_by_staff_at' => optional($latestRefund->return_arranged_by_staff_at)->toDateTimeString(),
                        'return_confirmed_at' => optional($latestRefund->return_confirmed_at)->toDateTimeString(),
                        'refund_executed_at' => optional($latestRefund->refund_executed_at)->toDateTimeString(),
                        'rejected_at' => optional($latestRefund->rejected_at)->toDateTimeString(),
                        'rejection_reason' => $latestRefund->rejection_reason,
                        'flow_type' => (string) ($latestRefund->flow_type ?? ''),
                        'items' => $latestRefundItems,
                    ] : null,
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
        
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Check if user can access staff job orders.
        if (!$this->canAccessStaffOrders($user)) {
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

        if (!$order) {
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
            'latest_refund' => $latestRefund ? [
                'id' => (int) $latestRefund->id,
                'status' => (string) $latestRefund->status,
                'reason_code' => $latestRefund->reason_code,
                'reason_note' => $latestRefund->reason_note,
                'other_reason_note' => $latestRefund->other_reason_note,
                'shop_owner_status' => (string) ($latestRefund->shop_owner_status ?? 'pending'),
                'finance_status' => (string) ($latestRefund->finance_status ?? 'pending'),
                'return_status' => (string) ($latestRefund->return_status ?? 'awaiting_approval'),
                'return_source' => (string) ($latestRefund->return_source ?? 'customer'),
                'customer_return_tracking_number' => $latestRefund->customer_return_tracking_number,
                'customer_return_carrier' => $latestRefund->customer_return_carrier,
                'customer_return_rider_name' => $latestRefund->customer_return_rider_name,
                'customer_return_rider_phone' => $latestRefund->customer_return_rider_phone,
                'customer_return_tracking_link' => $latestRefund->customer_return_tracking_link,
                'customer_return_shipped_at' => optional($latestRefund->customer_return_shipped_at)->toDateTimeString(),
                'staff_return_tracking_number' => $latestRefund->staff_return_tracking_number,
                'staff_return_carrier' => $latestRefund->staff_return_carrier,
                'staff_return_rider_name' => $latestRefund->staff_return_rider_name,
                'staff_return_rider_phone' => $latestRefund->staff_return_rider_phone,
                'staff_return_tracking_link' => $latestRefund->staff_return_tracking_link,
                'staff_return_shipped_at' => optional($latestRefund->staff_return_shipped_at)->toDateTimeString(),
                'return_arranged_by_staff_at' => optional($latestRefund->return_arranged_by_staff_at)->toDateTimeString(),
                'return_confirmed_at' => optional($latestRefund->return_confirmed_at)->toDateTimeString(),
                'refund_executed_at' => optional($latestRefund->refund_executed_at)->toDateTimeString(),
                'rejected_at' => optional($latestRefund->rejected_at)->toDateTimeString(),
                'rejection_reason' => $latestRefund->rejection_reason,
                'flow_type' => (string) ($latestRefund->flow_type ?? ''),
                'items' => $latestRefundItems,
            ] : null,
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

    public function updateStatus(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'status' => 'required|in:pending,processing,shipped,completed,cancelled',
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
        
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Check if user can access staff job orders.
        if (!$this->canAccessStaffOrders($user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Get the shop owner ID for this STAFF user
        $shopOwnerId = $user->shop_owner_id ?? $user->id;

        // Fetch order ONLY if it belongs to this shop
        $order = Order::where('shop_owner_id', $shopOwnerId)
            ->where('id', $id)
            ->first();

        if (!$order) {
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

        // Store the old status for comparison (convert enum to string value)
        $oldStatus = $order->status->value;

        // Update order status and shipping info
        $order->status = $validated['status'];
        
        if (isset($validated['tracking_number'])) {
            $order->tracking_number = $validated['tracking_number'];
        }
        
        if (isset($validated['carrier_company'])) {
            $order->carrier_company = $validated['carrier_company'];
        }
        
        if (isset($validated['carrier_name'])) {
            $order->carrier_name = $validated['carrier_name'];
        }
        
        if (isset($validated['carrier_phone'])) {
            $order->carrier_phone = $validated['carrier_phone'];
        }
        
        if (isset($validated['tracking_link'])) {
            $order->tracking_link = $validated['tracking_link'];
        }
        
        if (isset($validated['eta'])) {
            $order->eta = $validated['eta'];
        }
        
        $order->save();

        // Log the status change with business context
        activity()
            ->causedBy($user)
            ->performedOn($order)
            ->withProperties([
                'order_number' => $order->order_number,
                'customer_name' => $order->customer_name ?? 'N/A',
                'old_status' => $oldStatus,
                'new_status' => $validated['status'],
                'total_amount' => $order->total_amount,
                'updated_by_name' => $user->name,
                'updated_by_role' => $user->role,
                'tracking_number' => $validated['tracking_number'] ?? null,
                'carrier_company' => $validated['carrier_company'] ?? null,
            ])
            ->log("Order status updated from {$oldStatus} to {$validated['status']}");

        Log::info('Order status updated', [
            'order_id' => $id,
            'order_number' => $order->order_number,
            'old_status' => $oldStatus,
            'new_status' => $validated['status'],
            'user_id' => $user->id,
            'user_role' => $user->role,
        ]);

        if ($oldStatus !== 'shipped' && $validated['status'] === 'shipped') {
            app(\App\Services\Logistics\SourceShipmentService::class)->ensureRetailOrderShipment($order->fresh());
        }

        return response()->json([
            'success' => true,
            'message' => 'Order status updated successfully',
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'tracking_number' => $order->tracking_number,
                'updated_at' => $order->updated_at->toISOString(),
            ],
        ]);
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
            'line_dispositions' => 'nullable|array',
            'line_dispositions.*.order_item_id' => 'required|integer|min:1',
            'line_dispositions.*.approved_qty' => 'required|integer|min:1',
            'line_dispositions.*.inspection_disposition' => 'required|string|in:resellable,damaged',
        ]);

        $user = Auth::guard('user')->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        if (!$this->canAccessStaffOrders($user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $shopOwnerId = $user->shop_owner_id ?? $user->id;

        $order = Order::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('id', $id)
            ->first();

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        $refund = OrderRefund::query()
            ->where('order_id', $order->id)
            ->where('shop_owner_id', $shopOwnerId)
            ->where('flow_type', 'request_approval')
            ->whereIn('status', ['requested', 'pending_approval', 'processing', 'succeeded'])
            ->latest('id')
            ->first();

        if (!$refund) {
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
            'tracking_number' => ($isShopOwned ? 'nullable' : 'required') . '|string|max:255',
            'carrier_company' => ($isShopOwned ? 'nullable' : 'required') . '|string|max:255',
            'rider_name' => ($isShopOwned ? 'nullable' : 'required') . '|string|max:255',
            'rider_phone' => ($isShopOwned ? 'nullable' : 'required') . '|string|max:30',
            'tracking_link' => ($isShopOwned ? 'nullable' : 'required') . '|url|max:500',
            'note' => 'nullable|string|max:1000',
            'shipped_at' => 'nullable|date',
        ]);

        if ($isShopOwned) {
            $validated['carrier_company'] = 'Shop-owned logistics';
        }

        $user = Auth::guard('user')->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        if (!$this->canAccessStaffOrders($user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $shopOwnerId = $user->shop_owner_id ?? $user->id;

        $order = Order::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('id', $id)
            ->first();

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        $refund = OrderRefund::query()
            ->where('order_id', $order->id)
            ->where('shop_owner_id', $shopOwnerId)
            ->where('flow_type', 'request_approval')
            ->whereIn('status', ['requested', 'pending_approval', 'processing', 'succeeded'])
            ->latest('id')
            ->first();

        if (!$refund) {
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

        if ($isShopOwned) {
            $this->orderRefundService->ensureReturnShipment($result['refund']);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'] ?? 'Return pickup arranged successfully.',
            'refund' => $result['refund'],
        ]);
    }

    public function complete(Request $request, $id)
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Check if user can access staff job orders.
        if (!$this->canAccessStaffOrders($user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Get the shop owner ID for this STAFF user
        $shopOwnerId = $user->shop_owner_id ?? $user->id;

        // Fetch order ONLY if it belongs to this shop
        $order = Order::with(['items', 'customer', 'shopOwner'])
            ->where('shop_owner_id', $shopOwnerId)
            ->where('id', $id)
            ->first();

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        // Update order status to completed
        $order->status = 'completed';
        $order->save();

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
                'status' => 'completed',
            ],
        ]);
    }

    /**
     * Activate pickup confirmation for an order
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function activatePickup(Request $request, $id)
    {
        try {
            $user = Auth::guard('user')->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            // Check if user can access staff job orders.
            if (!$this->canAccessStaffOrders($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $order = Order::find($id);
            
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }
            
            // Get the shop owner ID for this STAFF user
            $shopOwnerId = $user->shop_owner_id ?? $user->id;
            
            // Verify order belongs to this shop
            if ($order->shop_owner_id != $shopOwnerId) {
                return response()->json([
                    'success' => false,
                    'message' => 'This order does not belong to your shop'
                ], 403);
            }
            
            // Check if status is shipped
            $currentStatus = $order->status instanceof \App\Enums\OrderStatus ? $order->status->value : $order->status;
            if ($currentStatus !== 'shipped') {
                return response()->json([
                    'success' => false,
                    'message' => 'Pickup can only be activated when order is shipped. Current status: ' . $currentStatus
                ], 400);
            }
            
            // Check if pickup is already enabled
            if ($order->pickup_enabled) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pickup confirmation is already activated'
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
                'order' => $order->fresh()
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error activating pickup for order: ' . $e->getMessage(), [
                'order_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to activate pickup confirmation'
            ], 500);
        }
    }

}
