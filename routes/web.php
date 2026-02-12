<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use App\Http\Controllers\ShopOwner\CalendarController;
use App\Http\Controllers\ShopOwner\EcommerceController;
use App\Http\Controllers\ShopOwner\UserAccessControlController;
use App\Http\Controllers\UserSide\LandingPageController;
use App\Http\Controllers\UserSide\CartController;
use App\Http\Controllers\UserSide\OrderController;
use App\Http\Controllers\UserSide\CheckoutController;
use App\Http\Controllers\superAdmin\SuperAdminUserManagementController;
use App\Http\Controllers\superAdmin\FlaggedAccountsController;
use App\Http\Controllers\superAdmin\ShopOwnerRegistrationViewController;
use App\Http\Controllers\superAdmin\SystemMonitoringDashboardController;
use App\Http\Controllers\superAdmin\NotificationCommunicationToolsController;
use App\Http\Controllers\superAdmin\DataReportAccessController;
use App\Http\Controllers\ShopRegistrationController;
use App\Http\Controllers\SuperAdminController;
use App\Http\Controllers\SuperAdminAuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ShopOwnerAuthController;
use App\Http\Controllers\UserProfileController;
use App\Http\Controllers\Api\ManagerController;
use App\Http\Controllers\Api\LeaveController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group.
|
*/

// Public Routes (User Side)
Route::get('/', [LandingPageController::class, 'index'])->name('landing');
Route::get('/products', [LandingPageController::class, 'products'])->name('products');
Route::get('/products/{slug}', [LandingPageController::class, 'productShow'])->name('products.show');
Route::get('/checkout', function () {
    return Inertia::render('UserSide/Checkout');
})->name('checkout');
Route::get('/payment', function () {
    return Inertia::render('UserSide/payment');
})->name('payment');
Route::get('/order-success', function () {
    return Inertia::render('UserSide/OrderSuccess');
})->name('order-success');
Route::get('/payment-failed', function () {
    return Inertia::render('UserSide/PaymentFailed');
})->name('payment-failed');
Route::get('/my-orders', [OrderController::class, 'index'])->name('my-orders');
Route::post('/orders/confirm-delivery', [OrderController::class, 'confirmDelivery'])->name('orders.confirm-delivery');
Route::post('/orders/cancel', [OrderController::class, 'cancel'])->middleware('auth:user')->name('orders.cancel');
Route::get('/my-repairs', function () {
    return Inertia::render('UserSide/myRepairs');
})->name('my-repairs');
Route::get('/repair-process', function () {
    return Inertia::render('UserSide/RepairProcess');
})->name('repair-process');
Route::get('/repair-services', [LandingPageController::class, 'repair'])->name('repair');
Route::get('/repair-shop/{id}', function () {
    return Inertia::render('UserSide/repairShow');
})->name('repair.show');
// Message / Chat with shop owner
Route::get('/message/{shopId}', function ($shopId) {
    return Inertia::render('UserSide/message', [
        'shopOwner' => [
            'id' => $shopId,
            // Minimal placeholder data; frontend can fetch real details if needed
            'name' => 'Shop Owner',
            'avatar' => '/images/shop/shop1.jpg',
            'online' => false,
        ],
    ]);
})->name('message');
Route::get('/shop-profile/{id}', [LandingPageController::class, 'shopProfile'])->name('shop-profile');
Route::get('/services', [LandingPageController::class, 'services'])->name('services');
Route::get('/contact', [LandingPageController::class, 'contact'])->name('contact');
Route::get('/register', [LandingPageController::class, 'register'])->name('register');
Route::get('/login', function () {
    return Inertia::render('UserSide/UserLogin');
})->name('login');
Route::get('/shop-owner-register', [LandingPageController::class, 'shopOwnerRegister'])->name('shop-owner-register');

// Cart Routes
Route::get('/api/cart', [CartController::class, 'index'])->name('cart.index');
Route::post('/api/cart/add', [CartController::class, 'add'])->middleware('auth:user')->name('cart.add');
Route::post('/api/cart/remove', [CartController::class, 'remove'])->middleware('auth:user')->name('cart.remove');
Route::post('/api/cart/update', [CartController::class, 'update'])->middleware('auth:user')->name('cart.update');
Route::post('/api/cart/clear', [CartController::class, 'clear'])->middleware('auth:user')->name('cart.clear');
Route::post('/api/cart/sync', [CartController::class, 'sync'])->middleware('auth:user')->name('cart.sync');

// User Address Management Routes
Route::middleware('auth:user')->prefix('api/user/addresses')->group(function () {
    Route::get('/', [App\Http\Controllers\UserSide\UserAddressController::class, 'index'])->name('user.addresses.index');
    Route::post('/', [App\Http\Controllers\UserSide\UserAddressController::class, 'store'])->name('user.addresses.store');
    Route::put('/{id}', [App\Http\Controllers\UserSide\UserAddressController::class, 'update'])->name('user.addresses.update');
    Route::delete('/{id}', [App\Http\Controllers\UserSide\UserAddressController::class, 'destroy'])->name('user.addresses.destroy');
    Route::post('/{id}/set-default', [App\Http\Controllers\UserSide\UserAddressController::class, 'setDefault'])->name('user.addresses.set-default');
});

