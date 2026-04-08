<?php

/**
 * Core API Routes
 * 
 * Purpose: Common API endpoints for authentication, payments, and core features
 * 
 * Note: Module-specific routes are in separate files:
 * - routes/hr-api.php          (HR module)
 * - routes/finance-api.php     (Finance module)
 * - routes/shop-owner-api.php  (Shop Owner module)
 */

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FinancialReportController;
// use App\Http\Controllers\Api\Finance\BudgetController;
use App\Http\Controllers\Api\PriceChangeRequestController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:web');

/**
 * PayMongo Webhook - Must be accessible without authentication
 */
Route::post('/webhooks/paymongo', [\App\Http\Controllers\PaymongoWebhookController::class, 'handle']);

/**
 * PayMongo Proxy - Frontend calls this to avoid CORS
 * Uses payment links API (the one that was working for you last week)
 */
Route::middleware(['web', 'auth:user', 'throttle:10,1'])->post('/paymongo-proxy', function (Request $request) {
    try {
        $validated = $request->validate([
            'order_id' => ['nullable', 'integer', 'required_without:repair_request_id'],
            'repair_request_id' => ['nullable', 'integer', 'required_without:order_id'],
        ]);

        $customer = Auth::guard('user')->user();
        if (!$customer) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // --- Resolve the correct PayMongo key ---
        // Shop payments must ALWAYS use the shop's own key.
        // The platform key (.env) is reserved for platform-level payments (e.g. premium subscriptions).
        // If no shop key is configured, we block the payment — no silent fallback.
        $apiKey = null;
        $amount = 0;
        $description = 'SoleSpace Purchase';
        $lineItems = [[
            'currency' => 'PHP',
            'amount' => 0,
            'name' => 'SoleSpace Purchase',
            'quantity' => 1,
        ]];
        $successUrl = url('/order-success') . '?paymongo_success=1';
        $failedUrl = url('/order-success') . '?paymongo_failed=1';

        if (!empty($validated['order_id'])) {
            $order = \App\Models\Order::with('shopOwner')
                ->where('id', (int) $validated['order_id'])
                ->where('customer_id', (int) $customer->id)
                ->first();

            if (!$order) {
                return response()->json(['error' => 'Order not found'], 404);
            }

            if (in_array((string) $order->payment_status, ['paid', 'completed'], true)) {
                return response()->json(['error' => 'Order is already paid'], 409);
            }

            $apiKey = $order->shopOwner?->paymongo_secret_key;
            $itemSubtotal = max(0.0, (float) $order->total_amount);
            $shippingFee = max(0.0, (float) ($order->shipping_fee ?? 0));
            $vatAmount = $order->vat_amount !== null ? max(0.0, (float) $order->vat_amount) : 0.0;
            $amount = $itemSubtotal + $shippingFee + $vatAmount;
            $description = 'SoleSpace Order #' . $order->order_number;

            $lineItems = [];
            if ($itemSubtotal > 0) {
                $lineItems[] = [
                    'currency' => 'PHP',
                    'amount' => (int) round($itemSubtotal * 100),
                    'name' => 'Product Subtotal',
                    'quantity' => 1,
                ];
            }
            if ($shippingFee > 0) {
                $lineItems[] = [
                    'currency' => 'PHP',
                    'amount' => (int) round($shippingFee * 100),
                    'name' => 'Shipping Fee',
                    'quantity' => 1,
                ];
            }
            if ($vatAmount > 0) {
                $lineItems[] = [
                    'currency' => 'PHP',
                    'amount' => (int) round($vatAmount * 100),
                    'name' => 'VAT (12%)',
                    'quantity' => 1,
                ];
            }
            if (empty($lineItems)) {
                $lineItems[] = [
                    'currency' => 'PHP',
                    'amount' => (int) round($amount * 100),
                    'name' => $description,
                    'quantity' => 1,
                ];
            }
        } else {
            $repair = \App\Models\RepairRequest::with('shopOwner')
                ->where('id', (int) $validated['repair_request_id'])
                ->where('user_id', (int) $customer->id)
                ->first();

            if (!$repair) {
                return response()->json(['error' => 'Repair request not found'], 404);
            }

            if ((string) $repair->payment_status === 'completed') {
                return response()->json(['error' => 'Repair is already fully paid'], 409);
            }

            $apiKey = $repair->shopOwner?->paymongo_secret_key;

            $chargeTotal = (float) ($repair->final_total ?? $repair->total ?? 0);
            if ($chargeTotal <= 0) {
                $chargeTotal = (float) (($repair->package_price ?? 0) + ($repair->add_ons_total ?? 0));
            }

            $policy = strtolower((string) ($repair->payment_policy ?? 'deposit_50'));
            if ($policy !== 'deposit_50') {
                $policy = 'full_upfront';
            }
            $isRemainingBalancePhase = (string) $repair->status === 'ready_for_pickup';
            if ($policy === 'full_upfront') {
                $amount = $chargeTotal;
                $phase = 'full payment';
            } else {
                $amount = max(1.0, round($chargeTotal / 2, 2));
                $phase = $isRemainingBalancePhase ? 'remaining balance' : 'down payment';
            }

            $description = 'SoleSpace Repair #' . ($repair->request_id ?: $repair->id) . ' (' . $phase . ')';
            $lineItems = [[
                'currency' => 'PHP',
                'amount' => (int) round($amount * 100),
                'name' => $description,
                'quantity' => 1,
            ]];
            $successUrl = url('/my-repairs') . '?paymongo_success=1';
            $failedUrl = url('/my-repairs') . '?paymongo_failed=1';
        }

        // Hard block: shop must have their own key configured
        if (!$apiKey) {
            return response()->json([
                'error'   => 'shop_payment_not_configured',
                'message' => 'This shop has not set up payment processing yet. Please contact the shop owner.',
            ], 503);
        }

        if ($amount <= 0) {
            return response()->json(['error' => 'Invalid payable amount'], 422);
        }
        // --- End key resolution ---
        
        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($apiKey . ':'),
        ])->post('https://api.paymongo.com/v1/checkout_sessions', [
            'data' => [
                'attributes' => [
                    'success_url'        => $successUrl,
                    'cancel_url'         => $failedUrl,
                    'description'        => $description,
                    'send_email_receipt' => false,
                    'show_description'   => true,
                    'show_line_items'    => true,
                    'line_items' => $lineItems,
                    'payment_method_types' => ['card', 'gcash', 'paymaya', 'grab_pay'],
                ],
            ],
        ]);

        if ($response->failed()) {
            $errorMsg = $response->json('message') ?? $response->json('error') ?? 'PayMongo API failed';
            $errors = $response->json('errors');
            
            // Log detailed error for debugging
            \Illuminate\Support\Facades\Log::error('PayMongo API Error', [
                'status' => $response->status(),
                'message' => $errorMsg,
                'errors' => $errors,
                'response' => $response->json(),
            ]);
            
            return response()->json([
                'error' => 'PayMongo Error: ' . ($errors[0]['detail'] ?? $errorMsg ?? 'Unknown error')
            ], $response->status());
        }

        $data = $response->json();
        $checkoutUrl = $data['data']['attributes']['checkout_url'] ?? null;
        $linkId = $data['data']['id'] ?? null;

        if (!$checkoutUrl || !$linkId) {
            \Illuminate\Support\Facades\Log::error('Missing data in PayMongo response', ['response' => $data]);
            return response()->json(['error' => 'Incomplete PayMongo response'], 500);
        }

        return response()->json([
            'checkout_url' => $checkoutUrl,
            'link_id' => $linkId,
        ]);
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error('PayMongo Proxy Exception', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        return response()->json(['error' => 'Server error while creating payment session'], 500);
    }
});

