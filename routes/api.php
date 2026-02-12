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
Route::withoutMiddleware(['web', 'api'])->post('/paymongo-proxy', function (Request $request) {
    try {
        $amount = $request->input('amount');
        $description = $request->input('description', 'SoleSpace Purchase');

        if (!$amount || $amount <= 0) {
            return response()->json(['error' => 'Invalid amount'], 400);
        }

        $apiKey = env('PAYMONGO_SECRET_KEY');
        
        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($apiKey . ':'),
        ])->post('https://api.paymongo.com/v1/links', [
            'data' => [
                'attributes' => [
                    'amount' => (int)($amount * 100), // Convert to centavos
                    'currency' => 'PHP',
                    'description' => $description,
                    'success_url' => url('/order-success'),
                    'failed_url' => url('/payment-failed'),
                ],
            ],
        ]);

        if ($response->failed()) {
            $errorMsg = $response->json('message') ?? $response->json('error') ?? 'PayMongo API failed';
            $errors = $response->json('errors');
            
            // Log detailed error for debugging
            \Log::error('PayMongo API Error', [
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
            \Log::error('Missing data in PayMongo response', ['response' => $data]);
            return response()->json(['error' => 'Incomplete PayMongo response'], 500);
        }

        return response()->json([
            'checkout_url' => $checkoutUrl,
            'link_id' => $linkId,
        ]);
    } catch (\Exception $e) {
        \Log::error('PayMongo Proxy Exception', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        return response()->json(['error' => 'Server error: ' . $e->getMessage()], 500);
    }
});

/**
 * Price Change Requests - Staff endpoints
 * MOVED TO web.php for proper session handling
 * Using web.php ensures session persistence across navigation
 */
// Route::middleware(['web', 'auth:user'])->prefix('price-change-requests')->group(function () {
//     Route::get('/my-pending', [PriceChangeRequestController::class, 'myPending']);
// });

// Debug endpoint to check current user
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
Route::middleware(['web', 'auth:web,user', 'old_role:Finance Staff|Finance Manager|Manager|Staff', 'shop.isolation'])->prefix('finance')->group(function () {
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
Route::post('/checkout/create-order', [\App\Http\Controllers\UserSide\CheckoutController::class, 'createOrder']);
Route::post('/orders/{id}/update-payment-link', [\App\Http\Controllers\UserSide\CheckoutController::class, 'updatePaymentLink']);
Route::get('/orders/{id}/details', [\App\Http\Controllers\UserSide\CheckoutController::class, 'getOrderDetails']);

/**
 * Staff/Manager Customer Management API
 */
Route::prefix('staff')->middleware(['web', 'auth:user'])->group(function () {
    Route::get('/customers', [\App\Http\Controllers\Api\Staff\CustomerController::class, 'index']);
    Route::get('/customers/stats', [\App\Http\Controllers\Api\Staff\CustomerController::class, 'stats']);
});

/**
 * Module Routes are loaded via web.php
 * - routes/hr-api.php
 * - routes/finance-api.php  
 * - routes/shop-owner-api.php
 */