// Checkout & Order Routes
Route::post('/api/checkout/create-order', [CheckoutController::class, 'createOrder'])->middleware('auth:user')->name('checkout.create-order');
Route::get('/api/my-orders', [CheckoutController::class, 'myOrders'])->middleware('auth:user')->name('api.my-orders');

// User Login Page
Route::get('/user/login', function () {
    return Inertia::render('UserSide/UserLogin');
})->name('user.login.form');

// Shop Owner Login Page (redirect to customer login)
Route::get('/shop-owner/login', function () {
    return redirect()->route('user.login.form');
})->name('shop-owner.login.form');

// User Authentication Routes
Route::post('/user/register', [UserController::class, 'register'])->name('user.register');
Route::post('/user/login', [UserController::class, 'login'])->name('user.login');
Route::post('/user/logout', [UserController::class, 'logout'])->name('user.logout');
Route::get('/api/user/me', [UserController::class, 'me'])->middleware('auth:user')->name('user.me');

// Shop Owner Authentication Routes
Route::post('/shop-owner/register', [ShopOwnerAuthController::class, 'register'])->name('shop-owner.register');
Route::post('/shop-owner/login', [ShopOwnerAuthController::class, 'login'])->name('shop-owner.login');
Route::post('/shop-owner/logout', [ShopOwnerAuthController::class, 'logout'])->name('shop-owner.logout');

// TEST ROUTE - Remove after debugging
Route::get('/test-auth', function () {
    return response()->json([
        'authenticated' => Auth::check(),
        'guard_user_check' => Auth::guard('user')->check(),
        'user' => Auth::user(),
        'guard_user' => Auth::guard('user')->user(),
        'session_id' => session()->getId(),
        'session_has_user' => session()->has('login_user_' . Auth::guard('user')->getName()),
    ]);
})->middleware('web');

Route::get('/api/shop-owner/me', [ShopOwnerAuthController::class, 'me'])->middleware('auth:shop_owner')->name('shop-owner.me');

// Common Routes (for testing/development)
Route::group([], function () {
    Route::get('/profile', function () {
        return Inertia::render('UserProfiles');
    })->name('profile');
    Route::get('/blank', function () {
        return Inertia::render('Blank');
    })->name('blank');
    Route::get('/form-elements', function () {
        return Inertia::render('Forms/FormElements');
    })->name('form-elements');
    Route::get('/basic-tables', function () {
        return Inertia::render('Tables/BasicTables');
    })->name('basic-tables');
    Route::get('/alerts', function () {
        return Inertia::render('UiElements/Alerts');
    })->name('alerts');
    Route::get('/avatars', function () {
        return Inertia::render('UiElements/Avatars');
    })->name('avatars');
    Route::get('/badge', function () {
        return Inertia::render('UiElements/Badges');
    })->name('badge');
    Route::get('/buttons', function () {
        return Inertia::render('UiElements/Buttons');
    })->name('buttons');
    Route::get('/images', function () {
        return Inertia::render('UiElements/Images');
    })->name('images');
    Route::get('/videos', function () {
        return Inertia::render('UiElements/Videos');
    })->name('videos');
    Route::get('/line-chart', function () {
        return Inertia::render('Charts/LineChart');
    })->name('line-chart');
    Route::get('/bar-chart', function () {
        return Inertia::render('Charts/BarChart');
    })->name('bar-chart');
});

// Super Admin Routes
Route::prefix('superAdmin')->name('superAdmin.')->middleware('auth:super_admin')->group(function () {
    Route::get('/super-admin-user-management', [SuperAdminUserManagementController::class, 'index'])->name('super-admin-user-management');
    Route::get('/flagged-accounts', [FlaggedAccountsController::class, 'index'])->name('flagged-accounts');
    Route::get('/shop-owner-registration-view', [ShopOwnerRegistrationViewController::class, 'index'])->name('shop-owner-registration-view');
    Route::post('/shop-owner-registration/{id}/approve', [ShopOwnerRegistrationViewController::class, 'approve'])->name('shop-owner-approve');
    Route::post('/shop-owner-registration/{id}/reject', [ShopOwnerRegistrationViewController::class, 'reject'])->name('shop-owner-reject');
    Route::get('/system-monitoring-dashboard', [SystemMonitoringDashboardController::class, 'index'])->name('system-monitoring-dashboard');
    Route::get('/notification-communication-tools', [NotificationCommunicationToolsController::class, 'index'])->name('notification-communication-tools');
    Route::get('/data-report-access', [DataReportAccessController::class, 'index'])->name('data-report-access');
});

// Shop Owner Routes
Route::prefix('shopOwner')->name('shopOwner.')->group(function () {
    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar');
    Route::get('/ecommerce', [EcommerceController::class, 'index'])->name('ecommerce');
    Route::get('/user-access-control', [UserAccessControlController::class, 'index'])->name('user-access-control');
    // Suspend Accounts page for Shop Owner (frontend page)
    Route::get('/suspend-accounts', function () {
        return Inertia::render('ShopOwner/suspendAccount');
    })->name('suspend-accounts');
});