Route::middleware(['web', 'auth:user,shop_owner'])->prefix('repair-pos')->group(function () {
    Route::post('/checkout', [\App\Http\Controllers\Api\RepairPosController::class, 'checkout']);
    Route::get('/manual-queue', [\App\Http\Controllers\Api\RepairPosController::class, 'listManualQueue']);
    Route::patch('/manual-queue/{repairId}/status', [\App\Http\Controllers\Api\RepairPosController::class, 'updateManualQueueStatus']);
    Route::get('/transactions', [\App\Http\Controllers\Api\RepairPosController::class, 'listTransactions']);
    Route::post('/payment-lines/{line}/verify', [\App\Http\Controllers\Api\RepairPosController::class, 'verifyPaymentLine']);
    Route::post('/refunds', [\App\Http\Controllers\Api\RepairPosController::class, 'requestRefund']);
    Route::get('/refunds/mine', [\App\Http\Controllers\Api\RepairPosController::class, 'listMyRefunds']);
    Route::get('/refunds/queue', [\App\Http\Controllers\Api\RepairPosController::class, 'listRefundQueue']);
    Route::post('/refunds/{refund}/approve', [\App\Http\Controllers\Api\RepairPosController::class, 'approveRefund']);
    Route::post('/refunds/{refund}/reject', [\App\Http\Controllers\Api\RepairPosController::class, 'rejectRefund']);
    Route::post('/refunds/{refund}/execute', [\App\Http\Controllers\Api\RepairPosController::class, 'executeRefund']);
    Route::get('/transactions/{transaction}', [\App\Http\Controllers\Api\RepairPosController::class, 'showTransaction']);
    Route::get('/transactions/{transaction}/receipt', [\App\Http\Controllers\Api\RepairPosController::class, 'showReceipt']);
});

