<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use App\Enums\OrderStatus;
use App\Enums\NotificationType;
use App\Services\NotificationService;
use App\Services\OrderRefundService;
use App\Services\Orders\OrderFulfillmentService;
use App\Services\RetailPosRefundSummaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderRefundService $orderRefundService,
        private readonly OrderFulfillmentService $orderFulfillmentService,
        private readonly RetailPosRefundSummaryService $retailPosRefundSummaryService,
        private readonly NotificationService $notificationService,
    ) {
    }

    /**
     * Get all orders for shop owner
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $shopOwner = Auth::guard('shop_owner')->user();
        
        if (!$shopOwner) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $includeRefundItems = Schema::hasTable('order_refund_items');

        $query = Order::where('shop_owner_id', $shopOwner->id)
            ->with([
                'items.product',
                'customer',
                'refunds' => function ($refundQuery) use ($includeRefundItems) {
                    if ($includeRefundItems) {
                        $refundQuery->with('items.orderItem');
                    }

                    $refundQuery->orderByDesc('id');
                },
            ]);

        // Filter by status if provided
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Search by order number or customer name
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_email', 'like', "%{$search}%");
            });
        }

        // Sort
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Paginate
        $perPage = $request->get('per_page', 15);
        $orders = $query->paginate($perPage);
        $retailPosRefundSummaries = $this->retailPosRefundSummaryService->buildForOrders(
            (int) $shopOwner->id,
            $orders->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );

        return response()->json([
            'data' => $orders->map(function($order) use ($retailPosRefundSummaries, $includeRefundItems) {
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
                    'eta' => $order->eta ?? null,
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
                    'items' => $order->items->map(function($item) {
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
                ];
            }),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * Get a single order by ID
     * 
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $shopOwner = Auth::guard('shop_owner')->user();
        
        if (!$shopOwner) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $includeRefundItems = Schema::hasTable('order_refund_items');

        $order = Order::where('shop_owner_id', $shopOwner->id)
            ->with([
                'items.product',
                'customer',
                'refunds' => function ($refundQuery) use ($includeRefundItems) {
                    if ($includeRefundItems) {
                        $refundQuery->with('items.orderItem');
                    }

                    $refundQuery->orderByDesc('id');
                },
            ])
            ->find($id);

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

        $retailPosRefundSummary = $this->retailPosRefundSummaryService->buildForOrders((int) $shopOwner->id, [(int) $order->id]);

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
            'items' => $order->items->map(function($item) {
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
        ]);
    }

    /**
     * Update order status
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateStatus(Request $request, $id)
    {
        $shopOwner = Auth::guard('shop_owner')->user();

        if (! $shopOwner) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'status' => 'required|string',
            'tracking_number' => 'nullable|string|max:255',
            'carrier_company' => 'nullable|string|max:255',
            'carrier_name' => 'nullable|string|max:255',
            'carrier_phone' => 'nullable|string|max:50',
            'tracking_link' => 'nullable|url|max:500',
            'eta' => 'nullable|date',
        ]);

        $order = Order::where('shop_owner_id', $shopOwner->id)->find($id);

        if (! $order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        try {
            $updatedOrder = match ($validated['status']) {
                'processing' => $this->orderFulfillmentService->markProcessing($order, $shopOwner),
                'shipped' => $this->orderFulfillmentService->markShipped(
                    $order,
                    $shopOwner,
                    array_intersect_key($validated, array_flip([
                        'tracking_number',
                        'carrier_company',
                        'carrier_name',
                        'carrier_phone',
                        'tracking_link',
                        'eta',
                    ])),
                ),
                'completed' => $this->orderFulfillmentService->completeDirectly($order, $shopOwner),
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

    public function correctTerminalOutcome(Request $request, $id)
    {
        $shopOwner = Auth::guard('shop_owner')->user();

        if (! $shopOwner) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'target' => 'required|in:delivered,completed',
            'reason' => 'required|string|max:2000',
        ]);
        $order = Order::where('shop_owner_id', $shopOwner->id)->find($id);

        if (! $order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        try {
            $updatedOrder = $this->orderFulfillmentService->correctTerminalOutcome(
                $order,
                $shopOwner,
                OrderStatus::from($validated['target']),
                $validated['reason'],
            );
        } catch (ValidationException $exception) {
            return $this->transitionErrorResponse($exception);
        }

        return response()->json([
            'success' => true,
            'message' => 'Order terminal outcome corrected successfully.',
            'order' => [
                'id' => $updatedOrder->id,
                'order_number' => $updatedOrder->order_number,
                'status' => $updatedOrder->status,
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
            $shopOwner = Auth::guard('shop_owner')->user();
            
            if (!$shopOwner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            $order = Order::find($id);
            
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }
            
            // Verify order belongs to this shop owner
            if ($order->shop_owner_id != $shopOwner->id) {
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
                'pickup_enabled_by' => $shopOwner->id,
            ]);

            if ($order->customer_id) {
                $this->notificationService->sendToUser(
                    userId: (int) $order->customer_id,
                    type: NotificationType::ORDER_STATUS_UPDATE,
                    title: 'Order Pickup Activated',
                    message: "Pickup confirmation is now enabled for order {$order->order_number}.",
                    data: [
                        'order_id' => (int) $order->id,
                        'order_number' => (string) $order->order_number,
                        'pickup_enabled' => true,
                    ],
                    actionUrl: '/my-orders',
                    shopId: (int) $order->shop_owner_id,
                    priority: 'high'
                );
            }
            
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

    public function confirmReturnReceived(Request $request, $id)
    {
        $validated = $request->validate([
            'return_notes' => 'nullable|string|max:1000',
            'line_dispositions' => 'required|array|min:1',
            'line_dispositions.*.order_item_id' => 'required|integer|min:1',
            'line_dispositions.*.approved_qty' => 'required|integer|min:1',
            'line_dispositions.*.inspection_disposition' => 'required|string|in:resellable,damaged',
        ]);

        $shopOwner = Auth::guard('shop_owner')->user();

        if (!$shopOwner) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $registrationType = strtolower(trim((string) ($shopOwner->registration_type ?? '')));
        if ($registrationType === 'company') {
            return response()->json([
                'success' => false,
                'message' => 'For company accounts, confirm returned items from the Staff Job Orders module.',
            ], 422);
        }

        $order = Order::query()
            ->where('shop_owner_id', (int) $shopOwner->id)
            ->where('id', $id)
            ->first();

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        $refund = OrderRefund::query()
            ->where('order_id', $order->id)
            ->where('shop_owner_id', (int) $shopOwner->id)
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
            staffId: null,
            notes: $validated['return_notes'] ?? null,
            lineDispositions: $validated['line_dispositions'],
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
        $validated = $request->validate([
            'tracking_number' => 'required|string|max:255',
            'carrier_company' => 'required|string|max:255',
            'rider_name' => 'required|string|max:255',
            'rider_phone' => 'required|string|max:30',
            'tracking_link' => 'required|url|max:500',
            'note' => 'nullable|string|max:1000',
            'shipped_at' => 'nullable|date',
        ]);

        $shopOwner = Auth::guard('shop_owner')->user();

        if (!$shopOwner) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $registrationType = strtolower(trim((string) ($shopOwner->registration_type ?? '')));
        if ($registrationType === 'company') {
            return response()->json([
                'success' => false,
                'message' => 'For company accounts, arrange return pickup from the Staff Job Orders module.',
            ], 422);
        }

        $order = Order::query()
            ->where('shop_owner_id', (int) $shopOwner->id)
            ->where('id', $id)
            ->first();

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        $refund = OrderRefund::query()
            ->where('order_id', $order->id)
            ->where('shop_owner_id', (int) $shopOwner->id)
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
            staffId: null,
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

}