// Shop Owner Protected Routes
Route::middleware('auth:shop_owner')->prefix('shop-owner')->name('shop-owner.')->group(function () {
    Route::get('/dashboard', function () {
        $shopOwner = Auth::guard('shop_owner')->user();
        return Inertia::render('ShopOwner/Dashboard', ['shop_owner' => $shopOwner]);
    })->name('dashboard');

    Route::get('/products', function () {
        return Inertia::render('ERP/STAFF/ProductManagementWithVariants');
    })->name('products');

    Route::get('/orders', function () {
        return Inertia::render('ShopOwner/Orders');
    })->name('orders');

    Route::get('/shop-profile', [\App\Http\Controllers\ShopOwner\ShopProfileController::class, 'index'])->name('shop-profile');
    Route::post('/shop-profile', [\App\Http\Controllers\ShopOwner\ShopProfileController::class, 'update'])->name('shop-profile.update');

    Route::get('/audit-logs', function () {
        return Inertia::render('ShopOwner/AuditLogs');
    })->name('audit-logs');

    Route::get('/price-approvals', function () {
        return Inertia::render('ShopOwner/PriceApprovals');
    })->name('price-approvals');

    Route::post('/employees', [UserAccessControlController::class, 'storeEmployee'])->name('employees.store');
    Route::delete('/employees/{employee}', [\App\Http\Controllers\EmployeeController::class, 'destroy'])->middleware('shop.isolation')->name('employees.destroy');
    Route::post('/employees/{employee}/suspend', [\App\Http\Controllers\EmployeeController::class, 'suspend'])->middleware('shop.isolation')->name('employees.suspend');
    Route::post('/employees/{employee}/activate', [\App\Http\Controllers\EmployeeController::class, 'activate'])->middleware('shop.isolation')->name('employees.activate');
    
    // Permission Management Routes (Phase 6)
    Route::get('/permissions/available', [UserAccessControlController::class, 'getAvailablePermissions'])->name('permissions.available');
    Route::get('/employees/{userId}/permissions', [UserAccessControlController::class, 'getEmployeePermissions'])->name('employees.permissions.get');
    Route::post('/employees/{userId}/permissions', [UserAccessControlController::class, 'updateEmployeePermissions'])->name('employees.permissions.update');
    Route::post('/employees/{userId}/permissions/sync', [UserAccessControlController::class, 'syncEmployeePermissions'])->name('employees.permissions.sync');

    // Roles Management Routes (Phase 7)
    Route::get('/roles/available', [UserAccessControlController::class, 'getAvailableRoles'])->name('roles.available');
    Route::post('/employees/{userId}/roles/sync', [UserAccessControlController::class, 'syncAdditionalRoles'])->name('employees.roles.sync');
    
    // Position Templates Routes (Phase 6+)
    Route::get('/position-templates', [UserAccessControlController::class, 'getPositionTemplates'])->name('position-templates.index');
    Route::post('/employees/{userId}/apply-template', [UserAccessControlController::class, 'applyPositionTemplate'])->name('employees.apply-template');
});

/**
 * Activity Logs API - Accessible by Shop Owner and ERP Users
 * Must be in web.php to maintain session authentication
 * No middleware here - authentication checked in controller for flexibility
 */
Route::get('/api/activity-logs', [\App\Http\Controllers\ActivityLogController::class, 'index'])
    ->name('api.activity_logs');

// Shop Owner API Routes
Route::middleware('auth:shop_owner')->prefix('api/shop-owner')->group(function () {
    Route::get('dashboard/stats', [\App\Http\Controllers\ShopOwner\DashboardController::class, 'getStats']);
    Route::get('dashboard/low-stock', [\App\Http\Controllers\ShopOwner\DashboardController::class, 'getLowStockAlerts']);
    Route::get('orders', [\App\Http\Controllers\ShopOwner\OrderController::class, 'index']);
    Route::get('orders/{id}', [\App\Http\Controllers\ShopOwner\OrderController::class, 'show']);
    Route::patch('orders/{id}/status', [\App\Http\Controllers\ShopOwner\OrderController::class, 'updateStatus']);
    
    // Profile
    Route::post('upload-profile-photo', [\App\Http\Controllers\ShopOwner\ShopProfileController::class, 'uploadPhoto']);
    
    // Price Change Approvals
    Route::get('price-changes/pending', [\App\Http\Controllers\Api\PriceChangeRequestController::class, 'ownerPending']);
    Route::post('price-changes/{id}/approve', [\App\Http\Controllers\Api\PriceChangeRequestController::class, 'ownerApprove']);
    Route::post('price-changes/{id}/reject', [\App\Http\Controllers\Api\PriceChangeRequestController::class, 'ownerReject']);
});

// Staff Price Change Requests (session-based auth)
Route::middleware('auth:user')->prefix('api/price-change-requests')->group(function () {
    Route::get('/my-pending', [\App\Http\Controllers\Api\PriceChangeRequestController::class, 'myPending']);
});

// CSRF Token endpoint for API requests
Route::get('/api/csrf-token', function () {
    return response()->json(['csrf_token' => csrf_token()]);
});