Route::middleware(['web', 'auth:user,shop_owner'])->prefix('retail-pos')->group(function () {
    Route::get('/products', [\App\Http\Controllers\Api\RetailPosController::class, 'listProducts']);
    Route::post('/checkout', [\App\Http\Controllers\Api\RetailPosController::class, 'checkout']);
    Route::get('/history', [\App\Http\Controllers\Api\RetailPosController::class, 'history']);
    Route::get('/orders/{order}/receipt', [\App\Http\Controllers\Api\RetailPosController::class, 'receipt']);
});

/**
 * Price Change Requests - Staff endpoints
 * MOVED TO web.php for proper session handling
 * Using web.php ensures session persistence across navigation
 */
// Route::middleware(['web', 'auth:user'])->prefix('price-change-requests')->group(function () {
//     Route::get('/my-pending', [PriceChangeRequestController::class, 'myPending']);
// });

// Debug endpoint to check current user (disabled in production)
if (!app()->environment('production')) {
    Route::get('/debug/me', function () {
        $user = Auth::guard('web')->user() ?? Auth::guard('user')->user();
        if (!$user) {
            return response()->json(['error' => 'Not authenticated']);
        }
        return response()->json([
            'id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'shop_owner_id' => $user->shop_owner_id,
        ]);
    })->middleware('web');
}

/**
 * Legacy Finance Routes (to be migrated to finance-api.php)
 * These are kept for backward compatibility
 * TODO: Move to finance-api.php and update frontend to use new endpoints
 */
Route::prefix('finance/public')->group(function () {
    // Route::get('budgets', [BudgetController::class, 'index']);
});

/**
 * Legacy Finance Module Routes (for backward compatibility)
 * Protected by session-based authentication and role-based middleware
 * TODO: Migrate frontend to use routes/finance-api.php
 */
