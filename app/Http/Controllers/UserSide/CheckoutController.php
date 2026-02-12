<?php

namespace App\Http\Controllers\UserSide;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Finance\Invoice;
use App\Models\Finance\InvoiceItem;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CheckoutController extends Controller
{
    /**
     * Create order from cart items
     */
    public function createOrder(Request $request)
    {
        try {
            $validated = $request->validate([
                'items' => 'required|array|min:1',
                'items.*.id' => 'required',
                'items.*.pid' => 'required|integer',
                'items.*.qty' => 'required|integer|min:1',
                'items.*.name' => 'required|string',
                'items.*.price' => 'required|numeric|min:0',
                'items.*.size' => 'nullable|string',
                'items.*.color' => 'nullable|string',
                'items.*.image' => 'nullable|string',
                'items.*.options' => 'nullable',
                'total_amount' => 'required|numeric|min:0',
                'customer_name' => 'required|string|max:255',
                'customer_email' => 'required|email|max:255',
                'customer_phone' => 'nullable|string|max:20',
                'shipping_address' => 'required|string|max:500',
                'payment_method' => 'nullable|string|max:50',
                // Structured address fields
                'address_id' => 'nullable|integer|exists:user_addresses,id',
                'shipping_region' => 'nullable|string|max:100',
                'shipping_province' => 'nullable|string|max:100',
                'shipping_city' => 'nullable|string|max:100',
                'shipping_barangay' => 'nullable|string|max:100',
                'shipping_postal_code' => 'nullable|string|max:10',
                'shipping_address_line' => 'nullable|string|max:255',
            ]);

            // Get authenticated user
            $user = Auth::guard('user')->user();
            $customerId = $user ? $user->id : null;

            // Group items by shop owner (products from same shop go to same order)
            $itemsByShop = [];
            foreach ($validated['items'] as $item) {
                // Log the item data for debugging
                Log::info('Processing checkout item', [
                    'item' => $item,
                    'pid_value' => $item['pid'] ?? 'NOT SET',
                    'pid_type' => gettype($item['pid'] ?? null),
                ]);
                
                $product = Product::find($item['pid']);
                if (!$product) {
                    Log::error('Product not found during checkout', [
                        'item' => $item,
                        'pid' => $item['pid'],
                        'all_products' => Product::pluck('id')->toArray(),
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => "Product not found: {$item['name']} (ID: {$item['pid']})",
                    ], 404);
                }

                // Extract variant details from cart item
                $options = isset($item['options']) ? (is_string($item['options']) ? json_decode($item['options'], true) : $item['options']) : [];
                $itemSize = $item['size'] ?? null;
                // Try to get color from direct field first, then from options
                $itemColor = $item['color'] ?? $options['color'] ?? null;
                
                // LOG: Check what we're extracting with FULL item dump
                Log::info('Checkout - Extracting variant details for stock check', [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'FULL_ITEM_DATA' => $item, // Log entire item to see everything
                    'item_array_keys' => array_keys($item),
                    'has_color_field' => isset($item['color']),
                    'color_value' => $item['color'] ?? 'NOT SET',
                    'has_options' => isset($item['options']),
                    'options' => $options,
                    'extracted_size' => $itemSize,
                    'extracted_color' => $itemColor,
                ]);

                // Check variant-specific stock availability
                if ($itemSize && $itemColor) {
                    $variant = ProductVariant::where('product_id', $product->id)
                        ->where('size', $itemSize)
                        ->where('color', $itemColor)
                        ->first();

                    if (!$variant) {
                        return response()->json([
                            'success' => false,
                            'message' => "Variant not found for {$product->name} (Size {$itemSize}, Color {$itemColor})",
                        ], 404);
                    }

                    if ($variant->quantity < $item['qty']) {
                        return response()->json([
                            'success' => false,
                            'message' => "Insufficient stock for {$product->name} (Size {$itemSize}, Color {$itemColor}). Available: {$variant->quantity}",
                        ], 400);
                    }
                } else {
                    // Fallback to product-level stock check if no variant specified
                    if ($product->stock_quantity < $item['qty']) {
                        return response()->json([
                            'success' => false,
                            'message' => "Insufficient stock for {$product->name}. Available: {$product->stock_quantity}",
                        ], 400);
                    }
                }

                $shopOwnerId = $product->shop_owner_id;
                if (!isset($itemsByShop[$shopOwnerId])) {
                    $itemsByShop[$shopOwnerId] = [];
                }
                $itemsByShop[$shopOwnerId][] = [
                    'item' => $item,
                    'product' => $product,
                ];
            }

            $createdOrders = [];

            DB::beginTransaction();

            try {
                // Create separate order for each shop owner
                foreach ($itemsByShop as $shopOwnerId => $shopItems) {
                    $orderTotal = 0;

                    // Create the order
                    $order = Order::create([
                        'shop_owner_id' => $shopOwnerId,
                        'customer_id' => $customerId,
                        'order_number' => Order::generateOrderNumber(),
                        'total_amount' => 0, // Will update after items
                        'status' => 'pending',
                        'customer_name' => $validated['customer_name'],
                        'customer_email' => $validated['customer_email'],
                        'customer_phone' => $validated['customer_phone'] ?? null,
                        'customer_address' => $validated['shipping_address'],
                        'payment_method' => $validated['payment_method'] ?? 'paymongo',
                        'payment_status' => 'pending',
                        // Store structured address data
                        'address_id' => $validated['address_id'] ?? null,
                        'shipping_region' => $validated['shipping_region'] ?? null,
                        'shipping_province' => $validated['shipping_province'] ?? null,
                        'shipping_city' => $validated['shipping_city'] ?? null,
                        'shipping_barangay' => $validated['shipping_barangay'] ?? null,
                        'shipping_postal_code' => $validated['shipping_postal_code'] ?? null,
                        'shipping_address_line' => $validated['shipping_address_line'] ?? null,
                    ]);

                    // Create order items and reduce stock
                    foreach ($shopItems as $shopItem) {
                        $item = $shopItem['item'];
                        $product = $shopItem['product'];

                        $subtotal = $item['price'] * $item['qty'];
                        $orderTotal += $subtotal;

                        // Extract options for color and image
                        $options = isset($item['options']) ? (is_string($item['options']) ? json_decode($item['options'], true) : $item['options']) : [];
                        $itemSize = $item['size'] ?? null;
                        // Try to get color from direct field first, then from options
                        $itemColor = $item['color'] ?? $options['color'] ?? null;
                        $itemImage = $options['image'] ?? $item['image'] ?? $product->main_image;

                        Log::info('Processing order item', [
                            'product_id' => $product->id,
                            'product_name' => $product->name,
                            'item_data' => $item,
                            'extracted_size' => $itemSize,
                            'extracted_color' => $itemColor,
                            'has_options' => isset($item['options']),
                            'options' => $options,
                        ]);

                        // LOG: What we're saving to order_items
                        Log::info('Checkout - Creating order_item', [
                            'order_id' => $order->id,
                            'product_id' => $product->id,
                            'size_to_save' => $itemSize,
                            'color_to_save' => $itemColor,
                            'image_to_save' => $itemImage,
                        ]);

                        OrderItem::create([
                            'order_id' => $order->id,
                            'product_id' => $product->id,
                            'product_name' => $product->name,
                            'product_slug' => $product->slug,
                            'price' => $item['price'],
                            'quantity' => $item['qty'],
                            'subtotal' => $subtotal,
                            'size' => $itemSize,
                            'color' => $itemColor,
                            'product_image' => $itemImage,
                        ]);

                        // Reduce variant-specific stock quantity
                        if ($itemSize && $itemColor) {
                            Log::info('Checkout - Looking for variant to decrement', [
                                'product_id' => $product->id,
                                'size' => $itemSize,
                                'color' => $itemColor,
                                'qty_to_reduce' => $item['qty']
                            ]);
                            
                            $variant = ProductVariant::where('product_id', $product->id)
                                ->where('size', $itemSize)
                                ->where('color', $itemColor)
                                ->first();

                            if ($variant) {
                                Log::info('Checkout - Variant FOUND, decrementing now', [
                                    'variant_id' => $variant->id,
                                    'before_quantity' => $variant->quantity,
                                    'reducing_by' => $item['qty']
                                ]);
                                
                                $variant->decrement('quantity', $item['qty']);
                                
                                // Refresh to get updated value
                                $variant->refresh();
                                
                                Log::info('Variant stock decremented', [
                                    'product_id' => $product->id,
                                    'variant_id' => $variant->id,
                                    'size' => $itemSize,
                                    'color' => $itemColor,
                                    'quantity_reduced' => $item['qty'],
                                    'remaining' => $variant->quantity,
                                ]);
                            } else {
                                Log::warning('Variant NOT FOUND for stock deduction', [
                                    'product_id' => $product->id,
                                    'size' => $itemSize,
                                    'color' => $itemColor,
                                    'searched_product_id' => $product->id,
                                ]);
                            }
                        } else {
                            Log::warning('Missing size or color for variant stock deduction', [
                                'product_id' => $product->id,
                                'product_name' => $product->name,
                                'size' => $itemSize,
                                'color' => $itemColor,
                                'has_size' => !empty($itemSize),
                                'has_color' => !empty($itemColor),
                            ]);
                        }

                        // Also reduce total product stock quantity
                        $product->decrement('stock_quantity', $item['qty']);
                    }

                    // Update order total
                    $order->update(['total_amount' => $orderTotal]);

                    $createdOrders[] = [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'total' => $orderTotal,
                        'items_count' => count($shopItems),
                    ];

                    Log::info('Order created', [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'shop_owner_id' => $shopOwnerId,
                        'customer_id' => $customerId,
                        'total' => $orderTotal,
                    ]);
                }

                DB::commit();

                // Auto-generate invoices AFTER successful order creation (optional feature)
                foreach ($createdOrders as $createdOrderData) {
                    try {
                        $order = Order::with('items')->find($createdOrderData['id']);
                        if ($order) {
                            $this->autoGenerateInvoice($order);
                        }
                    } catch (\Exception $e) {
                        // Log but don't fail - invoice generation is optional
                        Log::warning('Failed to auto-generate invoice for order', [
                            'order_id' => $createdOrderData['id'],
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Clear user's cart after successful order
                if ($customerId) {
                    \App\Models\CartItem::where('user_id', $customerId)->delete();
                    Log::info('Cart cleared after order', ['user_id' => $customerId]);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Order(s) created successfully',
                    'orders' => $createdOrders,
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('Order creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create order: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get customer orders
     */
    public function myOrders()
    {
        try {
            $user = Auth::guard('user')->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 401);
            }

            $orders = Order::where('customer_id', $user->id)
                ->with(['items.product', 'shopOwner'])
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($order) {
                    return [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'status' => $order->status,
                        'total_amount' => $order->total_amount,
                        'created_at' => $order->created_at->format('Y-m-d H:i:s'),
                        'shop_name' => $order->shopOwner->business_name ?? 'Unknown Shop',
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
                            ];
                        }),
                    ];
                });

            return response()->json([
                'success' => true,
                'orders' => $orders,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch orders', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch orders',
            ], 500);
        }
    }

    /**
     * Update order with PayMongo link ID
     */
    public function updatePaymentLink(Request $request, $orderId)
    {
        try {
            $validated = $request->validate([
                'paymongo_link_id' => 'required|string',
            ]);

            $order = Order::findOrFail($orderId);
            
            $order->update([
                'paymongo_link_id' => $validated['paymongo_link_id'],
            ]);

            Log::info('Order updated with PayMongo link ID', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'link_id' => $validated['paymongo_link_id'],
            ]);

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Failed to update payment link', [
                'error' => $e->getMessage(),
                'order_id' => $orderId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update payment link',
            ], 500);
        }
    }

    /**
     * Get order details for confirmation page
     */
    public function getOrderDetails($orderId)
    {
        try {
            $order = Order::find($orderId);

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'order' => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'total_amount' => $order->total_amount,
                    'status' => $order->status,
                    'payment_status' => $order->payment_status,
                    'payment_method' => $order->payment_method,
                    'created_at' => $order->created_at->format('M d, Y'),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch order details', [
                'error' => $e->getMessage(),
                'order_id' => $orderId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch order details',
            ], 500);
        }
    }

    /**
     * Auto-generate invoice for an order based on payment method
     */
    protected function autoGenerateInvoice(Order $order): ?Invoice
    {
        // Don't create duplicate invoices
        if ($order->invoice_generated || $order->invoice_id) {
            return $order->invoice;
        }

        try {
            // Generate invoice reference
            $prefix = 'INV';
            $date = now()->format('Ymd');
            $random = strtoupper(substr(uniqid(), -4));
            $reference = "{$prefix}-{$date}-{$random}";

            // Determine invoice status based on payment method
            $paymentMethod = strtolower($order->payment_method ?? 'paymongo');
            
            // Online payment methods - mark as paid immediately
            $onlinePaymentMethods = [
                'paymongo', 'paypal', 'stripe', 'gcash', 'maya',
                'online', 'card', 'credit_card', 'debit_card', 'bank_transfer'
            ];

            $invoiceStatus = 'sent'; // default
            $paymentDate = null;
            $dueDate = now()->addDays(15);

            if (in_array($paymentMethod, $onlinePaymentMethods)) {
                $invoiceStatus = 'paid';
                $paymentDate = now();
                $dueDate = null;
            } elseif (in_array($paymentMethod, ['cod', 'cash_on_delivery', 'cash'])) {
                $invoiceStatus = 'sent';
                $dueDate = now()->addDays(7); // COD: due on delivery
            } elseif ($paymentMethod === 'check') {
                $invoiceStatus = 'sent';
                $dueDate = now()->addDays(30);
            }

            // Calculate tax (12% VAT)
            $baseAmount = $order->total_amount / 1.12;
            $taxAmount = round($order->total_amount - $baseAmount, 2);

            // Create the invoice
            $invoice = Invoice::create([
                'shop_id' => $order->shop_owner_id,
                'reference' => $reference,
                'customer_name' => $order->customer_name,
                'customer_email' => $order->customer_email,
                'date' => now(),
                'due_date' => $dueDate,
                'total' => $order->total_amount,
                'tax_amount' => $taxAmount,
                'status' => $invoiceStatus,
                'payment_date' => $paymentDate,
                'payment_method' => in_array($paymentMethod, ['cod', 'cash_on_delivery']) ? 'cod' : $paymentMethod,
                'job_order_id' => $order->id,
                'notes' => "Auto-generated from Order #{$order->order_number}",
            ]);

            // Get default revenue account (if finance module is set up)
            $revenueAccount = null;
            // Account model not yet implemented - leave account_id as null for now

            // Create invoice items from order items
            if ($order->relationLoaded('items') && $order->items->count() > 0) {
                foreach ($order->items as $orderItem) {
                    InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'description' => $orderItem->product_name . 
                            ($orderItem->size ? " (Size: {$orderItem->size})" : '') .
                            ($orderItem->color ? " (Color: {$orderItem->color})" : ''),
                        'quantity' => $orderItem->quantity,
                        'unit_price' => $orderItem->price,
                        'tax_rate' => 12.00,
                        'amount' => $orderItem->subtotal,
                        'account_id' => $revenueAccount?->id,
                    ]);
                }
            }

            // Update order
            $order->update([
                'invoice_generated' => true,
                'invoice_id' => $invoice->id,
            ]);

            // Audit log
            AuditLog::create([
                'shop_owner_id' => $order->shop_owner_id,
                'action' => 'auto_generate_invoice',
                'target_type' => 'invoice',
                'target_id' => $invoice->id,
                'metadata' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'invoice_reference' => $reference,
                    'payment_method' => $order->payment_method,
                    'invoice_status' => $invoiceStatus,
                ]
            ]);

            Log::info('Auto-generated invoice for order', [
                'order_id' => $order->id,
                'invoice_id' => $invoice->id,
                'invoice_reference' => $reference,
                'status' => $invoiceStatus,
            ]);

            return $invoice;

        } catch (\Exception $e) {
            Log::error('Failed to auto-generate invoice', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