// Staff API Routes (session-based authentication)
Route::middleware('auth:user')->prefix('api/staff')->group(function () {
    Route::get('orders', [\App\Http\Controllers\Api\StaffOrderController::class, 'index'])
        ->middleware('permission:view-job-orders');
    Route::get('orders/{id}', [\App\Http\Controllers\Api\StaffOrderController::class, 'show'])
        ->middleware('permission:view-job-orders');
    Route::patch('orders/{id}/status', [\App\Http\Controllers\Api\StaffOrderController::class, 'updateStatus'])
        ->middleware('permission:edit-job-orders');
    Route::post('orders/{id}/complete', [\App\Http\Controllers\Api\StaffOrderController::class, 'complete'])
        ->middleware('permission:complete-job-orders');
});

// Product API Routes (public and shop owner)
Route::prefix('api/products')->group(function () {
    // Public routes (customers)
    Route::get('/', [\App\Http\Controllers\Api\ProductController::class, 'index']);
    Route::get('{slug}', [\App\Http\Controllers\Api\ProductController::class, 'show']);
    
    // Variant stock check (public)
    Route::post('{id}/variant-stock', [\App\Http\Controllers\Api\ProductController::class, 'getVariantStock']);
    
    // Shop Owner & Staff routes (authenticated - accepts both auth:user and auth:shop_owner)
    Route::middleware('auth:user,shop_owner')->group(function () {
        Route::get('my/products', [\App\Http\Controllers\Api\ProductController::class, 'myProducts'])
            ->middleware('permission:view-products');
        Route::post('/', [\App\Http\Controllers\Api\ProductController::class, 'store'])
            ->middleware('permission:create-products');
        Route::put('{id}', [\App\Http\Controllers\Api\ProductController::class, 'update'])
            ->middleware('permission:edit-products');
        Route::delete('{id}', [\App\Http\Controllers\Api\ProductController::class, 'destroy'])
            ->middleware('permission:delete-products');
        Route::post('upload-image', [\App\Http\Controllers\Api\ProductController::class, 'uploadImage'])
            ->middleware('permission:create-products|edit-products');
        Route::get('{id}/variants', [\App\Http\Controllers\Api\ProductController::class, 'getVariants'])
            ->middleware('permission:view-products');
        
        // Price Change Request - Staff must create approval request instead of direct update
        Route::post('price-change-request', [\App\Http\Controllers\Api\ProductController::class, 'createPriceChangeRequest'])
            ->middleware('permission:view-pricing');
        Route::post('{id}/request-price-change', [\App\Http\Controllers\Api\PriceChangeRequestController::class, 'store'])
            ->middleware('permission:view-pricing');
        
        // Color Variant Management
        Route::get('{productId}/color-variants', [\App\Http\Controllers\Api\ProductController::class, 'getColorVariants'])
            ->middleware('permission:view-products');
        Route::post('{productId}/color-variants', [\App\Http\Controllers\Api\ProductController::class, 'storeColorVariant'])
            ->middleware('permission:create-products|edit-products');
        Route::put('{productId}/color-variants/{colorVariantId}', [\App\Http\Controllers\Api\ProductController::class, 'updateColorVariant'])
            ->middleware('permission:edit-products');
        Route::delete('{productId}/color-variants/{colorVariantId}', [\App\Http\Controllers\Api\ProductController::class, 'deleteColorVariant'])
            ->middleware('permission:delete-products');
        
        // Color Variant Image Management
        Route::post('{productId}/color-variants/{colorVariantId}/images', [\App\Http\Controllers\Api\ProductController::class, 'uploadColorVariantImage'])
            ->middleware('permission:create-products|edit-products');
        Route::put('{productId}/color-variants/{colorVariantId}/images/{imageId}', [\App\Http\Controllers\Api\ProductController::class, 'updateColorVariantImage'])
            ->middleware('permission:edit-products');
        Route::delete('{productId}/color-variants/{colorVariantId}/images/{imageId}', [\App\Http\Controllers\Api\ProductController::class, 'deleteColorVariantImage'])
            ->middleware('permission:delete-products');
        Route::post('{productId}/color-variants/{colorVariantId}/images/reorder', [\App\Http\Controllers\Api\ProductController::class, 'reorderColorVariantImages'])
            ->middleware('permission:edit-products');
    });
});

// Product Reviews API (Verified Buyer System)
Route::prefix('api/products/{productId}/reviews')->group(function () {
    // Public route - Get all reviews for a product
    Route::get('/', [\App\Http\Controllers\Api\ProductReviewController::class, 'index']);
    
    // Authenticated routes (regular customers only, not ERP staff)
    Route::middleware('auth:user')->group(function () {
        // Check if user can review this product
        Route::get('check-eligibility', [\App\Http\Controllers\Api\ProductReviewController::class, 'checkEligibility']);
        
        // Submit a new review (verified buyers only)
        Route::post('/', [\App\Http\Controllers\Api\ProductReviewController::class, 'store']);
        
        // Get user's own review for this product
        Route::get('my-review', [\App\Http\Controllers\Api\ProductReviewController::class, 'getUserReview']);
        
        // Update user's review
        Route::put('{reviewId}', [\App\Http\Controllers\Api\ProductReviewController::class, 'update']);
        
        // Delete user's review
        Route::delete('{reviewId}', [\App\Http\Controllers\Api\ProductReviewController::class, 'destroy']);
    });
});

// Public route to serve review images
Route::get('/storage/reviews/{filename}', function ($filename) {
    $path = storage_path('app/public/reviews/' . $filename);
    
    if (!file_exists($path)) {
        abort(404);
    }
    
    return response()->file($path);
})->where('filename', '.*');