Route::middleware(['web', 'auth:web,user', 'old_role:Finance Staff,Finance Manager,Manager,Staff', 'shop.isolation'])->prefix('finance')->group(function () {
    // Financial Reports
    Route::prefix('reports')->group(function () {
        Route::get('balance-sheet', [FinancialReportController::class, 'balanceSheet']);
        Route::get('profit-loss', [FinancialReportController::class, 'profitLoss']);
        Route::get('trial-balance', [FinancialReportController::class, 'trialBalance']);
        Route::get('ar-aging', [FinancialReportController::class, 'arAging']);
        Route::get('ap-aging', [FinancialReportController::class, 'apAging']);
    });

    // Invoices
    Route::get('invoices', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'index']);
    Route::post('invoices', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'store']);
    Route::get('invoices/{id}', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'show']);
    Route::put('invoices/{id}', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'update']);
    Route::delete('invoices/{id}', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'destroy']);
    Route::post('invoices/{id}/restore', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'restore']);
    Route::post('invoices/{id}/send', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'send']);
    Route::post('invoices/{id}/void', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'void']);

    // Expenses
    Route::get('expenses', [\App\Http\Controllers\Api\Finance\ExpenseController::class, 'index']);
    Route::post('expenses', [\App\Http\Controllers\Api\Finance\ExpenseController::class, 'store']);
    Route::get('expenses/{id}', [\App\Http\Controllers\Api\Finance\ExpenseController::class, 'show']);
    Route::put('expenses/{id}', [\App\Http\Controllers\Api\Finance\ExpenseController::class, 'update']);
    Route::delete('expenses/{id}', [\App\Http\Controllers\Api\Finance\ExpenseController::class, 'destroy']);
    Route::post('expenses/{id}/approve', [\App\Http\Controllers\Api\Finance\ExpenseController::class, 'approve'])->middleware('permission:approve-expenses');
    Route::post('expenses/{id}/reject', [\App\Http\Controllers\Api\Finance\ExpenseController::class, 'reject'])->middleware('permission:approve-expenses');

    // Expense Receipt Management
    Route::post('expenses/{id}/receipt', [\App\Http\Controllers\Api\Finance\ExpenseController::class, 'uploadReceipt']);
    Route::get('expenses/{id}/receipt/download', [\App\Http\Controllers\Api\Finance\ExpenseController::class, 'downloadReceipt']);
    Route::delete('expenses/{id}/receipt', [\App\Http\Controllers\Api\Finance\ExpenseController::class, 'deleteReceipt']);

    // Tax Rates Management
    Route::get('tax-rates', [\App\Http\Controllers\Api\Finance\TaxRateController::class, 'index']);
    Route::post('tax-rates', [\App\Http\Controllers\Api\Finance\TaxRateController::class, 'store']);
    Route::get('tax-rates/effective', [\App\Http\Controllers\Api\Finance\TaxRateController::class, 'effective']);
    Route::get('tax-rates/default', [\App\Http\Controllers\Api\Finance\TaxRateController::class, 'getDefault']);
    Route::post('tax-rates/calculate', [\App\Http\Controllers\Api\Finance\TaxRateController::class, 'calculate']);
    Route::get('tax-rates/{id}', [\App\Http\Controllers\Api\Finance\TaxRateController::class, 'show']);
    Route::put('tax-rates/{id}', [\App\Http\Controllers\Api\Finance\TaxRateController::class, 'update']);
    Route::delete('tax-rates/{id}', [\App\Http\Controllers\Api\Finance\TaxRateController::class, 'destroy']);

    // Approval Workflow routes
    Route::prefix('approvals')->group(function () {
        Route::get('pending', [\App\Http\Controllers\ApprovalController::class, 'getPending']);
        Route::get('history', [\App\Http\Controllers\ApprovalController::class, 'getHistory']);
        Route::get('{id}/history', [\App\Http\Controllers\ApprovalController::class, 'getApprovalHistory']);

        // Only users with approve-expenses permission can approve/reject transactions
        Route::middleware('permission:approve-expenses')->group(function () {
            Route::post('{id}/approve', [\App\Http\Controllers\ApprovalController::class, 'approve']);
            Route::post('{id}/reject', [\App\Http\Controllers\ApprovalController::class, 'reject']);
        });
    });
});

/**
 * Checkout and Order Management
 */
Route::post('/checkout/create-order', [\App\Http\Controllers\UserSide\CheckoutController::class, 'createOrder'])
    ->middleware(['web', 'auth:user']);
