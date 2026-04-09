<?php

namespace App\Http\Controllers\UserSide;

use App\Http\Controllers\Controller;
use App\Models\InventoryColorVariant;
use App\Models\InventoryItem;
use App\Models\InventorySize;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Enums\OrderStatus;
use App\Services\NotificationService;
use App\Services\OrderRefundService;
use App\Services\PaymongoRefundService;
use App\Services\PaymentSettlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    protected NotificationService $notificationService;
    protected OrderRefundService $orderRefundService;
    protected PaymongoRefundService $paymongoRefundService;
    protected PaymentSettlementService $paymentSettlementService;
    private ?array $orderRefundColumns = null;
    private bool $orderRefundColumnIntrospectionFailed = false;

    public function __construct(
        NotificationService $notificationService,
        OrderRefundService $orderRefundService,
        PaymongoRefundService $paymongoRefundService,
        PaymentSettlementService $paymentSettlementService,
    )
    {
        $this->notificationService = $notificationService;
        $this->orderRefundService = $orderRefundService;
        $this->paymongoRefundService = $paymongoRefundService;
        $this->paymentSettlementService = $paymentSettlementService;
    }
    /**
     * Display user's orders
     */
    public function index(): Response|\Illuminate\Http\RedirectResponse
    {
        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return redirect()->route('login');
        }

        Notification::query()
            ->where('user_id', $user->id)
            ->where('is_read', false)
            ->whereIn('type', [
                'order_placed',
                'order_confirmed',
                'order_shipped',
                'order_delivered',
                'order_cancelled',
                'order_status_update',
            ])
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        
        $orders = Order::where('customer_id', $user->id)
            ->with([
                'items',
                'shopOwner',
                'refunds' => fn ($query) => $query->orderByDesc('id'),
            ])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function (Order $order) {
                $itemSubtotal = (float) ($order->total_amount ?? 0);
                $shippingFee = (float) ($order->shipping_fee ?? 0);
                $vatAmount = $order->vat_amount !== null ? max(0.0, (float) $order->vat_amount) : null;
                $vatRate = $order->vat_rate !== null ? max(0.0, (float) $order->vat_rate) : null;
                $legacyGrandTotal = (float) ($order->total ?? 0);

                if ($shippingFee <= 0 && $legacyGrandTotal > $itemSubtotal) {
                    $shippingFee = round($legacyGrandTotal - $itemSubtotal, 2);
                }

                $grandTotal = $itemSubtotal + $shippingFee + ($vatAmount ?? 0.0);
                if ($grandTotal <= 0 && $legacyGrandTotal > 0) {
                    $grandTotal = $legacyGrandTotal;
                }

                $totalPaid = max($grandTotal, $legacyGrandTotal, $itemSubtotal);

                $paymentMethod = strtolower((string) ($order->payment_method ?? ''));
                $isOnlinePayment = !in_array($paymentMethod, ['cod', 'cash_on_delivery', 'cash on delivery'], true);
                $latestRefund = $order->refunds->first();

                if ($isOnlinePayment && $latestRefund) {
                    $this->reconcileRefundWithGateway($order, $latestRefund);
                    $order->refresh();
                    $order->loadMissing(['refunds' => fn ($query) => $query->orderByDesc('id')]);
                    $latestRefund = $order->refunds->first();
                }

                $refundStatus = null;
                $refundStatusNote = null;
                if ((string) ($order->payment_status ?? 'pending') === 'refunded') {
                    $refundStatus = 'refunded';
                    $refundStatusNote = 'Refund has been completed and credited back to your original payment method.';
                } elseif ($isOnlinePayment) {
                    $latestRefundStatus = strtolower((string) ($latestRefund?->status ?? ''));
                    $hasRefundReference = !empty($order->paymongo_refund_id) || !empty($latestRefund?->paymongo_refund_id);

                    // Treat any non-terminal request-approval status as in-progress to prevent duplicate requests.
                    if ($latestRefund && !in_array($latestRefundStatus, ['rejected', 'failed'], true)) {
                        $refundStatus = $latestRefundStatus === 'succeeded' ? 'refunded' : 'processing';
                        $refundStatusNote = $latestRefundStatus === 'succeeded'
                            ? 'Refund has been completed and credited back to your original payment method.'
                            : 'Refund is being processed by PayMongo and your payment provider. Settlement usually reflects within 2-4 business days.';
                    } elseif ($hasRefundReference) {
                        $refundStatus = 'processing';
                        $refundStatusNote = 'Refund is being processed by PayMongo and your payment provider. Settlement usually reflects within 2-4 business days.';
                    }
                }

                if ($isOnlinePayment && $totalPaid <= $itemSubtotal && $order->paymongo_link_id && $order->shopOwner?->paymongo_secret_key) {
                    $sessionTotal = $this->resolveCheckoutSessionTotal($order->paymongo_link_id, (string) $order->shopOwner->paymongo_secret_key);

                    if ($sessionTotal !== null && $sessionTotal > $itemSubtotal) {
                        $shippingFee = round(max($shippingFee, $sessionTotal - $itemSubtotal), 2);
                        $grandTotal = max($grandTotal, $itemSubtotal + $shippingFee, $sessionTotal);
                        $totalPaid = max($totalPaid, $sessionTotal);

                        if ((float) ($order->shipping_fee ?? 0) <= 0 || (float) ($order->total ?? 0) < $totalPaid) {
                            $syncPayload = [];
                            if (Schema::hasColumn('orders', 'shipping_fee')) {
                                $syncPayload['shipping_fee'] = $shippingFee;
                            }
                            if (Schema::hasColumn('orders', 'total')) {
                                $syncPayload['total'] = $totalPaid;
                            }

                            if (!empty($syncPayload)) {
                                Order::whereKey($order->id)->update($syncPayload);
                            }
                        }
                    }
                }

                $cancellationRefundDeadlineAt = $order->cancellation_refund_deadline_at;
                $cancellationRefundWindowMinutes = $order->resolveCancellationRefundWindowMinutes();
                $cancellationRefundDeadlinePassed = !$order->isCancellationRefundWindowOpen();

                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'payment_status' => $order->payment_status ?? 'pending',
                    'payment_method' => $order->payment_method ?? 'paymongo',
                    'total_amount' => $itemSubtotal,
                    'shipping_fee' => $shippingFee,
                    'vat_amount' => $vatAmount,
                    'vat_rate' => $vatRate,
                    'grand_total' => $grandTotal,
                    'total_paid' => $totalPaid,
                    'created_at' => $order->created_at->format('Y-m-d H:i:s'),
                    'cancellation_refund_deadline_at' => $cancellationRefundDeadlineAt?->toIso8601String(),
                    'cancellation_refund_deadline_passed' => $cancellationRefundDeadlinePassed,
                    'cancellation_refund_window_minutes' => $cancellationRefundWindowMinutes,
                    'shop_id' => $order->shopOwner ? $order->shopOwner->id : null,
                    'shop_name' => $order->shopOwner->business_name ?? 'Unknown Shop',
                    'shop_address' => $order->shopOwner->business_address ?? $order->shopOwner->city_state,
                    'items_count' => $order->items->count(),
                    'items' => $order->items->map(function ($item) {
                        return [
                            'id' => $item->id,
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
                    'shipping_address' => $order->shipping_address,
                    'customer_name' => $order->customer_name,
                    'customer_email' => $order->customer_email,
                    'customer_phone' => $order->customer_phone,
                    'tracking_number' => $order->tracking_number,
                    'carrier_company' => $order->carrier_company,
                    'carrier_name' => $order->carrier_name,
                    'tracking_link' => $order->tracking_link,
                    'eta' => $order->eta,
                    'pickup_enabled' => $order->pickup_enabled ?? false,
                    'refund_status' => $refundStatus,
                    'refund_status_note' => $refundStatusNote,
                    'refund_stage' => $latestRefund ? [
                        'id' => $latestRefund->id,
                        'status' => (string) ($latestRefund->status ?? ''),
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
                        'rejection_reason' => $latestRefund->rejection_reason,
                        'is_refunded' => in_array(strtolower((string) ($latestRefund->status ?? '')), ['succeeded', 'refunded'], true),
                    ] : null,
                ];
            });

        return Inertia::render('UserSide/Orders/MyOrders', [
            'orders' => $orders,
        ]);
    }

    private function reconcileRefundWithGateway(Order $order, ?OrderRefund $latestRefund): void
    {
        if (!$latestRefund) {
            return;
        }

        if ((string) ($order->payment_status ?? 'pending') === 'refunded') {
            return;
        }

        $refundStatus = strtolower((string) ($latestRefund->status ?? ''));
        if (!in_array($refundStatus, ['processing', 'requested', 'pending_approval'], true)) {
            return;
        }

        $refundId = trim((string) ($latestRefund->paymongo_refund_id ?? ''));
        if ($refundId === '') {
            return;
        }

        $secretKey = trim((string) ($order->shopOwner?->paymongo_secret_key ?? ''));
        if ($secretKey === '') {
            return;
        }

        $gateway = $this->paymongoRefundService->getRefundStatus($secretKey, $refundId);
        if (!($gateway['success'] ?? false)) {
            return;
        }

        $status = strtolower((string) ($gateway['status'] ?? 'processing'));

        if (in_array($status, ['succeeded', 'completed', 'paid'], true)) {
            $latestRefund->update([
                'status' => 'succeeded',
                'refunded_at' => $latestRefund->refunded_at ?? now(),
                'failure_reason' => null,
                'failed_at' => null,
            ]);

            $this->paymentSettlementService->settleOrderRefunded(
                order: $order,
                refundId: $refundId,
                reason: $latestRefund->reason_code,
                note: $latestRefund->reason_note,
            );

            return;
        }

        if (in_array($status, ['failed', 'canceled', 'cancelled'], true)) {
            $latestRefund->update([
                'status' => 'failed',
                'failure_reason' => 'paymongo_refund_failed',
                'failed_at' => now(),
            ]);

            $this->paymentSettlementService->recordOrderRefundFailure($order, 'paymongo_refund_failed');
        }
    }

    private function resolveCheckoutSessionTotal(string $checkoutSessionId, string $secretKey): ?float
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . base64_encode($secretKey . ':'),
            ])->get("https://api.paymongo.com/v1/checkout_sessions/{$checkoutSessionId}");

            if ($response->failed()) {
                return null;
            }

            $attributes = $response->json('data.attributes') ?? [];
            $lineItems = $attributes['line_items'] ?? [];

            $lineItemsTotal = collect($lineItems)->reduce(function ($sum, $item) {
                $amount = (int) ($item['amount'] ?? 0);
                $qty = (int) ($item['quantity'] ?? 1);
                return $sum + (($amount * max($qty, 1)) / 100);
            }, 0.0);

            if ($lineItemsTotal > 0) {
                return round($lineItemsTotal, 2);
            }

            $payments = $attributes['payments'] ?? [];
            $firstPaymentAmount = (int) ($payments[0]['data']['attributes']['amount'] ?? $payments[0]['attributes']['amount'] ?? 0);
            if ($firstPaymentAmount > 0) {
                return round($firstPaymentAmount / 100, 2);
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('Unable to resolve PayMongo checkout session total for MyOrders', [
                'checkout_session_id' => $checkoutSessionId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Confirm order delivery
     */
    public function confirmDelivery(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|integer',
        ]);

        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $order = Order::where('id', $validated['order_id'])
            ->where('customer_id', $user->id)
            ->firstOrFail();

        // Only allow confirmation if order is shipped
        if (!in_array($order->status, [OrderStatus::SHIPPED])) {
            return response()->json([
                'success' => false,
                'message' => 'Can only confirm orders that have been shipped',
            ], 400);
        }

        $order->status = OrderStatus::DELIVERED;

        $paymentMethod = strtolower((string) ($order->payment_method ?? ''));
        $isCodOrder = in_array($paymentMethod, ['cod', 'cash_on_delivery', 'cash on delivery'], true);

        if ($isCodOrder && !in_array((string) ($order->payment_status ?? 'pending'), ['paid', 'completed'], true)) {
            $order->payment_status = 'paid';
            $order->paid_at = now();
            $order->payment_failed_at = null;
            $order->payment_failure_reason = null;
            $order->payment_expired_at = null;
        }

        $order->save();

        // Notify shop owner about successful delivery
        try {
            $this->notificationService->sendToShopOwner(
                shopOwnerId: $order->shop_owner_id,
                type: \App\Enums\NotificationType::ORDER_DELIVERED,
                title: 'Order Delivered Successfully',
                message: "Order #{$order->order_number} has been delivered to customer",
                data: [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer_name' => $order->customer_name,
                    'total' => number_format($order->total_amount, 2),
                ],
                actionUrl: '/shop-owner/job-orders-retail'
            );
            Log::info('Shop owner notified of successful delivery', [
                'shop_owner_id' => $order->shop_owner_id,
                'order_number' => $order->order_number,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send delivery notification to shop owner', [
                'shop_owner_id' => $order->shop_owner_id,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Order confirmed as delivered',
        ]);
    }

    public function requestRefund(Request $request)
    {
        try {
            $validated = $request->validate([
                'order_id' => 'required|integer',
                'reason' => 'required|string|max:255',
                'refund_method' => 'nullable|string|max:100',
                'request_type' => 'nullable|string|in:full,partial',
                'requested_amount' => 'nullable|numeric|min:0.01',
                'requested_item_ids' => 'nullable|array|min:1',
                'requested_item_ids.*' => 'integer|min:1',
                'refund_lines' => 'nullable|array|min:1',
                'refund_lines.*.order_item_id' => 'required|integer|min:1',
                'refund_lines.*.requested_qty' => 'required|integer|min:1',
                'note' => 'nullable|string|max:1000',
                'other_reason_note' => 'nullable|string|max:1000',
                'media' => 'required|array|size:6',
                'media.*' => 'required|file|mimes:jpg,jpeg,png,webp,mp4,mov,avi,mkv,webm',
            ]);

            $resolvedRefundMethod = trim((string) ($validated['refund_method'] ?? ''));
            if ($resolvedRefundMethod === '') {
                $resolvedRefundMethod = 'original_payment_method';
            }

            $user = Auth::guard('user')->user();

            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $order = Order::query()
                ->with('items')
                ->where('id', (int) $validated['order_id'])
                ->where('customer_id', (int) $user->id)
                ->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found.',
                ], 404);
            }

            $orderStatus = $order->status instanceof OrderStatus
                ? $order->status->value
                : (string) ($order->status ?? '');

            if (!in_array($orderStatus, [OrderStatus::DELIVERED->value, OrderStatus::COMPLETED->value], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only delivered or completed orders can request a refund.',
                ], 422);
            }

            $paymentMethod = strtolower((string) ($order->payment_method ?? ''));
            $isOnlinePayment = !in_array($paymentMethod, ['cod', 'cash_on_delivery', 'cash on delivery'], true);
            if (!$isOnlinePayment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only online-paid orders are eligible for gateway refund requests.',
                ], 422);
            }

            if (!in_array((string) ($order->payment_status ?? 'pending'), ['paid', 'completed'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order payment is not eligible for refund processing.',
                ], 422);
            }

            $existing = null;
            try {
                $existing = $this->buildActiveRefundRequestQuery((int) $order->id)->first();
            } catch (QueryException $queryException) {
                Log::warning('Skipping strict active refund duplicate-check due to schema/query mismatch', [
                    'order_id' => $order->id,
                    'error' => $queryException->getMessage(),
                ]);
            }

            if ($existing) {
                return response()->json([
                    'success' => false,
                    'message' => 'A refund request for this order is already in progress.',
                ], 422);
            }

            $mediaFiles = $request->file('media', []);
            $imageCount = 0;
            $videoCount = 0;
            $storedMedia = [];
            $maxImageBytes = 20 * 1024 * 1024; // 20MB
            $maxVideoBytes = 256 * 1024 * 1024; // 256MB

            foreach ($mediaFiles as $mediaFile) {
                $mime = strtolower((string) $mediaFile->getMimeType());
                if (str_starts_with($mime, 'video/')) {
                    if ((int) $mediaFile->getSize() > $maxVideoBytes) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Refund video must be 256MB or smaller.',
                        ], 422);
                    }
                    $videoCount++;
                } else {
                    if ((int) $mediaFile->getSize() > $maxImageBytes) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Each refund image must be 20MB or smaller.',
                        ], 422);
                    }
                    $imageCount++;
                }
            }

            if ($imageCount !== 5 || $videoCount !== 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'You must upload exactly 5 images and 1 video.',
                ], 422);
            }

            foreach ($mediaFiles as $mediaFile) {
                $path = $mediaFile->store('refund-evidence/order-' . $order->id, 'public');
                $storedMedia[] = Storage::url($path);
            }

            $fullRefundAmount = (float) ($order->total_amount ?? 0) + max(0, (float) ($order->shipping_fee ?? 0));
            if ($fullRefundAmount <= 0) {
                $fullRefundAmount = max((float) ($order->total ?? 0), (float) ($order->total_amount ?? 0));
            }

            if ($fullRefundAmount <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Refund amount could not be determined for this order.',
                ], 422);
            }

            $requestType = strtolower(trim((string) ($validated['request_type'] ?? 'full')));
            $requestType = in_array($requestType, ['full', 'partial'], true) ? $requestType : 'full';

            $requestedItemIds = collect((array) ($validated['requested_item_ids'] ?? []))
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->values()
                ->all();

            $requestedRefundLines = collect((array) ($validated['refund_lines'] ?? []))
                ->map(function ($line) {
                    return [
                        'order_item_id' => (int) ($line['order_item_id'] ?? 0),
                        'requested_qty' => (int) ($line['requested_qty'] ?? 0),
                    ];
                })
                ->filter(fn (array $line) => $line['order_item_id'] > 0 && $line['requested_qty'] > 0)
                ->values();

            $selectedItemsAmount = 0.0;
            $selectedItemsSummary = [];
            $normalizedRefundLines = [];
            $orderItemsById = $order->items->keyBy('id');

            if ($requestedRefundLines->isNotEmpty()) {
                $invalidLineItems = $requestedRefundLines
                    ->pluck('order_item_id')
                    ->filter(fn (int $itemId) => !$orderItemsById->has($itemId))
                    ->values()
                    ->all();

                if (!empty($invalidLineItems)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'One or more refund lines reference an invalid order item.',
                    ], 422);
                }

                foreach ($requestedRefundLines as $line) {
                    $orderItem = $orderItemsById->get($line['order_item_id']);
                    if (!$orderItem) {
                        continue;
                    }

                    $requestedQty = (int) ($line['requested_qty'] ?? 0);
                    $maxQty = max(1, (int) ($orderItem->quantity ?? 1));
                    if ($requestedQty > $maxQty) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Requested quantity exceeds ordered quantity for one or more lines.',
                        ], 422);
                    }

                    $unitPrice = (float) ($orderItem->price ?? 0);
                    if ($unitPrice <= 0) {
                        $itemQty = max(1, (int) ($orderItem->quantity ?? 1));
                        $unitPrice = round((float) ($orderItem->subtotal ?? 0) / $itemQty, 2);
                    }

                    $lineAmount = round($unitPrice * $requestedQty, 2);
                    $selectedItemsAmount += $lineAmount;
                    $selectedItemsSummary[] = sprintf(
                        '%s x%d (%.2f)',
                        (string) ($orderItem->product_name ?? 'Item'),
                        $requestedQty,
                        $lineAmount
                    );

                    $resolvedVariantId = null;
                    $size = trim((string) ($orderItem->size ?? ''));
                    $color = trim((string) ($orderItem->color ?? ''));
                    if ((int) ($orderItem->product_id ?? 0) > 0 && $size !== '' && $color !== '') {
                        $resolvedVariantId = ProductVariant::query()
                            ->where('product_id', (int) $orderItem->product_id)
                            ->where('size', $size)
                            ->whereRaw('LOWER(color) = ?', [strtolower($color)])
                            ->value('id');
                    }

                    $normalizedRefundLines[] = [
                        'order_item_id' => (int) $orderItem->id,
                        'product_id' => (int) ($orderItem->product_id ?? 0),
                        'product_variant_id' => $resolvedVariantId ? (int) $resolvedVariantId : null,
                        'requested_qty' => $requestedQty,
                        'approved_qty' => $requestedQty,
                        'unit_price_snapshot' => round($unitPrice, 2),
                        'line_amount' => $lineAmount,
                        'inspection_disposition' => 'pending',
                        'inventory_action' => 'pending',
                    ];
                }

                $selectedItemsAmount = round($selectedItemsAmount, 2);
            }

            if (!empty($requestedItemIds)) {
                $invalidItemIds = array_values(array_filter(
                    $requestedItemIds,
                    fn (int $itemId) => !$orderItemsById->has($itemId)
                ));

                if (!empty($invalidItemIds)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'One or more selected refund items do not belong to this order.',
                    ], 422);
                }

                foreach ($requestedItemIds as $itemId) {
                    $orderItem = $orderItemsById->get($itemId);
                    if (!$orderItem) {
                        continue;
                    }

                    $lineSubtotal = (float) ($orderItem->subtotal ?? 0);
                    if ($lineSubtotal <= 0) {
                        $lineSubtotal = round((float) ($orderItem->price ?? 0) * max(1, (int) ($orderItem->quantity ?? 1)), 2);
                    }

                    $selectedItemsAmount += $lineSubtotal;
                    $selectedItemsSummary[] = sprintf(
                        '%s x%d (%.2f)',
                        (string) ($orderItem->product_name ?? 'Item'),
                        (int) ($orderItem->quantity ?? 1),
                        round($lineSubtotal, 2)
                    );
                }

                $selectedItemsAmount = round($selectedItemsAmount, 2);
            }

            $amount = round($fullRefundAmount, 2);
            if ($requestType === 'partial') {
                $usesLinePayload = !empty($normalizedRefundLines);
                $hasRequestedAmount = array_key_exists('requested_amount', $validated) && $validated['requested_amount'] !== null;
                if (!$hasRequestedAmount && empty($requestedItemIds) && !$usesLinePayload) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Partial refund requires a requested amount or selected item(s).',
                    ], 422);
                }

                if ($usesLinePayload) {
                    $amount = $selectedItemsAmount;
                } else {
                    $amount = $hasRequestedAmount
                        ? round((float) $validated['requested_amount'], 2)
                        : $selectedItemsAmount;
                }

                if ($amount <= 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Partial refund amount must be greater than zero.',
                    ], 422);
                }

                if (!$usesLinePayload && !empty($requestedItemIds) && $selectedItemsAmount > 0 && $amount > $selectedItemsAmount) {
                    return response()->json([
                        'success' => false,
                        'message' => sprintf('Requested amount exceeds selected item total (%.2f).', $selectedItemsAmount),
                    ], 422);
                }

                if ($amount >= round($fullRefundAmount, 2)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Requested partial amount must be less than the full order amount.',
                    ], 422);
                }
            }

            if ($amount > round($fullRefundAmount, 2)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Requested refund amount exceeds order refundable amount.',
                ], 422);
            }

            $reasonCode = Str::slug((string) $validated['reason'], '_');
            $baseReasonNote = trim((string) (($validated['reason'] ?? '') . (!empty($validated['note']) ? "\n\n" . $validated['note'] : '')));
            $scopeNotes = [];
            if ($requestType === 'partial') {
                $scopeNotes[] = 'Refund scope: partial';
                $scopeNotes[] = sprintf('Requested amount: %.2f', $amount);

                if (!empty($requestedItemIds) && !empty($selectedItemsSummary)) {
                    $scopeNotes[] = 'Selected item IDs: ' . implode(', ', $requestedItemIds);
                    $scopeNotes[] = 'Selected items: ' . implode('; ', $selectedItemsSummary);
                }

                if (!empty($normalizedRefundLines) && !empty($selectedItemsSummary)) {
                    $scopeNotes[] = 'Refund lines: ' . implode('; ', $selectedItemsSummary);
                }
            }

            $reasonNote = trim(implode("\n\n", array_filter([$baseReasonNote, implode("\n", $scopeNotes)])));

            $refundPayload = [
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'shop_owner_id' => $order->shop_owner_id,
                'flow_type' => 'request_approval',
                'status' => 'pending_approval',
                'payment_gateway' => 'paymongo',
                'paymongo_payment_id' => $order->paymongo_payment_id,
                'amount' => round($amount, 2),
                'currency' => 'PHP',
                'requested_refund_method' => $resolvedRefundMethod,
                'reason_code' => $reasonCode,
                'reason_note' => $reasonNote,
                'other_reason_note' => trim((string) ($validated['other_reason_note'] ?? '')) ?: null,
                'evidence_media' => $storedMedia,
                'idempotency_key' => 'request-approval-order-' . $order->id . '-' . Str::uuid()->toString(),
                'requested_at' => now(),
            ];

            $refundRequest = $this->createRefundRequestWithCompatibilityFallback($refundPayload, (int) $order->id);

            if (!empty($normalizedRefundLines) && Schema::hasTable('order_refund_items')) {
                try {
                    $refundRequest->items()->createMany($normalizedRefundLines);
                } catch (\Throwable $linePersistError) {
                    Log::warning('Refund line payload accepted but line persistence failed', [
                        'order_id' => (int) $order->id,
                        'refund_id' => (int) ($refundRequest->id ?? 0),
                        'error' => $linePersistError->getMessage(),
                    ]);
                }
            }

            try {
                $this->orderRefundService->notifyRefundApprovalRequested($refundRequest);
            } catch (\Throwable $notifyError) {
                Log::warning('Refund request created but notification failed', [
                    'order_id' => $order->id,
                    'refund_id' => $refundRequest->id,
                    'error' => $notifyError->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Refund request submitted successfully and is pending approval.',
                'refund' => $refundRequest->fresh(),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Failed to submit refund request', [
                'order_id' => $request->input('order_id'),
                'user_id' => Auth::guard('user')->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to submit refund request right now. Please try again in a moment.',
            ], 500);
        }
    }

    private function filterOrderRefundPayload(array $payload): array
    {
        if (!$this->hasOrderRefundTable()) {
            return $payload;
        }

        $filtered = [];
        foreach ($payload as $column => $value) {
            if ($this->hasOrderRefundColumn((string) $column)) {
                $filtered[$column] = $value;
            }
        }

        return $filtered;
    }

    private function buildActiveRefundRequestQuery(int $orderId): \Illuminate\Database\Eloquent\Builder
    {
        $query = OrderRefund::query()
            ->where('order_id', $orderId)
            ->latest('id');

        if (!$this->orderRefundColumnIntrospectionFailed
            && $this->hasOrderRefundColumn('flow_type')
            && !$this->orderRefundColumnIntrospectionFailed
        ) {
            $query->where('flow_type', 'request_approval');
        }

        if (!$this->orderRefundColumnIntrospectionFailed
            && $this->hasOrderRefundColumn('status')
            && !$this->orderRefundColumnIntrospectionFailed
        ) {
            // Block duplicate requests unless latest flow is explicitly terminal/rejected.
            $query->where(function ($statusQuery) {
                $statusQuery
                    ->whereNull('status')
                    ->orWhereNotIn('status', ['rejected', 'failed']);
            });
        }

        return $query;
    }

    private function createRefundRequestWithCompatibilityFallback(array $refundPayload, int $orderId): OrderRefund
    {
        $filteredPayload = $this->filterOrderRefundPayload($refundPayload);

        try {
            return OrderRefund::create($filteredPayload);
        } catch (QueryException $e) {
            $fallbackPayload = $filteredPayload;

            // Older production schemas may reject newer status enum values.
            if (($fallbackPayload['status'] ?? null) === 'pending_approval') {
                $fallbackPayload['status'] = 'requested';
            }

            // If schema introspection fails in prod, remove optional modern fields on retry.
            if ($this->looksLikeUnknownColumnError($e)) {
                unset(
                    $fallbackPayload['flow_type'],
                    $fallbackPayload['requested_refund_method'],
                    $fallbackPayload['evidence_media'],
                    $fallbackPayload['other_reason_note']
                );

                $fallbackPayload = $this->filterOrderRefundPayload($fallbackPayload);
            }

            Log::warning('Refund create retry with compatibility fallback after query exception', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return OrderRefund::create($fallbackPayload);
        }
    }

    private function hasOrderRefundTable(): bool
    {
        try {
            return Schema::hasTable('order_refunds');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function hasOrderRefundColumn(string $column): bool
    {
        if ($this->orderRefundColumns === null) {
            try {
                $columns = Schema::getColumnListing('order_refunds');
                $this->orderRefundColumns = array_fill_keys($columns, true);
            } catch (\Throwable $e) {
                // Preserve payload behavior, but mark failure so query filters do not assume columns exist.
                $this->orderRefundColumnIntrospectionFailed = true;
                return true;
            }
        }

        return isset($this->orderRefundColumns[$column]);
    }

    private function looksLikeUnknownColumnError(QueryException $exception): bool
    {
        $message = (string) $exception->getMessage();
        return str_contains($message, 'Unknown column') || str_contains($message, 'SQLSTATE[42S22]');
    }

    private function normalizeSizeSystem(?string $rawSystem): string
    {
        $normalized = strtoupper(trim((string) $rawSystem));
        return in_array($normalized, ['US', 'UK', 'EU', 'AU', 'CN'], true) ? $normalized : 'US';
    }

    private function parseSizeComponents(?string $rawSize): array
    {
        $normalizedRaw = trim((string) $rawSize);
        if ($normalizedRaw === '') {
            return ['system' => 'US', 'value' => '', 'explicit_system' => false];
        }

        if (preg_match('/^(US|UK|EU|AU|CN)\s*[:\-]?\s*(.+)$/i', $normalizedRaw, $matches)) {
            return [
                'system' => $this->normalizeSizeSystem($matches[1] ?? null),
                'value' => trim((string) ($matches[2] ?? '')),
                'explicit_system' => true,
            ];
        }

        return ['system' => 'US', 'value' => $normalizedRaw, 'explicit_system' => false];
    }

    private function resolveInventorySizeRowForRestock(int $inventoryItemId, ?int $inventoryColorVariantId, ?string $rawSize): ?InventorySize
    {
        $parsed = $this->parseSizeComponents($rawSize);
        $sizeValue = trim((string) ($parsed['value'] ?? ''));
        $sizeSystem = (string) ($parsed['system'] ?? 'US');
        $hasExplicitSystem = (bool) ($parsed['explicit_system'] ?? false);

        if ($sizeValue === '') {
            return null;
        }

        $query = InventorySize::where('inventory_item_id', $inventoryItemId)
            ->where('size', $sizeValue);

        if ($inventoryColorVariantId) {
            $query->where('inventory_color_variant_id', $inventoryColorVariantId);
        } else {
            $query->whereNull('inventory_color_variant_id');
        }

        if ($hasExplicitSystem) {
            $preferred = (clone $query)
                ->where('size_system', $sizeSystem)
                ->lockForUpdate()
                ->first();

            if ($preferred) {
                return $preferred;
            }
        }

        return $query->orderByRaw("CASE WHEN size_system = 'US' THEN 0 ELSE 1 END")
            ->lockForUpdate()
            ->first();
    }

    private function restoreInventoryForCancelledItem(object $item, int $orderId, int $performedBy): void
    {
        if (empty($item->product_id) || empty($item->quantity)) {
            return;
        }

        $qty = (int) $item->quantity;
        if ($qty <= 0) {
            return;
        }

        $product = Product::query()->lockForUpdate()->find((int) $item->product_id);
        if (!$product) {
            return;
        }

        $inventoryItem = InventoryItem::where('product_id', $product->id)
            ->lockForUpdate()
            ->first();

        if (!$inventoryItem) {
            $product->increment('stock_quantity', $qty);

            if (!empty($item->size) && !empty($item->color)) {
                $variant = ProductVariant::query()
                    ->where('product_id', $item->product_id)
                    ->whereRaw('LOWER(color) = ?', [strtolower(trim((string) $item->color))])
                    ->where('size', $item->size)
                    ->lockForUpdate()
                    ->first();

                if ($variant) {
                    $variant->increment('quantity', $qty);
                }
            }

            return;
        }

        $quantityBefore = (int) $inventoryItem->available_quantity;
        $didSpecificRestock = false;

        if (!empty($item->size) && !empty($item->color)) {
            $normalizedColor = strtolower(trim((string) $item->color));

            $inventoryColorVariant = InventoryColorVariant::where('inventory_item_id', $inventoryItem->id)
                ->whereRaw('LOWER(color_name) = ?', [$normalizedColor])
                ->lockForUpdate()
                ->first();

            $sizeRow = $this->resolveInventorySizeRowForRestock(
                (int) $inventoryItem->id,
                $inventoryColorVariant?->id,
                (string) $item->size
            );

            if ($sizeRow) {
                $sizeRow->increment('quantity', $qty);
                $didSpecificRestock = true;
            }

            if ($inventoryColorVariant) {
                if ($sizeRow) {
                    $recomputedColorQty = (int) InventorySize::where('inventory_item_id', $inventoryItem->id)
                        ->where('inventory_color_variant_id', $inventoryColorVariant->id)
                        ->sum('quantity');

                    $inventoryColorVariant->quantity = $recomputedColorQty;
                    $inventoryColorVariant->save();
                } else {
                    $inventoryColorVariant->increment('quantity', $qty);
                }
                $didSpecificRestock = true;
            }
        }

        $newTotalQty = (int) InventoryColorVariant::where('inventory_item_id', $inventoryItem->id)
            ->sum('quantity');

        if ($newTotalQty === 0) {
            $newTotalQty = (int) InventorySize::where('inventory_item_id', $inventoryItem->id)
                ->whereNull('inventory_color_variant_id')
                ->sum('quantity');
        }

        if (!$didSpecificRestock) {
            $newTotalQty = $quantityBefore + $qty;
        }

        $inventoryItem->available_quantity = $newTotalQty;
        $inventoryItem->save();

        StockMovement::create([
            'inventory_item_id' => $inventoryItem->id,
            'movement_type' => 'stock_in',
            'quantity_change' => $qty,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $newTotalQty,
            'reference_type' => 'order',
            'reference_id' => $orderId,
            'notes' => 'Order cancellation restock',
            'performed_by' => $performedBy,
            'performed_at' => now(),
        ]);

        $product->stock_quantity = $newTotalQty;
        $product->save();
    }
    
    /**
     * Cancel order
     */
    public function cancel(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|integer',
            'order_item_id' => 'nullable|integer',
            'reason' => 'nullable|string|max:500',
            'note' => 'nullable|string|max:1000',
        ]);

        $user = Auth::guard('user')->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            $order = Order::where('id', $validated['order_id'])
                ->where('customer_id', $user->id)
                ->with('items')
                ->lockForUpdate()
                ->firstOrFail();

            // Only allow cancellation of pending orders
            if (!in_array($order->status, [OrderStatus::PENDING])) {
                if ($order->status === OrderStatus::CANCELLED) {
                    \Illuminate\Support\Facades\DB::commit();

                    $alreadyRefunded = (string) ($order->payment_status ?? '') === 'refunded';
                    $refundInProgress = !empty($order->paymongo_refund_id) && !$alreadyRefunded;

                    $message = 'Order is already cancelled.';
                    if ($alreadyRefunded) {
                        $message = 'Order is already cancelled and refunded to your original e-wallet/payment method.';
                    } elseif ($refundInProgress) {
                        $message = 'Order is already cancelled. Your refund is already being processed to your original e-wallet/payment method.';
                    }

                    return response()->json([
                        'success' => true,
                        'message' => $message,
                        'order_cancelled' => true,
                        'refund_required' => $alreadyRefunded || $refundInProgress,
                        'refund_status' => $alreadyRefunded ? 'already_refunded' : ($refundInProgress ? 'already_processing' : null),
                    ]);
                }

                \Illuminate\Support\Facades\DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending orders can be cancelled.',
                ], 400);
            }

            $paymentMethod = strtolower((string) ($order->payment_method ?? ''));
            $isOnlinePayment = !in_array($paymentMethod, ['cod', 'cash_on_delivery', 'cash on delivery'], true);
            $isPaidOnlineOrder = $isOnlinePayment && in_array((string) ($order->payment_status ?? 'pending'), ['paid', 'completed'], true);

            if ($isPaidOnlineOrder && !empty($validated['order_item_id'])) {
                \Illuminate\Support\Facades\DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Paid online orders support full-order cancellation only for refund processing.',
                ], 422);
            }

            if (!empty($validated['order_item_id'])) {
                $item = $order->items->firstWhere('id', (int) $validated['order_item_id']);

                if (!$item) {
                    \Illuminate\Support\Facades\DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Selected order item was not found for this order',
                    ], 404);
                }

                $this->restoreInventoryForCancelledItem($item, (int) $order->id, (int) $user->id);

                $item->delete();

                $remainingCount = $order->items()->count();
                $remainingTotal = (float) $order->items()->sum('subtotal');

                $order->total_amount = $remainingTotal;
                if ($remainingCount === 0) {
                    $order->status = OrderStatus::CANCELLED;
                }
                $order->save();

                \Illuminate\Support\Facades\DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => $remainingCount > 0
                        ? 'Order item cancelled successfully. Remaining items are still active.'
                        : 'Order cancelled successfully. Inventory has been restored.',
                    'order_cancelled' => $remainingCount === 0,
                ]);
            }

            // Restore inventory for each item
            foreach ($order->items as $item) {
                $this->restoreInventoryForCancelledItem($item, (int) $order->id, (int) $user->id);
            }

            if ($isPaidOnlineOrder) {
                $refundResult = $this->orderRefundService->autoRefundOnCancellation(
                    order: $order,
                    reason: $validated['reason'] ?? null,
                    note: $validated['note'] ?? null,
                );

                if (($refundResult['result'] ?? null) === 'failed') {
                    \Illuminate\Support\Facades\DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => $refundResult['message'] ?? 'Unable to process refund for this cancellation.',
                    ], 422);
                }

                $refundOutcome = (string) ($refundResult['result'] ?? 'processing');

                $refundMessage = match ($refundOutcome) {
                    'refunded' => 'Order cancelled successfully. Your payment has been refunded to your original e-wallet/payment method.',
                    'already_refunded' => 'Order cancelled successfully. This payment was already refunded to your original e-wallet/payment method.',
                    'already_processing', 'processing' => 'Order cancelled successfully. Your refund is being processed and will be returned to your original e-wallet/payment method once completed.',
                    default => 'Order cancelled successfully. Refund processing has been initiated to your original e-wallet/payment method.',
                };

                $order->status = OrderStatus::CANCELLED;
                $order->save();

                \Illuminate\Support\Facades\DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => $refundMessage,
                    'refund_required' => true,
                    'refund_status' => $refundOutcome,
                ]);
            }

            $order->status = OrderStatus::CANCELLED;
            $order->save();

            \Illuminate\Support\Facades\DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order cancelled successfully. Inventory has been restored.',
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            \Illuminate\Support\Facades\Log::error('Order cancellation failed', [
                'error' => $e->getMessage(),
                'order_id' => $validated['order_id'],
                'order_item_id' => $validated['order_item_id'] ?? null,
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel order. Please try again.',
            ], 500);
        }
    }
}