// Session-backed API endpoints for finance (allow web-session authenticated users)
// CONSOLIDATED: All finance routes under /api/finance/session
Route::middleware('auth:user')->prefix('api/finance/session')->group(function () {
    // Chart of Accounts - Commented out due to missing controller
    // Route::get('accounts', [\App\Http\Controllers\Api\Finance\AccountController::class, 'index']);
    // Route::post('accounts', [\App\Http\Controllers\Api\Finance\AccountController::class, 'store']);
    // Route::get('accounts/{id}', [\App\Http\Controllers\Api\Finance\AccountController::class, 'show']);
    // Route::get('accounts/{id}/ledger', [\App\Http\Controllers\Api\Finance\AccountController::class, 'ledger']);

    // Expenses
    Route::get('expenses', [\App\Http\Controllers\Api\Finance\ExpenseController::class, 'index']);
    Route::post('expenses', [\App\Http\Controllers\Api\Finance\ExpenseController::class, 'store']);
    Route::get('expenses/{id}', [\App\Http\Controllers\Api\Finance\ExpenseController::class, 'show']);
    Route::put('expenses/{id}', [\App\Http\Controllers\Api\Finance\ExpenseController::class, 'update']);
    Route::patch('expenses/{id}', [\App\Http\Controllers\Api\Finance\ExpenseController::class, 'update']);
    Route::delete('expenses/{id}', [\App\Http\Controllers\Api\Finance\ExpenseController::class, 'destroy']);
    
    // Expense Receipt Management
    Route::post('expenses/{id}/receipt', [\App\Http\Controllers\Api\Finance\ExpenseController::class, 'uploadReceipt']);
    Route::get('expenses/{id}/receipt/download', [\App\Http\Controllers\Api\Finance\ExpenseController::class, 'downloadReceipt']);
    Route::delete('expenses/{id}/receipt', [\App\Http\Controllers\Api\Finance\ExpenseController::class, 'deleteReceipt']);
    
    // Expense approval/posting (users with approval permission)
    Route::middleware('permission:approve-expenses')->group(function () {
        Route::post('expenses/{id}/approve', [\App\Http\Controllers\Api\Finance\ExpenseController::class, 'approve']);
        Route::post('expenses/{id}/reject', [\App\Http\Controllers\Api\Finance\ExpenseController::class, 'reject']);
        Route::post('expenses/{id}/post', [\App\Http\Controllers\Api\Finance\ExpenseController::class, 'post']);
    });

    // Invoices
    Route::get('invoices', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'index']);
    Route::post('invoices', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'store']);
    Route::post('invoices/from-job', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'createFromJob']);
    Route::get('invoices/{id}', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'show']);
    Route::put('invoices/{id}', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'update']);
    Route::patch('invoices/{id}', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'update']);
    Route::delete('invoices/{id}', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'destroy']);
    Route::post('invoices/{id}/send', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'send']);
    Route::post('invoices/{id}/void', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'void']);

    // REMOVED: Journal Entries - Invoices/expenses auto-post behind the scenes for SMEs
    // Route::get('journal-entries', [FinanceJournalEntryController::class, 'index']);
    // Route::post('journal-entries', [FinanceJournalEntryController::class, 'store']);
    // Route::get('journal-entries/{id}', [FinanceJournalEntryController::class, 'show']);
    // Route::put('journal-entries/{id}', [FinanceJournalEntryController::class, 'update']);
    // Route::patch('journal-entries/{id}', [FinanceJournalEntryController::class, 'update']);
    // Route::delete('journal-entries/{id}', [FinanceJournalEntryController::class, 'destroy']);
    // Route::post('journal-entries/{id}/post', [FinanceJournalEntryController::class, 'post']);
    // Route::post('journal-entries/{id}/reverse', [FinanceJournalEntryController::class, 'reverse']);

    // REMOVED: Bank Reconciliation - Too complex for SMEs
    // Route::prefix('reconciliation')->group(function () {
    //     Route::get('transactions', [\App\Http\Controllers\ReconciliationController::class, 'getTransactions']);
    //     Route::post('/', [\App\Http\Controllers\ReconciliationController::class, 'store']);
    //     Route::get('history', [\App\Http\Controllers\ReconciliationController::class, 'history']);
    //     Route::delete('{id}/unmatch', [\App\Http\Controllers\ReconciliationController::class, 'unmatch']);
    // });

    // Tax Rates
    Route::get('tax-rates', [\App\Http\Controllers\Api\Finance\TaxRateController::class, 'index']);
    Route::post('tax-rates', [\App\Http\Controllers\Api\Finance\TaxRateController::class, 'store']);
    Route::put('tax-rates/{id}', [\App\Http\Controllers\Api\Finance\TaxRateController::class, 'update']);
    Route::delete('tax-rates/{id}', [\App\Http\Controllers\Api\Finance\TaxRateController::class, 'destroy']);

    // Approval Workflow routes
    Route::prefix('approvals')->group(function () {
        Route::get('pending', [\App\Http\Controllers\ApprovalController::class, 'getPending']);
        Route::get('history', [\App\Http\Controllers\ApprovalController::class, 'getHistory']);
        Route::get('{id}/history', [\App\Http\Controllers\ApprovalController::class, 'getApprovalHistory']);

        // Only users with approval permission can approve/reject transactions
        Route::middleware('permission:approve-expenses')->group(function () {
            Route::post('{id}/approve', [\App\Http\Controllers\ApprovalController::class, 'approve']);
            Route::post('{id}/reject', [\App\Http\Controllers\ApprovalController::class, 'reject']);
        });

        // Delegation routes (managers only)
        Route::middleware('role:Manager')->group(function () {
            Route::get('delegations', [\App\Http\Controllers\ApprovalController::class, 'getDelegations']);
            Route::post('delegations', [\App\Http\Controllers\ApprovalController::class, 'createDelegation']);
            Route::post('delegations/{id}/deactivate', [\App\Http\Controllers\ApprovalController::class, 'deactivateDelegation']);
        });
    });
});