Route::post('/orders/{id}/update-payment-link', [\App\Http\Controllers\UserSide\CheckoutController::class, 'updatePaymentLink'])
    ->middleware(['web', 'auth:user', 'throttle:20,1']);
Route::post('/orders/{id}/verify-payment', [\App\Http\Controllers\UserSide\CheckoutController::class, 'verifyPayment'])
    ->middleware(['web', 'auth:user', 'throttle:20,1']);
Route::post('/orders/{id}/retry-payment-session', [\App\Http\Controllers\UserSide\CheckoutController::class, 'retryPaymentSession'])
    ->middleware(['web', 'auth:user', 'throttle:20,1']);
Route::get('/orders/{id}/details', [\App\Http\Controllers\UserSide\CheckoutController::class, 'getOrderDetails'])
    ->middleware(['web', 'auth:user', 'throttle:20,1']);
Route::post('/shipping/estimate', [\App\Http\Controllers\UserSide\ShippingEstimateController::class, 'estimate'])
    ->middleware(['throttle:120,1']);

/**
 * Staff/Manager Customer Management API
 */
Route::prefix('staff')->middleware(['web', 'auth:user'])->group(function () {
    Route::get('/customers', [\App\Http\Controllers\Api\Staff\CustomerController::class, 'index']);
    Route::get('/customers/stats', [\App\Http\Controllers\Api\Staff\CustomerController::class, 'stats']);
});

/**
 * Media Library Routes - Image Management
 */
Route::middleware(['web', 'auth:user'])->prefix('media')->group(function () {
    // Product images
    Route::post('products/{product}/main-image', [\App\Http\Controllers\MediaLibraryController::class, 'uploadProductMainImage']);
    Route::post('products/{product}/additional-images', [\App\Http\Controllers\MediaLibraryController::class, 'uploadProductAdditionalImages']);
    Route::get('products/{product}/images', [\App\Http\Controllers\MediaLibraryController::class, 'getProductImages']);
    
    // Color variant images
    Route::post('variants/{variant}/images', [\App\Http\Controllers\MediaLibraryController::class, 'uploadVariantImages']);
    Route::get('variants/{variant}/images', [\App\Http\Controllers\MediaLibraryController::class, 'getVariantImages']);
    
    // General operations
    Route::delete('files/{mediaId}', [\App\Http\Controllers\MediaLibraryController::class, 'deleteMedia']);
    Route::post('products/{product}/reorder', [\App\Http\Controllers\MediaLibraryController::class, 'reorderImages']);
    Route::get('files/{mediaId}/download', [\App\Http\Controllers\MediaLibraryController::class, 'downloadMedia']);
});

/**
 * CRM Conversation Routes - Shop staff managing customer conversations
 * NOTE: Customer conversation routes have been moved to routes/web.php for proper session handling
 */
Route::prefix('crm/conversations')->middleware(['web', 'auth:user', 'permission:access-customer-support'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\CRM\ConversationController::class, 'index']);
    Route::get('/{conversation}', [\App\Http\Controllers\Api\CRM\ConversationController::class, 'show']);
    Route::post('/{conversation}/messages', [\App\Http\Controllers\Api\CRM\ConversationController::class, 'sendMessage']);
    Route::post('/{conversation}/transfer', [\App\Http\Controllers\Api\CRM\ConversationController::class, 'transfer']);
    Route::patch('/{conversation}/status', [\App\Http\Controllers\Api\CRM\ConversationController::class, 'updateStatus']);
    Route::patch('/{conversation}/priority', [\App\Http\Controllers\Api\CRM\ConversationController::class, 'updatePriority']);
});

/**
 * CRM Customer Routes - Manage customer profiles, history and staff notes
 */