// Search routes
Route::middleware(['auth:user', 'check.suspension'])->group(function () {
    Route::get('/api/search', [\App\Http\Controllers\Api\SearchController::class, 'search']);
});

// Notification routes
Route::middleware(['auth:user', 'check.suspension'])->prefix('api/notifications')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\NotificationController::class, 'index']);
    Route::get('unread-count', [\App\Http\Controllers\Api\NotificationController::class, 'unreadCount']);
    Route::post('{id}/read', [\App\Http\Controllers\Api\NotificationController::class, 'markAsRead']);
    Route::post('read-all', [\App\Http\Controllers\Api\NotificationController::class, 'markAllAsRead']);
    Route::delete('{id}', [\App\Http\Controllers\Api\NotificationController::class, 'destroy']);
    
    // Notification preferences
    Route::get('preferences', [\App\Http\Controllers\Api\NotificationController::class, 'getPreferences']);
    Route::put('preferences', [\App\Http\Controllers\Api\NotificationController::class, 'updatePreferences']);
});

// Shop Registration Routes
Route::get('/shop/register', function () {
    return Inertia::render('userSide/ShopOwnerRegistration');
})->name('shop.register.form');
Route::post('/shop/register-full', [ShopRegistrationController::class, 'storeFullInertia'])->name('shop.register');

// Shop Message Route
Route::get('/shop/message', function () {
    return Inertia::render('UserSide/message', [
        'shopOwner' => [
            'id' => 1,
            'name' => 'Test Business',
            'avatar' => 'https://via.placeholder.com/48',
            'online' => true,
        ]
    ]);
})->name('shop.message');

// Super Admin Authentication Routes (Second set - removed duplicate, fixing authentication flows)
Route::get('/admin/login', [SuperAdminAuthController::class, 'showLoginForm'])->name('admin.login');
Route::post('/admin/login', [SuperAdminAuthController::class, 'login'])->name('admin.login.post');
Route::post('/admin/logout', [SuperAdminAuthController::class, 'logout'])->name('admin.logout');

// Admin Protected Routes
Route::middleware('super_admin.auth')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', function () {
        return redirect()->route('admin.system-monitoring');
    })->name('dashboard');
    Route::get('/system-monitoring', [SystemMonitoringDashboardController::class, 'index'])->name('system-monitoring');

    // Admin management routes
    Route::get('/admin', [SuperAdminController::class, 'showAdminManagement'])->name('admin-management');
    Route::get('/create-admin', [SuperAdminController::class, 'showCreateAdmin'])->name('create-admin');
    Route::post('/create-admin', [SuperAdminController::class, 'storeAdmin'])->name('create-admin.store');
    Route::post('/admins/{id}/suspend', [SuperAdminController::class, 'suspendAdmin'])->name('admins.suspend');
    Route::post('/admins/{id}/activate', [SuperAdminController::class, 'activateAdmin'])->name('admins.activate');
    Route::delete('/admins/{id}', [SuperAdminController::class, 'deleteAdmin'])->name('admins.delete');

    // Shop management routes
    Route::get('/registered-shops', [SuperAdminController::class, 'showRegisteredShops'])->name('registered-shops');
    Route::post('/shops/{id}/suspend', [SuperAdminController::class, 'suspendShop'])->name('shops.suspend');
    Route::post('/shops/{id}/activate', [SuperAdminController::class, 'activateShop'])->name('shops.activate');
    Route::delete('/shops/{id}', [SuperAdminController::class, 'deleteShop'])->name('shops.delete');

    // User management routes
    Route::get('/user-management', [SuperAdminController::class, 'showUserManagement'])->name('user-management');
    Route::post('/users/{id}/suspend', [SuperAdminController::class, 'suspendUser'])->name('users.suspend');
    Route::post('/users/{id}/activate', [SuperAdminController::class, 'activateUser'])->name('users.activate');
    Route::delete('/users/{id}', [SuperAdminController::class, 'deleteUser'])->name('users.delete');

    // Additional admin routes
    Route::get('/notifications', function () {
        return Inertia::render('superAdmin/NotificationCommunicationTools');
    })->name('notifications');
    Route::get('/data-reports', [SuperAdminController::class, 'showDataReports'])->name('data-reports');
});

// Training Routes
Route::prefix('training')->name('training.')->middleware('auth:user')->group(function () {
    Route::get('/', function () {
        return Inertia::render('Training');
    })->name('index');
});

// HR and ERP routes
// Time In/Out - First thing staff see after login
Route::middleware(['auth:user', 'check.suspension'])->get('/erp/time-in', function () {
    if (Auth::guard('user')->user()?->force_password_change) {
        return redirect()->route('erp.profile');
    }
    return Inertia::render('ERP/STAFF/TimeIn');
})->name('erp.time-in');

Route::middleware(['auth:user', 'check.suspension', 'permission:view-employees|view-attendance'])->get('/erp/hr', function () {
    if (Auth::guard('user')->user()?->force_password_change) {
        return redirect()->route('erp.profile');
    }
    return Inertia::render('ERP/HR/HR');
})->name('erp.hr');

// HR Audit Logs
Route::get('/erp/hr/audit-logs', function () {
    if (Auth::guard('user')->user()?->force_password_change) {
        return redirect()->route('erp.profile');
    }
    return Inertia::render('ERP/HR/AuditLogs');
})->middleware(['auth:user', 'permission:view-hr-audit-logs'])->name('erp.hr.audit-logs');

Route::middleware(['auth:user', 'check.suspension'])->group(function () {
    Route::get('/erp/profile', [UserProfileController::class, 'show'])->name('erp.profile');
    Route::post('/erp/password', [UserProfileController::class, 'updatePassword'])->name('erp.password.update');
});

// Finance pages
Route::prefix('finance')->name('finance.')->middleware(['auth:user', 'permission:view-expenses|view-invoices'])->group(function () {
    Route::get('/', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/Finance/Finance');
    })->name('index');
    
    Route::get('/dashboard', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/Finance/Dashboard');
    })->name('dashboard');
});

// Finance Audit Logs
Route::get('/erp/finance/audit-logs', function () {
    if (Auth::guard('user')->user()?->force_password_change) {
        return redirect()->route('erp.profile');
    }
    return Inertia::render('ERP/Finance/AuditLogs');
})->middleware(['auth:user', 'permission:view-finance-audit-logs'])->name('erp.finance.audit-logs');

// Approval Workflow page removed (frontend page deleted)

Route::get('/create-invoice', function () {
    if (Auth::guard('user')->user()?->force_password_change) {
        return redirect()->route('erp.profile');
    }
    return redirect('/finance?section=create-invoice');
})->middleware(['auth:user', 'permission:create-invoices'])->name('finance.create-invoice');

// CRM routes
Route::prefix('crm')->name('crm.')->middleware(['auth:user', 'permission:view-customers|view-leads'])->group(function () {
    Route::get('/', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/CRM/CRMDashboard');
    })->name('dashboard');
    Route::get('/opportunities', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/CRM/Opportunities');
    })->name('opportunities');
    Route::get('/leads', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/CRM/Leads');
    })->name('leads');
    Route::get('/customers', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/CRM/Customers');
    })->name('customers');
});

// MANAGER routes (only MANAGER can access)
Route::prefix('erp/manager')->name('erp.manager.')->middleware(['auth:user', 'role:Manager'])->group(function () {
    Route::get('/dashboard', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/Manager/Dashboard');
    })->name('dashboard');
    Route::get('/reports', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/Manager/Reports');
    })->name('reports');
    Route::get('/suspend-approval', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/Manager/suspendAccountManager');
    })->name('suspend-approval');
    Route::get('/pricing-and-services', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/repairer/PricingAndServices');
    })->name('pricing-services');
    Route::get('/shoe-pricing', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/STAFF/shoePricing');
    })->name('shoe-pricing');
    Route::get('/products', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/Manager/productUpload');
    })->name('products');
    Route::get('/inventory-overview', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/Manager/InventoryOverview');
    })->name('inventory-overview');
    Route::get('/user-management', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/Manager/UserManagement');
    })->name('user-management');
    Route::get('/audit-logs', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/Manager/AuditLogs');
    })->name('audit-logs');
});

// Note: Manager audit-logs route already defined above within the manager group

// STAFF routes (both MANAGER and STAFF can access)
Route::prefix('erp/staff')->name('erp.staff.')->middleware(['auth:user', 'manager.staff:staff'])->group(function () {
    Route::get('/dashboard', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/STAFF/Dashboard');
    })->name('dashboard');
    Route::get('/job-orders', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/STAFF/JobOrders');
    })->middleware('permission:view-job-orders')->name('job-orders');
    
    Route::get('/job-orders-repair', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/repairer/JobOrdersRepair');
    })->middleware('permission:view-job-orders')->name('job-orders-repair');
    
    Route::get('/shoe-pricing', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/STAFF/shoePricing');
    })->middleware('permission:view-pricing')->name('shoe-pricing');
    
    Route::get('/repair-status', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/STAFF/RepairStatus');
    })->middleware('permission:view-job-orders')->name('repair-status');
    
    Route::get('/products', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/STAFF/ProductManagementWithVariants');
    })->middleware('permission:view-products')->name('products');
    Route::get('/payments', function () {
        return redirect()->route('erp.staff.products');
    });
    Route::get('/customers', [\App\Http\Controllers\Staff\CustomerController::class, 'index'])
        ->middleware('permission:view-customers')
        ->name('customers');
    
    Route::get('/attendance', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/STAFF/timeIn');
    })->middleware('permission:view-attendance')->name('attendance');
});