Route::prefix('crm/customers')->middleware(['web', 'auth:user', 'permission:access-customer-support'])->group(function () {
    Route::get('/',                  [\App\Http\Controllers\Api\CRM\CRMCustomerController::class, 'index']);
    Route::get('/{id}',              [\App\Http\Controllers\Api\CRM\CRMCustomerController::class, 'show']);
    Route::put('/{id}',              [\App\Http\Controllers\Api\CRM\CRMCustomerController::class, 'update']);
    Route::post('/{id}/notes',       [\App\Http\Controllers\Api\CRM\CRMCustomerController::class, 'storeNote']);
});

/**
 * CRM Review Routes - Manage customer reviews and staff responses
 */
Route::prefix('crm/reviews')->middleware(['web', 'auth:user', 'permission:access-customer-support'])->group(function () {
    Route::get('/',                  [\App\Http\Controllers\Api\CRM\CRMReviewController::class, 'index']);
});

/**
 * CRM Dashboard Stats - Aggregate data for the CRM overview page
 */
Route::get('crm/dashboard-stats', [\App\Http\Controllers\Api\CRM\CRMDashboardController::class, 'index'])
    ->middleware(['web', 'auth:user', 'permission:access-customer-support']);

/**
 * Repairer Conversation Routes - Repair technicians handling technical support
 */
Route::prefix('repairer/conversations')->middleware(['web', 'auth:user', 'permission:access-repairer-support'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Repairer\ConversationController::class, 'index']);
    Route::get('/{conversation}', [\App\Http\Controllers\Api\Repairer\ConversationController::class, 'show']);
    Route::post('/{conversation}/messages', [\App\Http\Controllers\Api\Repairer\ConversationController::class, 'sendMessage']);
    Route::post('/{conversation}/transfer', [\App\Http\Controllers\Api\Repairer\ConversationController::class, 'transfer']);
    Route::patch('/{conversation}/status', [\App\Http\Controllers\Api\Repairer\ConversationController::class, 'updateStatus']);
    Route::patch('/{conversation}/priority', [\App\Http\Controllers\Api\Repairer\ConversationController::class, 'updatePriority']);
    Route::post('/{conversation}/activate-payment', [\App\Http\Controllers\Api\Repairer\ConversationController::class, 'activatePayment']);
});

/**
 * Customer Notifications API
 * Middleware: auth:user (for customers only)
 */
Route::prefix('notifications')->middleware(['auth:user'])->group(function () {
    Route::get('/', [\App\Http\Controllers\NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/unread-count', [\App\Http\Controllers\NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
    Route::get('/recent', [\App\Http\Controllers\NotificationController::class, 'recent'])->name('notifications.recent');
    Route::get('/stats', [\App\Http\Controllers\NotificationController::class, 'stats'])->name('notifications.stats');
    Route::post('/{id}/read', [\App\Http\Controllers\NotificationController::class, 'markAsRead'])->name('notifications.mark-read');
    Route::post('/mark-all-read', [\App\Http\Controllers\NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-read');
    Route::delete('/{id}', [\App\Http\Controllers\NotificationController::class, 'destroy'])->name('notifications.destroy');
    
    // Phase 6: Advanced Features
    Route::post('/bulk/mark-read', [\App\Http\Controllers\NotificationController::class, 'bulkMarkAsRead'])->name('notifications.bulk-mark-read');
    Route::delete('/bulk/delete', [\App\Http\Controllers\NotificationController::class, 'bulkDelete'])->name('notifications.bulk-delete');
    Route::post('/bulk/archive', [\App\Http\Controllers\NotificationController::class, 'bulkArchive'])->name('notifications.bulk-archive');
    Route::post('/{id}/archive', [\App\Http\Controllers\NotificationController::class, 'archive'])->name('notifications.archive');
    Route::get('/grouped', [\App\Http\Controllers\NotificationController::class, 'grouped'])->name('notifications.grouped');
    Route::get('/export', [\App\Http\Controllers\NotificationController::class, 'export'])->name('notifications.export');
});

/**
 * Module Routes are loaded via web.php
 * - routes/hr-api.php
 * - routes/finance-api.php  
 * - routes/shop-owner-api.php
 */