// Common Routes (for testing/development)
Route::group([], function () {
    Route::get('/profile', function () {
        return Inertia::render('UserProfiles');
    })->name('profile');
    Route::get('/blank', function () {
        return Inertia::render('Blank');
    })->name('blank');
    Route::get('/form-elements', function () {
        return Inertia::render('Forms/FormElements');
    })->name('form-elements');
    Route::get('/basic-tables', function () {
        return Inertia::render('Tables/BasicTables');
    })->name('basic-tables');
    Route::get('/alerts', function () {
        return Inertia::render('UiElements/Alerts');
    })->name('alerts');
    Route::get('/avatars', function () {
        return Inertia::render('UiElements/Avatars');
    })->name('avatars');
    Route::get('/badge', function () {
        return Inertia::render('UiElements/Badges');
    })->name('badge');
    Route::get('/buttons', function () {
        return Inertia::render('UiElements/Buttons');
    })->name('buttons');
    Route::get('/images', function () {
        return Inertia::render('UiElements/Images');
    })->name('images');
    Route::get('/videos', function () {
        return Inertia::render('UiElements/Videos');
    })->name('videos');
    Route::get('/line-chart', function () {
        return Inertia::render('Charts/LineChart');
    })->name('line-chart');
    Route::get('/bar-chart', function () {
        return Inertia::render('Charts/BarChart');
    })->name('bar-chart');
});

// Super Admin Routes
Route::prefix('superAdmin')->name('superAdmin.')->middleware('auth:super_admin')->group(function () {
    Route::get('/super-admin-user-management', [SuperAdminUserManagementController::class, 'index'])->name('super-admin-user-management');
    Route::get('/flagged-accounts', [FlaggedAccountsController::class, 'index'])->name('flagged-accounts');
    Route::get('/shop-owner-registration-view', [ShopOwnerRegistrationViewController::class, 'index'])->name('shop-owner-registration-view');
    Route::post('/shop-owner-registration/{id}/approve', [ShopOwnerRegistrationViewController::class, 'approve'])->name('shop-owner-approve');
    Route::post('/shop-owner-registration/{id}/reject', [ShopOwnerRegistrationViewController::class, 'reject'])->name('shop-owner-reject');
    Route::get('/system-monitoring-dashboard', [SystemMonitoringDashboardController::class, 'index'])->name('system-monitoring-dashboard');
    Route::get('/notification-communication-tools', [NotificationCommunicationToolsController::class, 'index'])->name('notification-communication-tools');
    Route::get('/data-report-access', [DataReportAccessController::class, 'index'])->name('data-report-access');
});

// Manager API Routes
Route::prefix('api/manager')->name('api.manager.')->middleware(['web', 'auth:user', 'check.suspension', 'role:Manager'])->group(function () {
    Route::get('/dashboard/stats', [ManagerController::class, 'getDashboardStats'])->name('dashboard.stats');
    Route::get('/staff-performance', [ManagerController::class, 'getStaffPerformance'])->name('staff-performance');
    Route::get('/analytics', [ManagerController::class, 'getAnalytics'])->name('analytics');

    // Suspension Approval Routes
    Route::prefix('suspension-requests')->group(function () {
        Route::get('/', [\App\Http\Controllers\ERP\Manager\SuspensionApprovalController::class, 'index'])->name('suspension_requests.index');
        Route::get('/{id}', [\App\Http\Controllers\ERP\Manager\SuspensionApprovalController::class, 'show'])->name('suspension_requests.show');
        Route::post('/{id}/review', [\App\Http\Controllers\ERP\Manager\SuspensionApprovalController::class, 'review'])->name('suspension_requests.review');
    });
});

// Leave Management API Routes
Route::prefix('api/leave')->name('api.leave.')->middleware(['auth:user'])->group(function () {
    // Staff routes
    Route::get('/', [LeaveController::class, 'index'])->name('index');
    Route::post('/', [LeaveController::class, 'store'])->name('store');
    Route::get('/{id}', [LeaveController::class, 'show'])->name('show');
    Route::delete('/{id}/cancel', [LeaveController::class, 'cancel'])->name('cancel');
    Route::get('/statistics/{employeeId}', [LeaveController::class, 'statistics'])->name('statistics');
    
    // Manager routes
    Route::get('/pending/all', [LeaveController::class, 'pending'])
        ->middleware('old_role:Manager|Finance Manager|Super Admin|Shop Owner')
        ->name('pending');
    Route::post('/{id}/approve', [LeaveController::class, 'approve'])
        ->middleware('old_role:Manager|Finance Manager|Super Admin|Shop Owner')
        ->name('approve');
    Route::post('/{id}/reject', [LeaveController::class, 'reject'])
        ->middleware('old_role:Manager|Finance Manager|Super Admin|Shop Owner')
        ->name('reject');
});

// Legacy API Routes
Route::post('/api/shop/register', [ShopRegistrationController::class, 'store']);
Route::post('/api/shop/register-full', [ShopRegistrationController::class, 'storeFull']);

/**
 * Load Module-Specific API Routes
 * Following best practice: separate API files for better organization
 */
require __DIR__.'/hr-api.php';
require __DIR__.'/finance-api.php';
require __DIR__.'/shop-owner-api.php';
require __DIR__.'/permission-audit-api.php';


