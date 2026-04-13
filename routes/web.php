<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use App\Http\Controllers\ShopOwner\EcommerceController;
use App\Http\Controllers\ShopOwner\UserAccessControlController;
use App\Http\Controllers\UserSide\LandingPageController;
use App\Http\Controllers\UserSide\CartController;
use App\Http\Controllers\UserSide\OrderController;
use App\Http\Controllers\UserSide\CheckoutController;
use App\Http\Controllers\UserSide\CustomerProfileController;
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
use App\Http\Controllers\ShopOwner\ShopSettingsController;
use App\Http\Controllers\ShopOwnerPasswordSetupController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\ForgotPasswordOtpController;
use App\Http\Controllers\UserProfileController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\Api\ManagerController;
use App\Http\Controllers\Api\LeaveController;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;

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

// Email Verification Routes
Route::get('/email/verify', function (Request $request) {
    $user = Auth::guard('web')->user() ?? Auth::guard('shop_owner')->user();

    return Inertia::render('UserSide/Auth/VerificationNotice', [
        'status' => session('status'),
        'email' => $user ? $user->email : null,
    ]);
})->middleware('auth')->name('verification.notice');

Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware(['signed'])
    ->name('verification.verify');

Route::post('/email/verification-notification', function (Request $request) {
    // Support both regular user (web guard) and shop owner guard
    $user = Auth::guard('web')->user() ?? Auth::guard('shop_owner')->user();

    if ($user && ! $user->hasVerifiedEmail()) {
        $user->sendEmailVerificationNotification();
    }

    return back()->with('status', 'verification-link-sent');
})->middleware(['auth:web,shop_owner', 'throttle:6,1'])->name('verification.send');

// Public Routes (User Side)
Route::get('/', [LandingPageController::class, 'index'])->name('landing');
Route::get('/products', [LandingPageController::class, 'products'])->name('products');
Route::get('/products/{slug}', [LandingPageController::class, 'productShow'])->name('products.show');
Route::get('/api/search/suggestions', [LandingPageController::class, 'searchSuggestions'])->name('search.suggestions');
Route::get('/checkout', function () {
    return Inertia::render('UserSide/Orders/Checkout');
})->name('checkout');
Route::get('/payment', function () {
    return Inertia::render('UserSide/Orders/payment');
})->name('payment');
Route::get('/order-success', function () {
    return Inertia::render('UserSide/Orders/OrderSuccess');
})->name('order-success');
Route::get('/payment-failed', function () {
    return Inertia::render('UserSide/Orders/PaymentFailed');
})->name('payment-failed');
Route::get('/my-orders', [OrderController::class, 'index'])->name('my-orders');
Route::post('/orders/confirm-delivery', [OrderController::class, 'confirmDelivery'])->name('orders.confirm-delivery');
Route::post('/orders/cancel', [OrderController::class, 'cancel'])->middleware('auth:user')->name('orders.cancel');
Route::post('/orders/request-refund', [OrderController::class, 'requestRefund'])->middleware('auth:user')->name('orders.request-refund');
Route::post('/orders/refunds/{id}/mark-shipped-return', [OrderController::class, 'markRefundReturnShipped'])->middleware('auth:user')->name('orders.refunds.mark-shipped-return');
Route::get('/customer-profile', [CustomerProfileController::class, 'show'])->middleware('auth:user')->name('customer-profile');
Route::post('/customer-profile', [CustomerProfileController::class, 'update'])->middleware('auth:user')->name('customer-profile.update');
Route::post('/customer-profile/password', [CustomerProfileController::class, 'updatePassword'])->middleware(['auth:user', 'throttle:5,1'])->name('customer-profile.password');
Route::get('/my-repairs', function () {
    $user = Auth::guard('user')->user();
    if ($user) {
        \App\Models\Notification::query()
            ->where('user_id', $user->id)
            ->where('is_read', false)
            ->where('type', 'repair_status_update')
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
    }

    return Inertia::render('UserSide/Repairs/myRepairs');
})->name('my-repairs');
Route::get('/repair-process', function () {
    return Inertia::render('UserSide/Repairs/RepairProcess');
})->name('repair-process');
Route::get('/erp/user/repair-reject-approval', function () {
    return Inertia::render('ShopOwner/Repairs/repairRejectReview');
})->middleware('auth:user')->name('erp.user.repair-reject-approval');
Route::get('/repair-services', [LandingPageController::class, 'repair'])->name('repair');
Route::get('/repair-shop/{id}', [LandingPageController::class, 'repairShow'])->name('repair.show');
// Customer conversations / Chat with repairer
Route::get('/customer/conversations', function () {
    return Inertia::render('UserSide/Communication/message');
})->middleware(['auth:user', 'customer.account'])->name('customer.conversations');
// Message / Chat with shop owner
Route::get('/message/{shopOwnerId?}', function ($shopOwnerId = null) {
    return Inertia::render('UserSide/Communication/message', [
        'shopOwnerId' => $shopOwnerId ? (int)$shopOwnerId : null,
    ]);
})->middleware(['auth:user', 'customer.account'])->name('message');

// Messages / Conversations listing page
Route::get('/messages', function () {
    return Inertia::render('UserSide/Communication/message', [
        'shops' => [], // Frontend will fetch shop list
    ]);
})->middleware(['auth:user', 'customer.account'])->name('messages');

// Customer notifications page
Route::get('/notifications', function () {
    return Inertia::render('Notifications/CustomerNotifications');
})->middleware('auth:user')->name('notifications.index');

// Customer notification preferences
Route::get('/notifications/settings', function () {
    return Inertia::render('Notifications/CustomerPreferences');
})->middleware('auth:user')->name('notifications.settings');

Route::get('/shop-profile/{id}', [LandingPageController::class, 'shopProfile'])->name('shop-profile');
Route::get('/shop-profile/{id}/virtual-showroom', [LandingPageController::class, 'virtualShowroom'])
    ->middleware('has.active.retail.premium')
    ->name('shop-profile.virtual-showroom');

// Phase 10D - Public shop reviews (no auth required)
Route::get('/api/shop-owners/{id}/reviews', [\App\Http\Controllers\Api\RepairReviewController::class, 'getShopReviews']);

Route::get('/services', [LandingPageController::class, 'services'])->name('services');
Route::get('/services/product-image-spin-tutorial', [LandingPageController::class, 'productImageSpinTutorial'])
    ->name('services.product-image-spin-tutorial');
// Route::get('/contact', [LandingPageController::class, 'contact'])->name('contact');
Route::get('/register', [LandingPageController::class, 'register'])->name('register');
Route::get('/login', function () {
    return Inertia::render('UserSide/Auth/UserLogin');
})->name('login');
Route::get('/forgot-password', function () {
    return Inertia::render('UserSide/Auth/Forgot');
})->name('password.request');

Route::post('/forgot-password/otp', [ForgotPasswordOtpController::class, 'sendOtp'])
    ->middleware('throttle:5,1')
    ->name('password.otp.send');

Route::post('/forgot-password/otp/resend', [ForgotPasswordOtpController::class, 'resendOtp'])
    ->middleware('throttle:6,1')
    ->name('password.otp.resend');

Route::post('/forgot-password/otp/verify', [ForgotPasswordOtpController::class, 'verifyOtp'])
    ->middleware('throttle:10,1')
    ->name('password.otp.verify');

Route::post('/forgot-password/reset', [ForgotPasswordOtpController::class, 'resetPassword'])
    ->middleware('throttle:6,1')
    ->name('password.otp.reset');

Route::get('/otp', function (Request $request) {
    return Inertia::render('UserSide/Auth/Otp', [
        'email' => $request->query('email'),
        'status' => session('status'),
    ]);
})->name('password.otp');
Route::get('/new-password', function (Request $request) {
    return Inertia::render('UserSide/Auth/NewPassword', [
        'email' => $request->query('email'),
        'status' => session('status'),
    ]);
})->name('password.new');
Route::get('/shop-owner-register', [LandingPageController::class, 'shopOwnerRegister'])->name('shop-owner-register');

// Employee Invitation Routes (Public - No Authentication Required)
Route::get('/invite/{token}', [InvitationController::class, 'show'])->name('invitation.show');
Route::post('/invite/{token}', [InvitationController::class, 'accept'])->name('invitation.accept');
// Alias route for accept-invitation (used by invitation links)
Route::get('/accept-invitation/{token}', [InvitationController::class, 'show'])->name('invitation.accept-invitation');
Route::post('/accept-invitation/{token}', [InvitationController::class, 'accept'])->name('invitation.accept-invitation.submit');

// Cart Routes
Route::get('/api/cart', [CartController::class, 'index'])->name('cart.index');
Route::post('/api/cart/add', [CartController::class, 'add'])->middleware('auth:user')->name('cart.add');
Route::post('/api/cart/remove', [CartController::class, 'remove'])->middleware('auth:user')->name('cart.remove');
Route::post('/api/cart/update', [CartController::class, 'update'])->middleware('auth:user')->name('cart.update');
Route::post('/api/cart/clear', [CartController::class, 'clear'])->middleware('auth:user')->name('cart.clear');
Route::post('/api/cart/sync', [CartController::class, 'sync'])->middleware('auth:user')->name('cart.sync');

// Customer Conversation Routes - Customer-side chat with shops
Route::prefix('api/customer/conversations')->middleware(['auth:user', 'customer.account'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Customer\ConversationController::class, 'index']);
    Route::get('/shops', [\App\Http\Controllers\Api\Customer\ConversationController::class, 'getContactedShops']);
    Route::post('/get-or-create', [\App\Http\Controllers\Api\Customer\ConversationController::class, 'getOrCreate']);
    Route::get('/{conversation}/messages', [\App\Http\Controllers\Api\Customer\ConversationController::class, 'getMessages']);
    Route::post('/{conversation}/messages', [\App\Http\Controllers\Api\Customer\ConversationController::class, 'sendMessage']);
});

// Customer Badge Counts - Real-time counts for navigation header icons
Route::get('/api/customer/badge-counts', function () {
    if (!Auth::guard('user')->check()) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $user = Auth::guard('user')->user();

    $orderTypes = [
        'order_placed',
        'order_confirmed',
        'order_shipped',
        'order_delivered',
        'order_cancelled',
        'order_status_update',
    ];

    $repairTypes = [
        'repair_submitted',
        'repair_assigned',
        'repair_accepted',
        'repair_rejected',
        'repair_in_progress',
        'repair_completed',
        'repair_ready_pickup',
        'repair_status_update',
    ];

    $orderStatusCount = \App\Models\Notification::query()
        ->where('user_id', $user->id)
        ->where('is_read', false)
        ->whereIn('type', $orderTypes)
        ->count();

    $repairStatusCount = \App\Models\Notification::query()
        ->where('user_id', $user->id)
        ->where('is_read', false)
        ->whereIn('type', $repairTypes)
        ->count();

    $chatIconCount = \App\Models\ConversationMessage::query()
        ->whereHas('conversation', function ($query) use ($user) {
            $query->where('customer_id', $user->id);
        })
        ->where(function ($query) use ($user) {
            $query->where('sender_type', '!=', 'customer')
                ->orWhere(function ($legacyQuery) use ($user) {
                    $legacyQuery->whereNull('sender_type')
                        ->where('sender_id', '!=', $user->id);
                });
        })
        ->whereNull('read_at')
        ->count();

    return response()->json([
        'orderStatusCount' => $orderStatusCount,
        'repairStatusCount' => $repairStatusCount,
        'chatIconCount' => $chatIconCount,
        'userIconCount' => $orderStatusCount + $repairStatusCount,
    ]);
})->middleware('auth:user');

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
Route::post('/api/checkout/promo-preview', [CheckoutController::class, 'previewPromoPricing'])->middleware('auth:user')->name('checkout.promo-preview');
Route::get('/api/my-orders', [CheckoutController::class, 'myOrders'])->middleware('auth:user')->name('api.my-orders');

// User Login Page
Route::get('/user/login', function () {
    return Inertia::render('UserSide/Auth/UserLogin');
})->name('user.login.form');

// Shop Owner Login Page (redirect to customer login)
Route::get('/shop-owner/login', function () {
    return redirect()->route('user.login.form');
})->name('shop-owner.login.form');

// User Authentication Routes
Route::get('/auth/check-email-availability', [UserController::class, 'checkEmailAvailability'])->name('auth.check-email-availability');
Route::post('/user/register', [UserController::class, 'register'])->name('user.register');
Route::post('/user/login', [UserController::class, 'login'])->name('user.login');
Route::post('/user/logout', [UserController::class, 'logout'])->name('user.logout');
Route::get('/api/user/me', [UserController::class, 'me'])->middleware('auth:user')->name('user.me');

// Shop Owner Authentication Routes
Route::post('/shop-owner/email-verification/send-code', [ShopOwnerAuthController::class, 'sendRegistrationEmailOtp'])
    ->middleware('throttle:5,1')
    ->name('shop-owner.email-verification.send-code');
Route::post('/shop-owner/email-verification/verify-code', [ShopOwnerAuthController::class, 'verifyRegistrationEmailOtp'])
    ->middleware('throttle:10,1')
    ->name('shop-owner.email-verification.verify-code');
Route::post('/shop-owner/register', [ShopOwnerAuthController::class, 'register'])->name('shop-owner.register');
Route::post('/shop-owner/login', [ShopOwnerAuthController::class, 'login'])->name('shop-owner.login');
Route::post('/shop-owner/logout', [ShopOwnerAuthController::class, 'logout'])->name('shop-owner.logout');
Route::get('/shop-owner/two-factor', [ShopOwnerAuthController::class, 'showTwoFactorChallenge'])->name('shop-owner.two-factor.challenge');
Route::post('/shop-owner/two-factor/verify', [ShopOwnerAuthController::class, 'verifyLoginTwoFactorOtp'])
    ->middleware('throttle:10,1')
    ->name('shop-owner.two-factor.verify');
Route::post('/shop-owner/two-factor/resend', [ShopOwnerAuthController::class, 'resendLoginTwoFactorOtp'])
    ->middleware('throttle:6,1')
    ->name('shop-owner.two-factor.resend');

// Shop Owner Pending Approval Page
Route::get('/shop-owner/pending-approval', function () {
    $shopOwner = Auth::guard('shop_owner')->user();

    if (!$shopOwner) {
        return redirect()->route('shop-owner.login.form');
    }

    // If already approved, redirect to dashboard
    if ($shopOwner->status === 'approved' && $shopOwner->password) {
        return redirect()->route('shop-owner.dashboard');
    }

    return Inertia::render('Auth/PendingApproval', [
        'shopOwner' => [
            'email' => $shopOwner->email,
            'business_name' => $shopOwner->business_name,
            'status' => $shopOwner->status,
            'email_verified_at' => $shopOwner->email_verified_at,
            'created_at' => $shopOwner->created_at,
            'rejection_reason' => $shopOwner->rejection_reason,
        ]
    ]);
})->middleware('auth:shop_owner')->name('shop-owner.pending-approval');

// Shop Owner Password Setup Routes (no authentication required - validated by token)
Route::get('/shop-owner/setup-password', [ShopOwnerPasswordSetupController::class, 'show'])
    ->name('shop-owner.password.setup');
Route::post('/shop-owner/setup-password', [ShopOwnerPasswordSetupController::class, 'store'])
    ->name('shop-owner.password.setup.store');

// Shop Owner notifications page
Route::get('/shop-owner/notifications', function () {
    return Inertia::render('Notifications/ShopOwnerNotifications');
})->middleware('auth:shop_owner')->name('shop-owner.notifications.index');

// Shop Owner notification preferences
Route::get('/shop-owner/notifications/settings', function () {
    return Inertia::render('Notifications/ShopOwnerPreferences');
})->middleware('auth:shop_owner')->name('shop-owner.notifications.settings');

// TEST ROUTE - Remove after debugging
Route::get('/test-auth', function () {
    return response()->json([
        'authenticated' => Auth::check(),
        'guard_user_check' => Auth::guard('user')->check(),
        'user' => Auth::user(),
        'guard_user' => Auth::guard('user')->user(),
        'session_id' => session()->getId(),
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
    Route::post('/flagged-accounts/{id}/mark-reviewed', [FlaggedAccountsController::class, 'markReviewed'])->name('flagged-accounts.mark-reviewed');
    Route::post('/flagged-accounts/{id}/dismiss', [FlaggedAccountsController::class, 'dismiss'])->name('flagged-accounts.dismiss');
    Route::post('/flagged-accounts/{id}/ban', [FlaggedAccountsController::class, 'ban'])->name('flagged-accounts.ban');
    Route::get('/shop-owner-registration-view', [ShopOwnerRegistrationViewController::class, 'index'])->name('shop-owner-registration-view');
    Route::post('/shop-owner-registration/{id}/approve', [ShopOwnerRegistrationViewController::class, 'approve'])->name('shop-owner-approve');
    Route::post('/shop-owner-registration/{id}/reject', [ShopOwnerRegistrationViewController::class, 'reject'])->name('shop-owner-reject');
    Route::get('/system-monitoring-dashboard', [SystemMonitoringDashboardController::class, 'index'])->name('system-monitoring-dashboard');
    Route::get('/notification-communication-tools', [NotificationCommunicationToolsController::class, 'index'])->name('notification-communication-tools');
    Route::get('/data-report-access', [DataReportAccessController::class, 'index'])->name('data-report-access');
});

// Shop Owner Routes
Route::prefix('shopOwner')->name('shopOwner.')->group(function () {
    Route::get('/ecommerce', [EcommerceController::class, 'index'])->name('ecommerce');

    // User Access Control - Business only (to manage employees)
    Route::get('/user-access-control', [UserAccessControlController::class, 'index'])
        ->middleware('check.registration.type:company')
        ->name('user-access-control');

    // Suspend Accounts page for Shop Owner (frontend page)
    Route::get('/suspend-accounts', function () {
        return Inertia::render('ShopOwner/TeamManagement/suspendAccount');
    })->name('suspend-accounts');

    Route::get('/refund-approvals', function () {
        return Inertia::render('ShopOwner/Approvals/refundApproval');
    })->name('refund-approvals');
});

// Shop Owner Protected Routes
Route::middleware(['auth:shop_owner'])->get('/point-of-sale', function (\Illuminate\Http\Request $request) {
    $query = $request->getQueryString();

    return redirect('/shop-owner/point-of-sale' . ($query ? ('?' . $query) : ''));
})->name('shop-owner.point-of-sale.legacy');

Route::middleware('auth:shop_owner')->prefix('shop-owner')->name('shop-owner.')->group(function () {
    // Dashboard - Available to ALL shop owners
    Route::get('/dashboard', function () {
        $shopOwner = Auth::guard('shop_owner')->user();
        return Inertia::render('ShopOwner/Dashboard', ['shop_owner' => $shopOwner]);
    })->name('dashboard');

    Route::get('/point-of-sale', function () {
        return Inertia::render('ShopOwner/Repairs/service management/POS');
    })->name('point-of-sale');

    // PRODUCT MANAGEMENT - Retail or Both only
    Route::middleware('check.business.type:retail,both')->group(function () {
        Route::get('/products', function () {
            return Inertia::render('ShopOwner/Products/product management/ProductManagementWithVariants');
        })->name('products');

        Route::get('/product-uploder', function () {
            return Inertia::render('ShopOwner/Products/product management/ProductManagementWithVariants');
        })->name('product-uploder');

        Route::get('/inventory-overview', function () {
            return Inertia::render('ShopOwner/Products/product management/InventoryOverview');
        })->middleware('check.registration.type:company')->name('inventory-overview');
    });

    // SERVICE MANAGEMENT - Repair or Both only
    Route::middleware('check.business.type:repair,both')->group(function () {
        Route::get('/job-orders-repair', function () {
            $shopOwner = Auth::guard('shop_owner')->user();
            return Inertia::render('ShopOwner/Repairs/service management/JobOrdersRepair', [
                'repair_workload_limit' => (int) ($shopOwner->repair_workload_limit ?? 20),
            ]);
        })->name('job-orders-repair');

        Route::get('/warranty-queue', function () {
            return Inertia::render('ShopOwner/Repairs/service management/WarrantyQueue');
        })->middleware('check.registration.type:individual')->name('warranty-queue');

        Route::get('/upload-services', function () {
            return Inertia::render('ShopOwner/Repairs/service management/uploadService');
        })->name('upload-services');

        Route::get('/upload-stock-materials', function () {
            return Inertia::render('ShopOwner/Repairs/individual/uploadStockMaterial');
        })->middleware('check.registration.type:individual')->name('upload-stock-materials');

        Route::middleware('check.registration.type:company')->group(function () {
            Route::get('/repair-reject-approval', function () {
                return Inertia::render('ShopOwner/Repairs/repairRejectReview');
            })->name('repair-reject-approval');

            Route::get('/history-rejection', function () {
                return Inertia::render('ShopOwner/Repairs/historyRejection');
            })->name('history-rejection');
        });
    });

    // ORDERS - Available to ALL
    Route::get('/job-orders-retail', function () {
        return Inertia::render('ShopOwner/Orders/order management/JobOrders');
    })->middleware('check.business.type:retail,both')->name('job-orders-retail');

    // CUSTOMERS - Available to ALL
    Route::get('/customers', function () {
        return Inertia::render('ShopOwner/Customers/customer management/Customers');
    })->name('customers');

    Route::get('/customer-support', function () {
        return Inertia::render('ShopOwner/Customers/customer management/customerSupport');
    })->middleware('check.business.type:retail,both')->name('customer-support');

    Route::get('/repair-support', function () {
        return Inertia::render('ShopOwner/Customers/customer management/repairSupport');
    })->middleware('check.business.type:repair,both')->name('repair-support');

    Route::get('/customer-reviews', function () {
        return Inertia::render('ShopOwner/Customers/customer management/CustomersReviews');
    })->name('customer-reviews');

    // SHOP PROFILE - Available to ALL
    Route::get('/shop-profile', [\App\Http\Controllers\ShopOwner\ShopProfileController::class, 'index'])->name('shop-profile');
    Route::post('/shop-profile', [\App\Http\Controllers\ShopOwner\ShopProfileController::class, 'update'])->name('shop-profile.update');
    Route::post('/shop-profile/password', [\App\Http\Controllers\ShopOwner\ShopProfileController::class, 'updatePassword'])
        ->middleware('throttle:5,1')
        ->name('shop-profile.password.update');

    // SHOP SETTINGS - Available to ALL
    Route::get('/settings', [ShopSettingsController::class, 'index'])->name('settings');
    Route::put('/settings', [ShopSettingsController::class, 'update'])->name('settings.update');
    Route::post('/settings/paymongo-key', [ShopSettingsController::class, 'updatePaymongoKey'])->name('settings.paymongo-key');
    Route::delete('/settings/paymongo-key', [ShopSettingsController::class, 'removePaymongoKey'])->name('settings.paymongo-key.remove');
    Route::post('/settings/geofence', [ShopSettingsController::class, 'updateGeofence'])->name('settings.geofence');

    // PREMIUM BENEFITS - Retail-capable shops only
    Route::get('/premium-benefits', function () {
        $shopOwner = \Illuminate\Support\Facades\Auth::guard('shop_owner')->user();

        $businessType = strtolower(trim((string) ($shopOwner?->business_type ?? '')));
        $hasRepairSignal = str_contains($businessType, 'repair') || str_contains($businessType, 'service');
        $hasRetailSignal = str_contains($businessType, 'retail') || str_contains($businessType, 'shoe') || str_contains($businessType, 'product');

        if ($shopOwner && $hasRepairSignal && !$hasRetailSignal) {
            return redirect()->route('shop-owner.settings');
        }

        return Inertia::render('ShopOwner/Premium/premuimBenefits');
    })->name('premium-benefits');

    Route::get('/premium/benefits', function () {
        return redirect()->route('shop-owner.premium-benefits');
    })->name('premium-benefits-legacy');

    Route::get('/premium/cancel', function (Request $request) {
        $subscriptionId = (int) $request->query('subscription_id');
        $shopOwner = \Illuminate\Support\Facades\Auth::guard('shop_owner')->user();

        if ($shopOwner && $subscriptionId > 0) {
            \App\Models\ShopOwnerSubscription::where('id', $subscriptionId)
                ->where('shop_owner_id', (int) $shopOwner->id)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled']);
        }

        return redirect()->route('shop-owner.premium-benefits');
    })->name('premium-cancel');

    Route::get('/premium/success', function (Request $request) {
        $subscriptionId = $request->query('subscription_id');

        $shopOwner = \Illuminate\Support\Facades\Auth::guard('shop_owner')->user();

        if ($shopOwner && !empty($subscriptionId)) {
            $subscription = \App\Models\ShopOwnerSubscription::with('premiumPlan')
                ->where('id', (int) $subscriptionId)
                ->where('shop_owner_id', (int) $shopOwner->id)
                ->first();

            if ($subscription && in_array($subscription->status, ['pending', 'failed'], true) && !empty($subscription->paymongo_session_id)) {
                try {
                    $apiKey = config('services.paymongo.secret_key');

                    if (!empty($apiKey)) {
                        $response = \Illuminate\Support\Facades\Http::withHeaders([
                            'Content-Type' => 'application/json',
                            'Authorization' => 'Basic ' . base64_encode($apiKey . ':'),
                        ])->get('https://api.paymongo.com/v1/checkout_sessions/' . $subscription->paymongo_session_id);

                        if ($response->ok()) {
                            $attributes = (array) $response->json('data.attributes', []);
                            $payments = is_array($attributes['payments'] ?? null) ? $attributes['payments'] : [];
                            $paymentStatus = strtolower((string) ($attributes['payment_status'] ?? ''));
                            $hasPaidSignal = $paymentStatus === 'paid' || count($payments) > 0;

                            if ($hasPaidSignal && in_array($subscription->status, ['pending', 'failed'], true)) {
                                $startsAt = now();
                                $paymentId = $payments[0]['id'] ?? null;
                                $durationDays = max(1, (int) ($subscription->premiumPlan?->duration_days ?? 30));
                                $endsAt = $startsAt->copy()->addDays($durationDays);

                                \Illuminate\Support\Facades\DB::transaction(function () use ($subscription, $startsAt, $endsAt, $paymentId) {
                                    $lockedSubscription = \App\Models\ShopOwnerSubscription::query()
                                        ->where('id', $subscription->id)
                                        ->lockForUpdate()
                                        ->first();

                                    if (!$lockedSubscription || !in_array($lockedSubscription->status, ['pending', 'failed'], true)) {
                                        return;
                                    }

                                    $updatePayload = [
                                        'status' => 'active',
                                        'paymongo_payment_id' => $paymentId,
                                        'starts_at' => $startsAt,
                                        'ends_at' => $endsAt,
                                    ];

                                    if (
                                        \Illuminate\Support\Facades\Schema::hasColumn('shop_owner_subscriptions', 'auto_renew')
                                        && \Illuminate\Support\Facades\Schema::hasColumn('shop_owner_subscriptions', 'auto_renew_status')
                                    ) {
                                        $updatePayload['auto_renew'] = true;
                                        $updatePayload['auto_renew_status'] = \App\Models\ShopOwnerSubscription::AUTO_RENEW_STATUS_ENABLED;
                                    }

                                    $lockedSubscription->update($updatePayload);

                                    if ($lockedSubscription->replaces_subscription_id) {
                                        $source = \App\Models\ShopOwnerSubscription::query()
                                            ->where('id', (int) $lockedSubscription->replaces_subscription_id)
                                            ->lockForUpdate()
                                            ->first();

                                        if ($source && $source->status === 'active') {
                                            $sourceUpdate = [
                                                'status' => 'cancelled',
                                                'ends_at' => $startsAt,
                                            ];

                                            if (
                                                \Illuminate\Support\Facades\Schema::hasColumn('shop_owner_subscriptions', 'auto_renew')
                                                && \Illuminate\Support\Facades\Schema::hasColumn('shop_owner_subscriptions', 'auto_renew_status')
                                            ) {
                                                $sourceUpdate['auto_renew'] = false;
                                                $sourceUpdate['auto_renew_status'] = \App\Models\ShopOwnerSubscription::AUTO_RENEW_STATUS_DISABLED;
                                            }

                                            if (\Illuminate\Support\Facades\Schema::hasColumn('shop_owner_subscriptions', 'pending_premium_plan_id')) {
                                                $sourceUpdate['pending_premium_plan_id'] = null;
                                            }

                                            if (\Illuminate\Support\Facades\Schema::hasColumn('shop_owner_subscriptions', 'pending_plan_effective_at')) {
                                                $sourceUpdate['pending_plan_effective_at'] = null;
                                            }

                                            $source->update($sourceUpdate);
                                        }
                                    }
                                });

                                return redirect()
                                    ->route('shop-owner.premium-benefits')
                                    ->with('success', 'Payment confirmed. Your premium subscription is now active.');
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Premium success verification fallback failed', [
                        'subscription_id' => $subscription->id,
                        'session_id' => $subscription->paymongo_session_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return redirect()
            ->route('shop-owner.premium-benefits')
            ->with('success', 'Premium subscription payment was completed successfully. Activation may take a moment while payment is being confirmed.')
            ->with('premium_subscription_id', $subscriptionId);
    })->name('premium-success');

    // DSS INSIGHTS - Available to all individual accounts (repair, retail, both) AND company accounts
    Route::get('/dss-insights', function () {
        return Inertia::render('ShopOwner/DssInsights');
    })->name('dss-insights');

    // VOUCHERS & DISCOUNT - Retail-capable shops only
    Route::get('/vouchers-discount', function () {
        return Inertia::render('ShopOwner/Orders/order management/discount');
    })->middleware('check.business.type:retail,both')->name('vouchers-discount');

    // AUDIT LOGS - Company only (individual has no staff to audit)
    Route::middleware('check.registration.type:company')->group(function () {
        Route::get('/audit-logs', function () {
            return Inertia::render('ShopOwner/Settings/AuditLogs');
        })->name('audit-logs');
    });

    // REFUND APPROVALS - Available to ALL
    Route::get('/refund-approvals', function () {
        return Inertia::render('ShopOwner/Approvals/refundApproval');
    })->name('refund-approvals');

    // PRICE APPROVALS - Business only (for approving staff price changes)
    Route::middleware('check.registration.type:company')->group(function () {
        Route::get('/price-approvals', function () {
            return Inertia::render('ShopOwner/Approvals/PriceApprovals');
        })->name('price-approvals');

        Route::get('/payslip-approvals', function () {
            return Inertia::render('ShopOwner/Approvals/PayslipApproval');
        })->name('payslip-approvals');

        Route::get('/purchase-request-approval', function () {
            return Inertia::render('ShopOwner/Approvals/PurchaseRequestApproval');
        })->name('purchase-request-approval');

        Route::get('/expense-approvals', function () {
            return Inertia::render('ShopOwner/Approvals/ExpenseApproval');
        })->name('expense-approvals');

        Route::get('/salary-adjustment-approvals', function () {
            return Inertia::render('ShopOwner/Approvals/SalaryChangesApproval');
        })->name('salary-adjustment-approvals');
    });

    // STAFF/EMPLOYEE MANAGEMENT - Business only
    Route::middleware('check.registration.type:company')->group(function () {
        Route::post('/employees', [UserAccessControlController::class, 'storeEmployee'])->name('employees.store');
        Route::delete('/employees/{employee}', [\App\Http\Controllers\EmployeeController::class, 'destroy'])->middleware('shop.isolation')->name('employees.destroy');
        Route::post('/employees/{employee}/suspend', [\App\Http\Controllers\EmployeeController::class, 'suspend'])->middleware('shop.isolation')->name('employees.suspend');
        Route::post('/employees/{employee}/activate', [\App\Http\Controllers\EmployeeController::class, 'activate'])->middleware('shop.isolation')->name('employees.activate');

        // Get allowed roles based on business type
        Route::get('/roles/allowed', [UserAccessControlController::class, 'getAllowedRoles'])->name('roles.allowed');

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
    Route::get('dashboard/dss-insights', [\App\Http\Controllers\ShopOwner\DssController::class, 'getInsights'])->name('api.shop_owner.dashboard.dss-insights');
    Route::get('orders', [\App\Http\Controllers\ShopOwner\OrderController::class, 'index'])->middleware('check.business.type:retail,both');
    Route::get('orders/{id}', [\App\Http\Controllers\ShopOwner\OrderController::class, 'show'])->middleware('check.business.type:retail,both');
    Route::patch('orders/{id}/status', [\App\Http\Controllers\ShopOwner\OrderController::class, 'updateStatus'])->middleware('check.business.type:retail,both');

    // Profile
    Route::post('upload-profile-photo', [\App\Http\Controllers\ShopOwner\ShopProfileController::class, 'uploadPhoto']);
    Route::post('upload-cover-photo', [\App\Http\Controllers\ShopOwner\ShopProfileController::class, 'uploadCoverPhoto']);
    Route::delete('profile-photo', [\App\Http\Controllers\ShopOwner\ShopProfileController::class, 'removeProfilePhoto']);
    Route::delete('cover-photo', [\App\Http\Controllers\ShopOwner\ShopProfileController::class, 'removeCoverPhoto']);

    // Price Change Approvals
    Route::get('price-changes/pending', [\App\Http\Controllers\Api\PriceChangeRequestController::class, 'ownerPending'])->middleware('check.business.type:retail,both');
    Route::post('price-changes/{id}/approve', [\App\Http\Controllers\Api\PriceChangeRequestController::class, 'ownerApprove'])->middleware('check.business.type:retail,both');
    Route::post('price-changes/{id}/reject', [\App\Http\Controllers\Api\PriceChangeRequestController::class, 'ownerReject'])->middleware('check.business.type:retail,both');

    // Payslip Approvals (Shop Owner Portal)
    Route::prefix('payslip-approvals')->middleware('check.registration.type:company')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\Finance\PayslipApprovalController::class, 'getPayslipsForApproval'])->name('shop_owner.payslip_approval.index');
        Route::post('/batch/final-approve', [\App\Http\Controllers\Api\Finance\PayslipApprovalController::class, 'batchFinalApprove'])->name('shop_owner.payslip_approval.batch_final_approve');
        Route::get('/{id}', [\App\Http\Controllers\Api\Finance\PayslipApprovalController::class, 'getPayslipForApproval'])->whereNumber('id')->name('shop_owner.payslip_approval.show');
        Route::post('/{id}/final-approve', [\App\Http\Controllers\Api\Finance\PayslipApprovalController::class, 'finalApprovePayslip'])->whereNumber('id')->name('shop_owner.payslip_approval.final_approve');
    });
});

// Staff Price Change Requests (session-based auth)
Route::middleware('auth:user')->prefix('api/price-change-requests')->group(function () {
    Route::get('/my-pending', [\App\Http\Controllers\Api\PriceChangeRequestController::class, 'myPending']);
    Route::post('/{id}/cancel', [\App\Http\Controllers\Api\PriceChangeRequestController::class, 'cancelRequest']);
});

// CSRF Token endpoint for API requests
Route::get('/api/csrf-token', function () {
    return response()->json(['csrf_token' => csrf_token()]);
});

// Staff API Routes (session-based authentication)
Route::middleware('auth:user')->prefix('api/staff')->group(function () {
    Route::get('inventory-overview', [\App\Http\Controllers\Api\StaffInventoryController::class, 'index']);
    Route::get('orders', [\App\Http\Controllers\Api\StaffOrderController::class, 'index'])
        ->middleware('permission:access-staff-job-orders');
    Route::get('orders/{id}', [\App\Http\Controllers\Api\StaffOrderController::class, 'show'])
        ->middleware('permission:access-staff-job-orders');
    Route::patch('orders/{id}/status', [\App\Http\Controllers\Api\StaffOrderController::class, 'updateStatus'])
        ->middleware('permission:access-staff-job-orders');
    Route::post('orders/{id}/confirm-return-received', [\App\Http\Controllers\Api\StaffOrderController::class, 'confirmReturnReceived'])
        ->middleware('permission:access-staff-job-orders');
    Route::post('orders/{id}/arrange-return-pickup', [\App\Http\Controllers\Api\StaffOrderController::class, 'arrangeReturnPickup'])
        ->middleware('permission:access-staff-job-orders');
    Route::post('orders/{id}/complete', [\App\Http\Controllers\Api\StaffOrderController::class, 'complete'])
        ->middleware('permission:access-staff-job-orders');
    Route::post('orders/{id}/activate-pickup', [\App\Http\Controllers\Api\StaffOrderController::class, 'activatePickup'])
        ->middleware('permission:access-staff-job-orders');
});

// Product API Routes (public and shop owner)
Route::prefix('api/products')->group(function () {
    // Public routes (customers)
    Route::get('/', [\App\Http\Controllers\Api\ProductController::class, 'index']);
    Route::get('{slug}', [\App\Http\Controllers\Api\ProductController::class, 'show']);
    Route::post('{productId}/vouchers/{campaignId}/claim', [\App\Http\Controllers\UserSide\ProductVoucherController::class, 'claim'])
        ->middleware(['auth:user', 'customer.account'])
        ->name('api.products.vouchers.claim');

    // Variant stock check (public)
    Route::post('{id}/variant-stock', [\App\Http\Controllers\Api\ProductController::class, 'getVariantStock']);

    // Shop Owner & Staff routes (authenticated - accepts both auth:user and auth:shop_owner)
    Route::middleware('auth:user,shop_owner')->group(function () {
        Route::get('meta/showroom-entitlement', [\App\Http\Controllers\Api\ProductController::class, 'showroomEntitlement'])
            ->middleware('permission:access-product-upload-staff|access-product-management');
        Route::get('my/products', [\App\Http\Controllers\Api\ProductController::class, 'myProducts'])
            ->middleware('throttle:120,1')
            ->middleware('permission:access-product-upload-staff|access-product-management');
        Route::post('/', [\App\Http\Controllers\Api\ProductController::class, 'store'])
            ->middleware('permission:access-product-upload-staff|access-product-management');
        Route::put('{id}', [\App\Http\Controllers\Api\ProductController::class, 'update'])
            ->middleware('permission:access-product-upload-staff|access-product-management');
        Route::delete('{id}', [\App\Http\Controllers\Api\ProductController::class, 'destroy'])
            ->middleware('throttle:120,1')
            ->middleware('permission:access-product-upload-staff|access-product-management');
        Route::post('upload-image', [\App\Http\Controllers\Api\ProductController::class, 'uploadImage'])
            ->middleware('throttle:180,1')
            ->middleware('permission:access-product-upload-staff|access-product-management');
        Route::get('{id}/variants', [\App\Http\Controllers\Api\ProductController::class, 'getVariants'])
            ->middleware('permission:access-product-upload-staff|access-product-management');

        // Price Change Request - Staff must create approval request instead of direct update
        Route::post('price-change-request', [\App\Http\Controllers\Api\ProductController::class, 'createPriceChangeRequest'])
            ->middleware('permission:access-shoe-pricing');
        Route::post('{id}/request-price-change', [\App\Http\Controllers\Api\PriceChangeRequestController::class, 'store'])
            ->middleware('permission:access-shoe-pricing');

        // Color Variant Management
        Route::get('{productId}/color-variants', [\App\Http\Controllers\Api\ProductController::class, 'getColorVariants'])
            ->middleware('permission:access-product-upload-staff|access-product-management');
        Route::post('{productId}/color-variants', [\App\Http\Controllers\Api\ProductController::class, 'storeColorVariant'])
            ->middleware('permission:access-product-upload-staff|access-product-management');
        Route::put('{productId}/color-variants/{colorVariantId}', [\App\Http\Controllers\Api\ProductController::class, 'updateColorVariant'])
            ->middleware('permission:access-product-upload-staff|access-product-management');
        Route::delete('{productId}/color-variants/{colorVariantId}', [\App\Http\Controllers\Api\ProductController::class, 'deleteColorVariant'])
            ->middleware('throttle:180,1')
            ->middleware('permission:access-product-upload-staff|access-product-management');

        // Color Variant Image Management
        Route::post('{productId}/color-variants/{colorVariantId}/images', [\App\Http\Controllers\Api\ProductController::class, 'uploadColorVariantImage'])
            ->middleware('throttle:240,1')
            ->middleware('permission:access-product-upload-staff|access-product-management');
        Route::put('{productId}/color-variants/{colorVariantId}/images/{imageId}', [\App\Http\Controllers\Api\ProductController::class, 'updateColorVariantImage'])
            ->middleware('permission:access-product-upload-staff|access-product-management');
        Route::delete('{productId}/color-variants/{colorVariantId}/images/{imageId}', [\App\Http\Controllers\Api\ProductController::class, 'deleteColorVariantImage'])
            ->middleware('throttle:240,1')
            ->middleware('permission:access-product-upload-staff|access-product-management');
        Route::post('{productId}/color-variants/{colorVariantId}/images/reorder', [\App\Http\Controllers\Api\ProductController::class, 'reorderColorVariantImages'])
            ->middleware('throttle:240,1')
            ->middleware('permission:access-product-upload-staff|access-product-management');
    });
});

// Repair Services API Routes - Public access for viewing
Route::prefix('api/repair-services')->group(function () {
    // Get all repair services (with filtering) - Public
    Route::get('/', [\App\Http\Controllers\Api\RepairServiceController::class, 'index']);

    // Get single repair service - Public
    Route::get('{id}', [\App\Http\Controllers\Api\RepairServiceController::class, 'show']);

    // Protected routes requiring authentication
    Route::middleware('auth:user')->group(function () {
        // Create repair service (Staff, Manager, and Repairer)
        Route::post('/', [\App\Http\Controllers\Api\RepairServiceController::class, 'store'])
            ->middleware('permission:access-upload-service|access-pricing-services');

        // Update repair service (Staff, Manager, and Repairer)
        Route::put('{id}', [\App\Http\Controllers\Api\RepairServiceController::class, 'update'])
            ->middleware('permission:access-upload-service|access-pricing-services');

        // Delete repair service (Staff, Manager, and Repairer)
        Route::delete('{id}', [\App\Http\Controllers\Api\RepairServiceController::class, 'destroy'])
            ->middleware('permission:access-upload-service|access-pricing-services');

        // Finance approval routes
        Route::get('finance/pending', [\App\Http\Controllers\Api\RepairServiceController::class, 'financePending'])
            ->middleware('permission:access-repair-price-approval');
        Route::post('{id}/finance/approve', [\App\Http\Controllers\Api\RepairServiceController::class, 'financeApprove'])
            ->middleware('permission:access-repair-price-approval');
        Route::post('{id}/finance/reject', [\App\Http\Controllers\Api\RepairServiceController::class, 'financeReject'])
            ->middleware('permission:access-repair-price-approval');
    });
});

// Repair Packages API Routes
Route::prefix('api/repair-packages')->group(function () {
    // Public listing for customer-side browsing (active packages)
    Route::get('/public', [\App\Http\Controllers\Api\RepairPackageController::class, 'publicIndex']);

    // Protected routes — accepts both ERP staff (auth:user) and direct shop owners (auth:shop_owner).
    // The controller's resolveShopOwnerId() already scopes each action to the correct shop.
    // Using auth:user,shop_owner avoids duplicate route definitions that would silently overwrite each other.
    Route::middleware('auth:user,shop_owner')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\RepairPackageController::class, 'index']);
        Route::get('/analytics', [\App\Http\Controllers\Api\RepairPackageController::class, 'analytics']);
        Route::post('/', [\App\Http\Controllers\Api\RepairPackageController::class, 'store']);
        Route::get('{id}', [\App\Http\Controllers\Api\RepairPackageController::class, 'show']);
        Route::put('{id}', [\App\Http\Controllers\Api\RepairPackageController::class, 'update']);
        Route::delete('{id}', [\App\Http\Controllers\Api\RepairPackageController::class, 'destroy']);
    });
});

// Repair Request API Routes
Route::prefix('api/repair-requests')->group(function () {
    // Submit repair request - Protected (customers must be logged in)
    Route::post('/', [\App\Http\Controllers\Api\RepairRequestController::class, 'store'])
        ->middleware('auth:user');

    // Get all repair requests - Protected (Staff/Manager only)
    Route::middleware('auth:user')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\RepairRequestController::class, 'index']);
        Route::post('{requestId}/status', [\App\Http\Controllers\Api\RepairRequestController::class, 'updateStatus']);
    });
});

// Customer Repair Management API Routes (Phase 2)
Route::middleware('auth:user')->prefix('api/customer/repairs')->group(function () {
    // Get customer's repair requests
    Route::get('/', [\App\Http\Controllers\Api\RepairRequestController::class, 'myRepairs']);

    // Get single repair request
    Route::get('{id}', [\App\Http\Controllers\Api\RepairRequestController::class, 'show']);

    // Cancel repair request
    Route::post('{id}/cancel', [\App\Http\Controllers\Api\RepairRequestController::class, 'cancel']);

    // Confirm pickup
    Route::post('{id}/confirm-pickup', [\App\Http\Controllers\Api\RepairRequestController::class, 'confirmPickup']);

    // Confirm repair after chat discussion (Phase 3)
    Route::post('{id}/confirm', [\App\Http\Controllers\Api\RepairRequestController::class, 'confirmRepair']);

    // Update payment link (PayMongo integration)
    Route::post('{id}/update-payment-link', [\App\Http\Controllers\Api\RepairRequestController::class, 'updatePaymentLink'])
        ->middleware('throttle:20,1');

    // Simulate payment for testing (bypasses PayMongo) - disabled in production
    if (!app()->environment('production')) {
        Route::post('{id}/simulate-payment', [\App\Http\Controllers\Api\RepairRequestController::class, 'simulatePayment'])
            ->middleware('throttle:10,1');
    }

    // Verify payment with PayMongo API (called when customer returns from checkout)
    Route::post('{id}/verify-payment', [\App\Http\Controllers\Api\RepairRequestController::class, 'verifyPayment'])
        ->middleware('throttle:20,1');

    // Retry payment session creation with fresh PayMongo checkout URL
    Route::post('{id}/retry-payment-session', [\App\Http\Controllers\Api\RepairRequestController::class, 'retryPaymentSession'])
        ->middleware('throttle:20,1');

    // Customer-initiated online refund request for myRepairs flow
    Route::post('{id}/refunds', [\App\Http\Controllers\Api\RepairRequestController::class, 'requestRefundFromMyRepair']);

    // Customer-initiated warranty claim flow
    Route::post('{id}/warranty-claims', [\App\Http\Controllers\Api\RepairWarrantyClaimController::class, 'store']);
    Route::get('{id}/warranty-claims/latest', [\App\Http\Controllers\Api\RepairWarrantyClaimController::class, 'latest']);

    // Phase 10D - Reviews & Ratings
    Route::post('{id}/review', [\App\Http\Controllers\Api\RepairReviewController::class, 'store']);
    Route::get('{id}/review', [\App\Http\Controllers\Api\RepairReviewController::class, 'getRepairReview']);
    Route::get('{id}/can-review', [\App\Http\Controllers\Api\RepairReviewController::class, 'canReview']);

    // Set preferred drop-off date after repairer accepts and customer chats
    Route::patch('{id}/schedule', [\App\Http\Controllers\Api\RepairRequestController::class, 'setSchedule']);

    // Change pickup/delivery method before final receipt confirmation
    Route::patch('{id}/delivery-method', [\App\Http\Controllers\Api\RepairRequestController::class, 'changeDeliveryMethod']);
});

Route::middleware(['auth:user', 'check.user.business.type:repair,both'])->prefix('api/repairer/refunds')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\RepairRefundWorkflowController::class, 'repairerQueue']);
    Route::post('{refund}/approve', [\App\Http\Controllers\Api\RepairRefundWorkflowController::class, 'repairerApprove']);
    Route::post('{refund}/reject', [\App\Http\Controllers\Api\RepairRefundWorkflowController::class, 'repairerReject']);
});

Route::middleware(['auth:user', 'check.user.business.type:repair,both'])->prefix('api/repairer/warranty-claims')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\RepairerWarrantyClaimController::class, 'index']);
    Route::get('/kpi', [\App\Http\Controllers\Api\RepairerWarrantyClaimController::class, 'metrics']);
    Route::post('{claim}/approve', [\App\Http\Controllers\Api\RepairerWarrantyClaimController::class, 'approve']);
    Route::post('{claim}/reject', [\App\Http\Controllers\Api\RepairerWarrantyClaimController::class, 'reject']);
});

Route::middleware(['auth:shop_owner', 'check.business.type:repair,both'])->prefix('api/shop-owner/warranty-claims')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\RepairerWarrantyClaimController::class, 'index']);
    Route::get('/kpi', [\App\Http\Controllers\Api\RepairerWarrantyClaimController::class, 'metrics']);
    Route::post('{claim}/approve', [\App\Http\Controllers\Api\RepairerWarrantyClaimController::class, 'approve']);
    Route::post('{claim}/reject', [\App\Http\Controllers\Api\RepairerWarrantyClaimController::class, 'reject']);
});

Route::middleware(['auth:user', 'permission:access-refund-approval'])->prefix('api/finance/repair-refunds')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\RepairRefundWorkflowController::class, 'financeIndex']);
    Route::post('{refund}/approve', [\App\Http\Controllers\Api\RepairRefundWorkflowController::class, 'financeApprove']);
    Route::post('{refund}/reject', [\App\Http\Controllers\Api\RepairRefundWorkflowController::class, 'financeReject']);
    Route::post('{refund}/execute', [\App\Http\Controllers\Api\RepairRefundWorkflowController::class, 'financeExecute']);
});

// Shop repair capacity check — publicly accessible per shop (no auth needed; returns counts only)
Route::get('api/customer/shop/{shopOwnerId}/repair-capacity', [\App\Http\Controllers\Api\RepairRequestController::class, 'shopRepairCapacity']);

// Repair Workflow API Routes (Internal - Phase 2)
Route::middleware(['auth:user', 'check.user.business.type:repair,both'])->prefix('api/workflow/repairs')->group(function () {
    // Auto-assign repairer
    Route::post('{id}/assign', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'assignToRepairer']);

    // Get workflow status
    Route::get('{id}/status', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'getWorkflowStatus']);

    // Calculate high value threshold
    Route::post('{id}/calculate-high-value', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'calculateHighValue']);
});

// Repairer API Routes (Phase 3 - Chat Integration)
Route::middleware(['auth:user', 'check.user.business.type:repair,both'])->prefix('api/repairer')->group(function () {
    // Dashboard statistics
    Route::get('/dashboard', [\App\Http\Controllers\Repairer\DashboardController::class, 'getDashboardData']);

    // Repair material stock + requests (repairer view)
    Route::get('/materials', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'repairStocksOverview'])
        ->middleware('permission:access-repair-stocks');
    Route::get('/material-requests', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'myMaterialRequests'])
        ->middleware('permission:access-repair-stocks');
    Route::post('/material-requests', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'createMaterialRequest'])
        ->middleware('permission:access-repair-stocks');
    Route::post('/material-requests/bulk', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'createBulkMaterialRequests'])
        ->middleware('permission:access-repair-stocks');
});

// Public shop-hours fetch — no auth needed, used by the customer schedule modal
Route::get('/api/repair/shop-hours', [\App\Http\Controllers\Api\RepairAvailabilityController::class, 'shopHours']);

Route::middleware(['auth:user', 'check.user.business.type:repair,both'])->prefix('api/repairer/repairs')->group(function () {
    // Get assigned repairs
    Route::get('/', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'myAssignedRepairs']);

    // Accept repair (creates conversation)
    Route::post('{id}/accept', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'acceptRepair']);

    // Reject repair (escalates to manager)
    Route::post('{id}/reject', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'rejectRepair']);

    // Mark shoes as received (after pickup from customer)
    Route::post('{id}/mark-received', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'markAsReceived']);

    // Change delivery method (before shoes are received)
    Route::patch('{id}/delivery-method', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'changeDeliveryMethod']);

    // Phase 8: Work Progress Tracking
    Route::post('{id}/start-work', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'startWork']);
    Route::post('{id}/mark-awaiting-parts', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'markAwaitingParts']);
    Route::post('{id}/resume-work', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'resumeWork']);
    Route::post('{id}/mark-completed', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'markCompleted']);
    Route::post('{id}/materials/validate-start', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'validateMaterialStart']);
    Route::post('{id}/materials/validate-complete', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'validateMaterialCompletion']);
    Route::post('{id}/mark-ready', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'markReadyForPickup']);
    Route::post('{id}/ship', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'shipRepair']);
    Route::post('{id}/activate-pickup', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'activatePickup']);

    // Repair material usage tracking
    Route::get('{id}/materials', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'getRepairMaterialUsage'])
        ->middleware('permission:access-repair-stocks');
    Route::post('{id}/materials', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'logRepairMaterialUsage'])
        ->middleware('permission:access-repair-stocks');
    Route::delete('{id}/materials/{usageId}', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'removeRepairMaterialUsage'])
        ->middleware('permission:access-repair-stocks');

    // Activate payment for specific repair request
    Route::post('{id}/activate-payment', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'activatePaymentForRepair']);
    Route::post('{id}/mark-paid-in-shop', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'markPaidInShop']);

    // Ship a repair (ready-for-pickup → shipped)
    Route::post('{id}/ship', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'shipRepair']);
});

// Manager API Routes (Phase 5 - Rejection Review)
// Accessible by Manager role or explicit manager-level permissions
Route::middleware(['auth:user', 'role_or_permission:Manager|access-manager-dashboard|access-repair-reject-review'])->prefix('api/manager/repairs')->group(function () {
    // Get repairs pending manager review
    Route::get('/rejected', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'getPendingManagerReviews']);

    // Get rejection history timeline
    Route::get('/rejection-history', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'getRejectionHistory']);

    // Approve repairer's rejection
    Route::post('{id}/approve-rejection', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'approveRejection']);

    // Final manager approval to close rejection workflow
    Route::post('{id}/finalize-rejection', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'finalizeRejection']);

    // Override rejection and reassign
    Route::post('{id}/override-rejection', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'overrideRejection']);

    // Get available repairers for a repair (with skill matching)
    Route::get('{id}/available-repairers', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'getAvailableRepairersForRepair']);
});

// Shop Owner API Routes (Phase 6 - High-Value Approval)
Route::middleware(['auth:shop_owner', 'check.business.type:repair,both'])->prefix('api/shop-owner/repairs')->group(function () {
    // Get high-value repairs pending owner approval
    Route::get('/high-value-pending', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'getHighValuePendingApprovals']);

    // Approve high-value repair
    Route::post('{id}/approve-high-value', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'approveHighValueRepair']);

    // Reject high-value repair
    Route::post('{id}/reject-high-value', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'rejectHighValueRepair']);

    // Rejection workflow owner approval routes
    Route::get('/rejection-pending', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'getOwnerRejectionPendingApprovals']);
    Route::post('{id}/approve-rejection', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'approveOwnerRejection']);
    Route::post('{id}/reject-rejection', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'rejectOwnerRejection']);

    // Ship a repair (ready-for-pickup → shipped)
    Route::post('{id}/ship', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'shipRepair']);
});

// Phase 10D - Shop owner review responses
Route::middleware('auth:shop_owner')->prefix('api/reviews')->group(function () {
    Route::post('{id}/respond', [\App\Http\Controllers\Api\RepairReviewController::class, 'respond']);
});

// Repair Services - Shop Owner Routes
Route::middleware(['auth:shop_owner', 'check.business.type:repair,both'])->prefix('api/repair-services')->group(function () {
    Route::get('owner/pending', [\App\Http\Controllers\Api\RepairServiceController::class, 'ownerPending']);
    Route::post('{id}/owner/approve', [\App\Http\Controllers\Api\RepairServiceController::class, 'ownerApprove']);
    Route::post('{id}/owner/reject', [\App\Http\Controllers\Api\RepairServiceController::class, 'ownerReject']);
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

// Shop Reviews API (Verified Service Completion System)
Route::prefix('api/shops/{shopId}/reviews')->group(function () {
    // Public route - Get all reviews for a shop
    Route::get('/', [\App\Http\Controllers\Api\ShopReviewController::class, 'index']);

    // Check if user can review this shop (controller handles auth check)
    Route::get('check-eligibility', [\App\Http\Controllers\Api\ShopReviewController::class, 'checkEligibility']);

    // Submit a new review (verified service completion only - controller handles auth check)
    Route::post('/', [\App\Http\Controllers\Api\ShopReviewController::class, 'store']);
});

// Shop Reports API — customers submit reports against shops
Route::post('/api/shops/{shopId}/report', [\App\Http\Controllers\Api\ReportShopController::class, 'store'])
    ->middleware('auth:user')
    ->name('api.shops.report');

// Public route to serve review images
Route::get('/storage/reviews/{filename}', function ($filename) {
    $path = storage_path('app/public/reviews/' . $filename);

    if (!file_exists($path)) {
        abort(404);
    }

    return response()->file($path);
})->where('filename', '.*');

// Session-backed API endpoints for finance
// CONSOLIDATED: All finance routes under /api/finance/session with finance access permissions
Route::middleware(['auth:user', 'permission:access-finance-dashboard|access-finance-expenses|access-finance-invoices', 'shop.isolation'])->prefix('api/finance/session')->group(function () {
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

    // Expense approval (users with approval permission)
    Route::middleware('permission:approve-expenses')->group(function () {
        Route::post('expenses/{id}/approve', [\App\Http\Controllers\Api\Finance\ExpenseController::class, 'approve']);
        Route::post('expenses/{id}/reject', [\App\Http\Controllers\Api\Finance\ExpenseController::class, 'reject']);
    });

    // Invoices
    Route::get('invoices', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'index']);
    Route::post('invoices', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'store']);
    Route::post('invoices/from-job', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'createFromJob']);
    Route::get('invoices/{id}', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'show']);
    Route::put('invoices/{id}', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'update']);
    Route::patch('invoices/{id}', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'update']);
    Route::delete('invoices/{id}', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'destroy']);
    Route::post('invoices/{id}/restore', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'restore']);
    Route::post('invoices/{id}/send', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'send']);
    Route::post('invoices/{id}/void', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'void']);
    Route::post('invoices/{id}/mark-paid', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'markAsPaid']);

    // Post to ledger (requires invoice finance access permission)
    Route::middleware('permission:access-finance-invoices')->group(function () {
        Route::post('invoices/{id}/post', [\App\Http\Controllers\Api\Finance\InvoiceController::class, 'post']);
    });

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

// Super Admin Notification API routes
Route::middleware('super_admin.auth')->prefix('api/admin/notifications')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\AdminNotificationController::class, 'index']);
    Route::get('/unread-count', [\App\Http\Controllers\Api\AdminNotificationController::class, 'unreadCount']);
    Route::post('/{id}/read', [\App\Http\Controllers\Api\AdminNotificationController::class, 'markAsRead']);
    Route::post('/read-all', [\App\Http\Controllers\Api\AdminNotificationController::class, 'markAllAsRead']);
    Route::delete('/{id}', [\App\Http\Controllers\Api\AdminNotificationController::class, 'destroy']);
});

// Shop Registration Routes
Route::get('/shop/register', function () {
    return Inertia::render('UserSide/Auth/ShopOwnerRegistration');
})->name('shop.register.form');
Route::post('/shop/register-full', [ShopRegistrationController::class, 'storeFullInertia'])->name('shop.register');

// Shop Message Route
Route::get('/shop/message', function () {
    return Inertia::render('UserSide/Communication/message', [
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
    Route::get('/subscription-management', [SuperAdminController::class, 'showSubscriptionManagement'])->name('subscription-management');
        Route::post('/subscriptions/{id}/cancel', [SuperAdminController::class, 'cancelSubscription'])->name('subscriptions.cancel');
        Route::post('/subscriptions/{id}/upgrade', [SuperAdminController::class, 'upgradeSubscription'])->name('subscriptions.upgrade');
        Route::post('/subscriptions/{id}/downgrade', [SuperAdminController::class, 'downgradeSubscription'])->name('subscriptions.downgrade');
        Route::post('/shops/{id}/suspend', [SuperAdminController::class, 'suspendShop'])->name('shops.suspend');
    Route::post('/shops/{id}/activate', [SuperAdminController::class, 'activateShop'])->name('shops.activate');
    Route::delete('/shops/{id}', [SuperAdminController::class, 'deleteShop'])->name('shops.delete');

    // User management routes
    Route::get('/user-management', [SuperAdminController::class, 'showUserManagement'])->name('user-management');
    Route::post('/users/{id}/suspend', [SuperAdminController::class, 'suspendUser'])->name('users.suspend');
    Route::post('/users/{id}/activate', [SuperAdminController::class, 'activateUser'])->name('users.activate');
    Route::delete('/users/{id}', [SuperAdminController::class, 'deleteUser'])->name('users.delete');

    // Shop Reports routes
    Route::get('/shop-reports', [\App\Http\Controllers\superAdmin\ShopReportsController::class, 'index'])->name('shop-reports');
    Route::post('/shop-reports/{id}/action', [\App\Http\Controllers\superAdmin\ShopReportsController::class, 'action'])->name('shop-reports.action');

    // Additional admin routes
    Route::get('/notifications', function () {
        return Inertia::render('superAdmin/Communications/NotificationCommunicationTools');
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
// ERP notifications page
Route::get('/erp/notifications', function () {
    return Inertia::render('Notifications/ERPNotifications');
})->middleware(['auth:user', 'check.suspension'])->name('erp.notifications.page');

// ERP notification preferences
Route::get('/erp/notifications/settings', function () {
    return Inertia::render('Notifications/ERPPreferences');
})->middleware(['auth:user', 'check.suspension'])->name('erp.notifications.settings');

// Time In/Out - First thing staff see after login
Route::middleware(['auth:user', 'check.suspension'])->get('/erp/time-in', function () {
    if (Auth::guard('user')->user()?->force_password_change) {
        return redirect()->route('erp.profile');
    }
    return Inertia::render('ERP/STAFF/TimeIn');
})->name('erp.time-in');

Route::middleware(['auth:user', 'check.suspension', 'permission:access-hr-dashboard|access-employee-directory|access-attendance-records|access-leave-approvals|access-overtime-approvals|access-payslip-generation|access-view-payslip'])->get('/erp/hr', function () {
    if (Auth::guard('user')->user()?->force_password_change) {
        return redirect()->route('erp.profile');
    }
    try {
        $controller = app(\App\Http\Controllers\Erp\HR\HRAnalyticsController::class);
        $response = $controller->dashboard(request());
        $initialHrDashboard = json_decode($response->getContent(), true);
    } catch (\Exception $e) {
        $initialHrDashboard = null;
    }
    return Inertia::render('ERP/HR/HR', compact('initialHrDashboard'));
        // Evaluate requires_owner_approval for each request
        $policyService = app(\App\Services\ShopOwnerApprovalPolicyService::class);
        $requests = $requests->map(function ($request) use ($policyService) {
            $payload = $request->toArray();
            $payload['requires_owner_approval'] = $policyService->requiresOwnerApprovalForPurchaseRequest(
                (int) $request->shop_owner_id,
                (float) $request->total_cost
            );

            return $payload;
        });
})->name('erp.hr');

// HR Audit Logs
Route::get('/erp/hr/audit-logs', function () {
    if (Auth::guard('user')->user()?->force_password_change) {
        return redirect()->route('erp.profile');
    }
    return Inertia::render('ERP/HR/AuditLogs');
})->middleware(['auth:user', 'permission:access-audit-logs'])->name('erp.hr.audit-logs');

Route::middleware(['auth:user', 'check.suspension'])->group(function () {
    Route::get('/erp/profile', [UserProfileController::class, 'show'])->name('erp.profile');
    Route::post('/erp/password', [UserProfileController::class, 'updatePassword'])->middleware('throttle:5,1')->name('erp.password.update');
});

// Finance pages
Route::prefix('finance')->name('finance.')->middleware(['auth:user', 'role_or_permission:Shop Owner|access-finance-dashboard|access-finance-expenses|access-finance-invoices|access-repair-price-approval|access-shoe-price-approval|access-approval-workflow|access-purchase-request-approval|access-payslip-approval|access-refund-approval'])->group(function () {
    Route::get('/', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }

        $purchaseRequests = collect();
        if (Auth::user()->can('viewAny', \App\Models\PurchaseRequest::class)) {
            $purchaseRequests = \App\Models\PurchaseRequest::query()
                ->with(['shopOwner', 'supplier', 'inventoryItem', 'requester', 'reviewer', 'approver'])
                ->where('shop_owner_id', Auth::user()->shop_owner_id)
                ->where('status', 'pending_finance')
                ->orderBy('requested_date', 'desc')
                ->get();
        }

        return Inertia::render('ERP/Finance/Finance', [
            'purchaseRequests' => $purchaseRequests
        ]);
    })->name('index');

    Route::get('/dashboard', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        $shopId = Auth::user()->shop_owner_id;
        $year   = now()->year;
        $yearStart = now()->copy()->startOfYear();
        $yearEnd = now()->copy()->endOfYear();

        $invoices = \App\Models\Finance\Invoice::where('shop_id', $shopId)
            ->whereBetween('date', [$yearStart->toDateString(), $yearEnd->toDateString()])
            ->with(['jobOrder' => function ($query) {
                $query->select(['id', 'payment_status']);
            }])
            ->select(['id', 'reference', 'status', 'total', 'tax_amount', 'meta', 'date', 'job_order_id'])
            ->orderBy('date', 'desc')
            ->get();

        $invoicePayload = $invoices->map(function ($invoice) {
            $paymentStatus = strtolower((string) ($invoice->jobOrder->payment_status ?? ''));
            $effectiveStatus = $paymentStatus === 'refunded'
                ? 'refunded'
                : (string) $invoice->status;

            return [
                'id' => $invoice->id,
                'reference' => $invoice->reference,
                'status' => $invoice->status,
                'effective_status' => $effectiveStatus,
                'total' => $invoice->total,
                'tax_amount' => $invoice->tax_amount,
                'meta' => $invoice->meta,
                'date' => optional($invoice->date)->toDateString(),
            ];
        })->values();

        $posTransactions = \App\Models\PosTransaction::query()
            ->where('shop_owner_id', $shopId)
            ->whereBetween(\Illuminate\Support\Facades\DB::raw('COALESCE(paid_at, created_at)'), [
                $yearStart->toDateTimeString(),
                $yearEnd->toDateTimeString(),
            ])
            ->whereIn('status', ['paid', 'partially_refunded', 'refunded'])
            ->select(['id', 'transaction_no', 'status', 'total_amount', 'tax_amount', 'paid_at', 'created_at'])
            ->orderByDesc(\Illuminate\Support\Facades\DB::raw('COALESCE(paid_at, created_at)'))
            ->get();

        $posRefundByTransaction = [];
        if ($posTransactions->isNotEmpty()) {
            $posRefundRows = \App\Models\PosRefund::query()
                ->select('source_transaction_id')
                ->selectRaw('SUM(COALESCE(approved_amount, requested_amount, 0)) as total_refunded')
                ->where('status', 'succeeded')
                ->whereIn('source_transaction_id', $posTransactions->pluck('id')->all())
                ->groupBy('source_transaction_id')
                ->get();

            foreach ($posRefundRows as $row) {
                $transactionId = (int) ($row->source_transaction_id ?? 0);
                if ($transactionId <= 0) {
                    continue;
                }
                $posRefundByTransaction[$transactionId] = (float) ($row->total_refunded ?? 0.0);
            }
        }

        $posRevenuePayload = $posTransactions->map(function ($transaction) use ($posRefundByTransaction) {
            $transactionStatus = strtolower((string) ($transaction->status ?? ''));
            $grossTotal = max(0.0, (float) ($transaction->total_amount ?? 0.0));
            $vatAmount = max(0.0, (float) ($transaction->tax_amount ?? 0.0));
            $preRefundNet = max(0.0, ($grossTotal - $vatAmount) > 0 ? ($grossTotal - $vatAmount) : $grossTotal);

            $refundAmount = max(0.0, (float) ($posRefundByTransaction[$transaction->id] ?? 0.0));
            if ($refundAmount <= 0 && $transactionStatus === 'refunded') {
                $refundAmount = $preRefundNet;
            }

            $netRevenue = max(0.0, $preRefundNet - min($preRefundNet, $refundAmount));
            $effectiveStatus = ($transactionStatus === 'refunded' && $netRevenue <= 0)
                ? 'refunded'
                : 'paid';

            return [
                'id' => 'pos-' . $transaction->id,
                'reference' => $transaction->transaction_no,
                'status' => $transactionStatus,
                'effective_status' => $effectiveStatus,
                'total' => $grossTotal,
                'tax_amount' => $vatAmount,
                'meta' => [
                    'subtotal_amount' => round($netRevenue, 2),
                ],
                'date' => optional($transaction->paid_at ?? $transaction->created_at)->toDateString(),
            ];
        })->values();

        $invoicePayload = $invoicePayload
            ->concat($posRevenuePayload)
            ->sortByDesc('date')
            ->values();

        $expenses = \App\Models\Finance\Expense::where('shop_id', $shopId)
            ->whereBetween('date', [$yearStart->toDateString(), $yearEnd->toDateString()])
            ->select(['id', 'reference', 'status', 'amount', 'date'])
            ->orderBy('date', 'desc')
            ->get();

        $orderRefunds = \App\Models\OrderRefund::query()
            ->where('shop_owner_id', $shopId)
            ->whereYear('refunded_at', $year)
            ->whereNotNull('refunded_at')
            ->where('status', 'succeeded')
            ->select(['id', 'order_id', 'amount', 'status', 'refunded_at', 'requested_at'])
            ->orderByDesc('refunded_at')
            ->get();

        $orderRefundLineById = collect();
        if (
            $orderRefunds->isNotEmpty()
            && \Illuminate\Support\Facades\Schema::hasTable('order_refund_items')
        ) {
            $orderRefundLineById = \Illuminate\Support\Facades\DB::table('order_refund_items')
                ->select('order_refund_id', \Illuminate\Support\Facades\DB::raw('SUM(COALESCE(line_amount, 0)) as line_total'))
                ->whereIn('order_refund_id', $orderRefunds->pluck('id')->all())
                ->groupBy('order_refund_id')
                ->pluck('line_total', 'order_refund_id');
        }

        $orderRefundPayload = $orderRefunds->map(function ($refund) use ($orderRefundLineById) {
            $lineAmount = (float) ($orderRefundLineById[$refund->id] ?? 0.0);
            $effectiveAmount = $lineAmount > 0
                ? $lineAmount
                : (float) ($refund->amount ?? 0.0);

            return [
                'id' => $refund->id,
                'order_id' => $refund->order_id,
                'amount' => round(max(0.0, $effectiveAmount), 2),
                'status' => $refund->status,
                'refunded_at' => optional($refund->refunded_at)->toDateTimeString(),
                'requested_at' => optional($refund->requested_at)->toDateTimeString(),
            ];
        })->values();

        $posRefunds = \App\Models\PosRefund::query()
            ->where('shop_owner_id', $shopId)
            ->where('status', 'succeeded')
            ->whereYear('executed_at', $year)
            ->whereNotNull('executed_at')
            ->select(['id', 'source_transaction_id', 'approved_amount', 'requested_amount', 'executed_at'])
            ->orderByDesc('executed_at')
            ->get();

        $posRefundPayload = $posRefunds->map(function ($refund) {
            $effectiveAmount = (float) ($refund->approved_amount ?? 0.0);
            if ($effectiveAmount <= 0) {
                $effectiveAmount = (float) ($refund->requested_amount ?? 0.0);
            }

            return [
                'id' => 'pos-' . $refund->id,
                'order_id' => null,
                'amount' => round(max(0.0, $effectiveAmount), 2),
                'status' => 'succeeded',
                'refunded_at' => optional($refund->executed_at)->toDateTimeString(),
                'requested_at' => optional($refund->executed_at)->toDateTimeString(),
            ];
        })->values();

        $refundPayload = $orderRefundPayload
            ->concat($posRefundPayload)
            ->sortByDesc('refunded_at')
            ->values();

        $refundedRevenue = $refundPayload->sum(function ($refund) {
            return (float) ($refund['amount'] ?? 0.0);
        });

        return Inertia::render('ERP/Finance/Dashboard', [
            'invoices' => $invoicePayload,
            'expenses' => $expenses,
            'refunds' => $refundPayload,
            'refundedRevenue' => $refundedRevenue,
        ]);
    })->name('dashboard');

    Route::get('/purchase-request-approval', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }

        abort_unless(Auth::user()->can('viewAny', \App\Models\PurchaseRequest::class), 403);

        $requests = \App\Models\PurchaseRequest::query()
            ->with(['shopOwner', 'supplier', 'inventoryItem', 'requester', 'reviewer', 'approver'])
            ->where('shop_owner_id', Auth::user()->shop_owner_id)
            ->whereIn('status', ['pending_finance', 'pending_finance_final'])
            ->orderBy('requested_date', 'desc')
            ->get();

        $policyService = app(\App\Services\ShopOwnerApprovalPolicyService::class);
        $requests = $requests->map(function (\App\Models\PurchaseRequest $purchaseRequest) use ($policyService) {
            $payload = $purchaseRequest->toArray();
            $payload['requires_owner_approval'] = $policyService->requiresOwnerApprovalForPurchaseRequest(
                (int) $purchaseRequest->shop_owner_id,
                (float) $purchaseRequest->total_cost
            );
            $payload['approval_stage'] = $purchaseRequest->status === 'pending_finance_final'
                ? 'finance_final'
                : ($purchaseRequest->status === 'pending_finance' ? 'finance_initial' : null);

            return $payload;
        });

        return Inertia::render('ERP/Finance/PurchaseRequestApproval', [
            'requests' => $requests
        ]);
    })->middleware('permission:access-finance-dashboard|access-approval-workflow|access-purchase-request-approval')->name('purchase-request-approval');
});

// Finance Audit Logs
Route::get('/erp/finance/audit-logs', function () {
    if (Auth::guard('user')->user()?->force_password_change) {
        return redirect()->route('erp.profile');
    }
    return Inertia::render('ERP/Finance/AuditLogs');
})->middleware(['auth:user', 'permission:access-audit-logs'])->name('erp.finance.audit-logs');

// Approval Workflow page removed (frontend page deleted)

Route::get('/create-invoice', function () {
    if (Auth::guard('user')->user()?->force_password_change) {
        return redirect()->route('erp.profile');
    }
    return redirect('/finance?section=create-invoice');
})->middleware(['auth:user', 'permission:access-finance-invoices'])->name('finance.create-invoice');

// CRM routes
Route::prefix('crm')->name('crm.')->middleware(['auth:user', 'permission:access-crm-dashboard|access-crm-customers|access-customer-support|access-customer-reviews|access-crm-messages'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\CRM\CRMDashboardController::class, 'indexPage'])->name('dashboard');
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
    Route::get('/customers', [\App\Http\Controllers\Api\CRM\CRMCustomerController::class, 'indexPage'])->name('customers');
    Route::get('/customer-support', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/CRM/customerSupport');
    })->middleware('permission:access-customer-support')->name('customer-support');

    Route::get('/customer-reviews', [\App\Http\Controllers\Api\CRM\CRMReviewController::class, 'indexPage'])->name('customer-reviews');
});

// MANAGER routes (Manager role OR manager permissions)
Route::prefix('erp/manager')->name('erp.manager.')->middleware([
    'auth:user',
    'role_or_permission:Manager|access-manager-dashboard|access-audit-logs|access-manager-reports|access-inventory-overview|access-repair-reject-review|access-suspend-account'
])->group(function () {
    Route::get('/dashboard', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        $user = Auth::guard('user')->user();
        $shopOwnerId = $user->shop_owner_id ?? $user->id;
        $initialPendingLeaves = \App\Models\HR\LeaveRequest::where('shop_owner_id', $shopOwnerId)
            ->where('status', 'pending')
            ->with(['employee' => function ($query) {
                $query->select('id', 'name', 'email', 'position');
            }])
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($leave) {
                if (!$leave->employee) return null;
                return [
                    'id' => $leave->id,
                    'employee' => [
                        'id' => $leave->employee->id,
                        'name' => $leave->employee->name,
                        'email' => $leave->employee->email,
                        'position' => $leave->employee->position,
                    ],
                    'leave_type' => $leave->leave_type,
                    'leave_type_label' => \App\Models\HR\LeaveRequest::LEAVE_TYPES[$leave->leave_type] ?? $leave->leave_type,
                    'start_date' => $leave->start_date->format('Y-m-d'),
                    'end_date' => $leave->end_date->format('Y-m-d'),
                    'no_of_days' => $leave->no_of_days,
                    'reason' => $leave->reason,
                    'status' => $leave->status,
                    'created_at' => $leave->created_at->toIso8601String(),
                    'days_pending' => \Carbon\Carbon::parse($leave->created_at)->diffInDays(now()),
                ];
            })
            ->filter()
            ->values();
        return Inertia::render('ERP/Manager/Dashboard', compact('initialPendingLeaves'));
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
    Route::get('/shoe-pricing', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/STAFF/shoePricing');
    })->middleware(['permission:access-shoe-pricing', 'check.user.business.type:retail,both'])->name('shoe-pricing');
    Route::get('/products', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/Manager/productUpload');
    })->middleware('check.user.business.type:retail,both')->name('products');

    // Inventory routes - accessible by Inventory Manager (Manager needs explicit permission)
    Route::get('/inventory-overview', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/Manager/InventoryOverview');
    })->middleware('permission:access-inventory-overview')->name('inventory-overview');
    Route::get('/upload-stocks', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        $shopOwnerId = Auth::guard('user')->user()->shop_owner_id;
        $initialData = \App\Models\InventoryItem::with(['sizes', 'colorVariants.images', 'colorVariants.sizes', 'images'])
            ->where('shop_owner_id', $shopOwnerId)->orderBy('created_at', 'desc')->paginate(50);
        return Inertia::render('ERP/inventory/UploadInventory', compact('initialData'));
    })->middleware('permission:access-upload-inventory')->name('upload-stocks');
    Route::get('/inventory-dashboard', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        $shopOwnerId = Auth::guard('user')->user()->shop_owner_id;
        $initialData = \App\Models\InventoryItem::with(['sizes', 'colorVariants', 'images'])
            ->where('shop_owner_id', $shopOwnerId)->where('is_active', true)->orderBy('name')->paginate(200);
        $initialMetrics = ['total_items' => \App\Models\InventoryItem::where('shop_owner_id', $shopOwnerId)->where('is_active', true)->count(), 'low_stock_count' => \App\Models\InventoryItem::where('shop_owner_id', $shopOwnerId)->lowStock()->count(), 'out_of_stock_count' => \App\Models\InventoryItem::where('shop_owner_id', $shopOwnerId)->outOfStock()->count()];
        return Inertia::render('ERP/inventory/InventoryDashboard', compact('initialData', 'initialMetrics'));
    })->middleware('permission:access-inventory-dashboard')->name('inventory-dashboard');
    Route::get('/stock-movement', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        $shopOwnerId = Auth::guard('user')->user()->shop_owner_id;
        $initialData = \App\Models\StockMovement::with(['inventoryItem', 'performer'])
            ->whereHas('inventoryItem', fn($q) => $q->where('shop_owner_id', $shopOwnerId))
            ->orderBy('performed_at', 'desc')->paginate(200);
        return Inertia::render('ERP/inventory/StockMovement', compact('initialData'));
    })->middleware('permission:access-stock-movement')->name('stock-movement');
    Route::get('/product-inventory', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        $shopOwnerId = Auth::guard('user')->user()->shop_owner_id;
        $initialData = \App\Models\InventoryItem::with(['sizes', 'colorVariants', 'images'])
            ->where('shop_owner_id', $shopOwnerId)->where('is_active', true)->orderBy('name')->paginate(200);
        return Inertia::render('ERP/inventory/ProductInventory', compact('initialData'));
    })->middleware('permission:access-product-inventory')->name('product-inventory');
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
    // Repair rejection review: manager route limited to repair-capable businesses
    Route::get('/repair-rejection-review', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/Manager/repairRejectReview');
    })->middleware('check.user.business.type:repair,both')->name('repair-rejection-review');
    Route::get('/dss-insights', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ShopOwner/DssInsights');
    })->name('dss-insights');
});

// Note: Manager audit-logs route already defined above within the manager group

// INVENTORY MODULE routes (accessible by Inventory Manager role or users with explicit permissions)
Route::prefix('erp/inventory')->name('erp.inventory.')->middleware(['auth:user', 'permission:view-inventory|access-inventory-dashboard|access-product-inventory|access-stock-movement|access-upload-inventory'])->group(function () {
    Route::get('/upload-stocks', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        $shopOwnerId = Auth::guard('user')->user()->shop_owner_id;
        $initialData = \App\Models\InventoryItem::with(['sizes', 'colorVariants.images', 'colorVariants.sizes', 'images'])
            ->where('shop_owner_id', $shopOwnerId)->orderBy('created_at', 'desc')->paginate(50);
        return Inertia::render('ERP/inventory/UploadInventory', compact('initialData'));
    })->name('upload-stocks');

    Route::get('/inventory-dashboard', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        $shopOwnerId = Auth::guard('user')->user()->shop_owner_id;
        $initialData = \App\Models\InventoryItem::with(['sizes', 'colorVariants', 'images'])
            ->where('shop_owner_id', $shopOwnerId)->where('is_active', true)->orderBy('name')->paginate(200);
        $initialMetrics = ['total_items' => \App\Models\InventoryItem::where('shop_owner_id', $shopOwnerId)->where('is_active', true)->count(), 'low_stock_count' => \App\Models\InventoryItem::where('shop_owner_id', $shopOwnerId)->lowStock()->count(), 'out_of_stock_count' => \App\Models\InventoryItem::where('shop_owner_id', $shopOwnerId)->outOfStock()->count()];
        return Inertia::render('ERP/inventory/InventoryDashboard', compact('initialData', 'initialMetrics'));
    })->name('inventory-dashboard');

    Route::get('/stock-movement', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        $shopOwnerId = Auth::guard('user')->user()->shop_owner_id;
        $initialData = \App\Models\StockMovement::with(['inventoryItem', 'performer'])
            ->whereHas('inventoryItem', fn($q) => $q->where('shop_owner_id', $shopOwnerId))
            ->orderBy('performed_at', 'desc')->paginate(200);
        return Inertia::render('ERP/inventory/StockMovement', compact('initialData'));
    })->name('stock-movement');

    Route::get('/product-inventory', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        $shopOwnerId = Auth::guard('user')->user()->shop_owner_id;
        $initialData = \App\Models\InventoryItem::with(['sizes', 'colorVariants', 'images'])
            ->where('shop_owner_id', $shopOwnerId)->where('is_active', true)->orderBy('name')->paginate(200);
        return Inertia::render('ERP/inventory/ProductInventory', compact('initialData'));
    })->name('product-inventory');

    Route::get('/stock-request', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        $shopOwnerId = Auth::guard('user')->user()->shop_owner_id;
        $initialRequests = \App\Models\StockRequestApproval::with(['inventoryItem', 'requester'])
            ->where('shop_owner_id', $shopOwnerId)->orderBy('requested_date', 'desc')->paginate(200);
        $initialInventoryItems = \App\Models\InventoryItem::with(['sizes', 'colorVariants', 'images'])
            ->where('shop_owner_id', $shopOwnerId)->where('is_active', true)->orderBy('name')->paginate(200);
        return Inertia::render('ERP/inventory/StockRequest', compact('initialRequests', 'initialInventoryItems'));
    })->name('stock-request');

    Route::get('/request-material-approval', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/inventory/RequestApproval');
    })->middleware('check.user.business.type:repair,both')->name('request-material-approval');

    Route::get('/supplier-order-monitoring', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        $shopOwnerId = Auth::guard('user')->user()->shop_owner_id;
        $initialData = \App\Models\PurchaseOrder::with(['supplier', 'purchaseRequest.inventoryItem'])
            ->where('shop_owner_id', $shopOwnerId)->orderBy('ordered_date', 'desc')->paginate(200);
        return Inertia::render('ERP/inventory/SupplierOrderMonitoring', compact('initialData'));
    })->name('supplier-order-monitoring');

    Route::get('/stock-request-approval', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        $shopOwnerId = Auth::guard('user')->user()->shop_owner_id;
        $initialData = \App\Models\StockRequestApproval::with(['shopOwner', 'inventoryItem', 'requester', 'approver'])
            ->where('shop_owner_id', $shopOwnerId)
            ->where(function ($query) {
                $query->where('request_source', 'manual')
                    ->orWhere(function ($repairQuery) {
                        $repairQuery->where('request_source', 'repair')
                            ->whereNotNull('inventory_approved_date');
                    });
            })
            ->orderBy('requested_date', 'desc')
            ->paginate(100);
        return Inertia::render('ERP/Procurement/StockRequestApproval', compact('initialData'));
    })->name('stock-request-approval');

    Route::get('/purchase-request', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        $shopOwnerId = Auth::guard('user')->user()->shop_owner_id;
        $initialData = \App\Models\PurchaseRequest::with(['shopOwner', 'supplier', 'inventoryItem', 'requester', 'reviewer', 'approver'])
            ->where('shop_owner_id', $shopOwnerId)->orderBy('requested_date', 'desc')->paginate(100);
        $initialSuppliers = \App\Models\Supplier::where('shop_owner_id', $shopOwnerId)->orderBy('name')->get();
        $initialAcceptedRequests = \App\Models\StockRequestApproval::with(['inventoryItem', 'requester'])
            ->where('shop_owner_id', $shopOwnerId)->where('status', 'accepted')->orderBy('requested_date', 'desc')->paginate(200);
        return Inertia::render('ERP/Procurement/PurchaseRequest', compact('initialData', 'initialSuppliers', 'initialAcceptedRequests'));
    })->name('purchase-request');

    Route::get('/purchase-orders', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        $shopOwnerId = Auth::guard('user')->user()->shop_owner_id;
        $initialData = \App\Models\PurchaseOrder::with(['purchaseRequest', 'shopOwner', 'supplier', 'inventoryItem', 'orderer'])
            ->where('shop_owner_id', $shopOwnerId)->orderBy('ordered_date', 'desc')->paginate(100);
        $initialApprovedPRs = \App\Models\PurchaseRequest::with(['supplier', 'inventoryItem', 'requester'])
            ->where('shop_owner_id', $shopOwnerId)->approved()
            ->whereDoesntHave('purchaseOrders', fn($q) => $q->whereNotIn('status', ['cancelled']))
            ->orderBy('approved_date', 'desc')->get();
        return Inertia::render('ERP/Procurement/PurchaseOrders', compact('initialData', 'initialApprovedPRs'));
    })->name('purchase-orders');

    Route::get('/suppliers-management', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        $shopOwnerId = Auth::guard('user')->user()->shop_owner_id;
        $initialData = \App\Models\Supplier::where('shop_owner_id', $shopOwnerId)->orderBy('name')->paginate(100);
        return Inertia::render('ERP/Procurement/SuppliersManagement', compact('initialData'));
    })->name('suppliers-management');
});

// PROCUREMENT MODULE routes (accessible by Procurement Manager role or users with explicit permissions)
Route::prefix('erp/procurement')->name('erp.procurement.')->middleware(['auth:user', 'permission:view-procurement|access-procurement-dashboard|access-purchase-requests|access-purchase-orders|access-stock-request-approval|access-suppliers-management|access-supplier-order-monitoring'])->group(function () {

    Route::get('/purchase-request', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        $shopOwnerId = Auth::guard('user')->user()->shop_owner_id;
        $initialData = \App\Models\PurchaseRequest::with(['shopOwner', 'supplier', 'inventoryItem', 'requester', 'reviewer', 'approver'])
            ->where('shop_owner_id', $shopOwnerId)->orderBy('requested_date', 'desc')->paginate(100);
        $initialSuppliers = \App\Models\Supplier::where('shop_owner_id', $shopOwnerId)->orderBy('name')->get();
        $initialAcceptedRequests = \App\Models\StockRequestApproval::with(['inventoryItem', 'requester'])
            ->where('shop_owner_id', $shopOwnerId)->where('status', 'accepted')->orderBy('requested_date', 'desc')->paginate(200);
        return Inertia::render('ERP/Procurement/PurchaseRequest', compact('initialData', 'initialSuppliers', 'initialAcceptedRequests'));
    })->name('purchase-request');

    Route::get('/purchase-orders', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        $shopOwnerId = Auth::guard('user')->user()->shop_owner_id;
        $initialData = \App\Models\PurchaseOrder::with(['purchaseRequest', 'shopOwner', 'supplier', 'inventoryItem', 'orderer'])
            ->where('shop_owner_id', $shopOwnerId)->orderBy('ordered_date', 'desc')->paginate(100);
        $initialApprovedPRs = \App\Models\PurchaseRequest::with(['supplier', 'inventoryItem', 'requester'])
            ->where('shop_owner_id', $shopOwnerId)->approved()
            ->whereDoesntHave('purchaseOrders', fn($q) => $q->whereNotIn('status', ['cancelled']))
            ->orderBy('approved_date', 'desc')->get();
        return Inertia::render('ERP/Procurement/PurchaseOrders', compact('initialData', 'initialApprovedPRs'));
    })->name('purchase-orders');

    Route::get('/stock-request-approval', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        $shopOwnerId = Auth::guard('user')->user()->shop_owner_id;
        $initialData = \App\Models\StockRequestApproval::with(['shopOwner', 'inventoryItem', 'requester', 'approver'])
            ->where('shop_owner_id', $shopOwnerId)->orderBy('requested_date', 'desc')->paginate(100);
        return Inertia::render('ERP/Procurement/StockRequestApproval', compact('initialData'));
    })->name('stock-request-approval');

    Route::get('/suppliers-management', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        $shopOwnerId = Auth::guard('user')->user()->shop_owner_id;
        $initialData = \App\Models\Supplier::where('shop_owner_id', $shopOwnerId)->orderBy('name')->paginate(100);
        return Inertia::render('ERP/Procurement/SuppliersManagement', compact('initialData'));
    })->name('suppliers-management');
});

// STAFF routes (both MANAGER and STAFF can access)
Route::prefix('erp/staff')->name('erp.staff.')->middleware(['auth:user', 'manager.staff:staff'])->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\Staff\CustomerController::class, 'index'])
        ->middleware('permission:access-staff-dashboard')
        ->name('dashboard');
    Route::get('/job-orders', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        $user = Auth::guard('user')->user();
        $shopOwnerId = $user->shop_owner_id ?? $user->id;
        $initialOrders = \App\Models\Order::with(['items', 'customer', 'shopOwner', 'refunds' => fn ($refundQuery) => $refundQuery->orderByDesc('id')])
            ->where('shop_owner_id', $shopOwnerId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($order) {
                $itemSubtotal = (float) ($order->total_amount ?? 0);
                $shippingFee = (float) ($order->shipping_fee ?? 0);
            $latestRefund = $order->refunds->first();
                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer_name' => $order->customer_name ?? optional($order->customer)->name ?? 'Guest',
                    'customer_email' => $order->customer_email ?? optional($order->customer)->email ?? '',
                    'customer_phone' => $order->customer_phone ?? '',
                    'shipping_address' => $order->customer_address ?? '',
                    'total_amount' => $itemSubtotal,
                    'shipping_fee' => $shippingFee,
                    'grand_total' => $itemSubtotal + $shippingFee,
                    'status' => $order->status,
                    'payment_status' => $order->payment_status ?? 'pending',
                    'payment_method' => $order->payment_method ?? '',
                    'tracking_number' => $order->tracking_number ?? '',
                    'carrier_company' => $order->carrier_company ?? '',
                    'carrier_name' => $order->carrier_name ?? '',
                    'carrier_phone' => $order->carrier_phone ?? '',
                    'tracking_link' => $order->tracking_link ?? '',
                    'eta' => $order->eta ?? null,
                    'created_at' => $order->created_at->toISOString(),
                    'updated_at' => $order->updated_at->toISOString(),
                    'pickup_enabled' => $order->pickup_enabled ?? false,
                    'pickup_enabled_at' => $order->pickup_enabled_at ?? null,
                    'latest_refund' => $latestRefund ? [
                        'id' => (int) $latestRefund->id,
                        'status' => (string) $latestRefund->status,
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
                    ] : null,
                    'items' => $order->items->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'product_id' => $item->product_id,
                            'product_name' => $item->product_name,
                            'product_slug' => $item->product_slug ?? '',
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
        return Inertia::render('ERP/STAFF/JobOrders', compact('initialOrders'));
    })->middleware(['permission:access-staff-job-orders', 'check.user.business.type:retail,both'])->name('job-orders');

    Route::get('/repair-dashboard', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        try {
            $controller = app(\App\Http\Controllers\Repairer\DashboardController::class);
            $response = $controller->getDashboardData();
            $initialDashboard = json_decode($response->getContent(), true);
        } catch (\Exception $e) {
            $initialDashboard = null;
        }
        return Inertia::render('ERP/repairer/dashboardRepair', compact('initialDashboard'));
    })->middleware(['permission:access-repairer-dashboard', 'check.user.business.type:repair,both'])->name('repair-dashboard');

    Route::get('/job-orders-repair', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        $repairerUser = Auth::guard('user')->user();
        $shopOwner = $repairerUser?->shopOwner;
        return Inertia::render('ERP/repairer/JobOrdersRepair', [
            'repair_workload_limit' => (int) ($shopOwner?->repair_workload_limit ?? 20),
        ]);
    })->middleware(['permission:access-repair-job-orders', 'check.user.business.type:repair,both'])->name('job-orders-repair');

    Route::get('/warranty-queue', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }

        return Inertia::render('ERP/repairer/WarrantyQueue');
    })->middleware(['permission:access-repair-job-orders', 'check.user.business.type:repair,both'])->name('warranty-queue');

    Route::get('/upload-services', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/repairer/uploadService');
    })->middleware('check.user.business.type:repair,both')->name('upload-services');

    Route::get('/stocks-overview', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/repairer/repairStocksOverview');
    })->middleware(['permission:access-repair-stocks', 'check.user.business.type:repair,both'])->name('stocks-overview');

    Route::get('/request-material', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/repairer/requestMaterials');
    })->middleware(['permission:access-repair-stocks', 'check.user.business.type:repair,both'])->name('request-material');

    Route::get('/pricing-and-services', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        $user = Auth::guard('user')->user();
        $initialServices = \App\Models\RepairService::where('shop_owner_id', $user->shop_owner_id)
            ->orderBy('created_at', 'desc')
            ->get();
        return Inertia::render('ERP/repairer/PricingAndServices', compact('initialServices'));
    })->middleware(['permission:access-pricing-services', 'check.user.business.type:repair,both'])->name('pricing-services');

    Route::get('/shoe-pricing', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/STAFF/shoePricing');
    })->middleware(['permission:access-shoe-pricing', 'check.user.business.type:retail,both'])->name('shoe-pricing');

    Route::get('/repair-status', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/STAFF/RepairStatus');
    })->middleware(['permission:access-repair-job-orders', 'check.user.business.type:repair,both'])->name('repair-status');

    Route::get('/products', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }

        $user = Auth::guard('user')->user();
        $staffShopRegistrationType = strtolower((string) ($user?->shopOwner?->registration_type ?? ''));

        return Inertia::render('ERP/STAFF/ProductManagementWithVariants', [
            'staff_shop_registration_type' => $staffShopRegistrationType,
        ]);
    })->middleware(['permission:access-product-upload-staff', 'check.user.business.type:retail,both'])->name('products');
    Route::get('/payments', function () {
        return redirect()->route('erp.staff.products');
    });
    Route::get('/customers', [\App\Http\Controllers\Staff\CustomerController::class, 'index'])
        ->middleware('permission:access-staff-customers')
        ->name('customers');

    Route::get('/inventory-overview', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/Manager/InventoryOverview');
    })->name('inventory-overview');

    Route::get('/attendance', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/STAFF/timeIn');
    })->middleware('permission:access-staff-time-in')->name('attendance');
});

// My Payslips — accessible to ALL authenticated ERP employees (every role)
Route::get('/erp/my-payslips', function () {
    if (Auth::guard('user')->user()?->force_password_change) {
        return redirect()->route('erp.profile');
    }
    return Inertia::render('ERP/STAFF/MyPayslips');
})->middleware(['auth:user'])->name('erp.my-payslips');

// Repairer Support Route - Accessible to users with repairer conversation permissions
Route::get('/erp/staff/repairer-support', function () {
    if (Auth::guard('user')->user()?->force_password_change) {
        return redirect()->route('erp.profile');
    }
    return Inertia::render('ERP/repairer/repairerSupport');
})->middleware(['auth:user', 'permission:access-repairer-support', 'check.user.business.type:repair,both'])->name('erp.repairer.support');

// Repairer Pricing Route - Accessible to users with repair service management permissions
Route::get('/erp/repairer/pricing-and-services', function () {
    if (Auth::guard('user')->user()?->force_password_change) {
        return redirect()->route('erp.profile');
    }
    $user = Auth::guard('user')->user();
    $initialServices = \App\Models\RepairService::where('shop_owner_id', $user->shop_owner_id)
        ->orderBy('created_at', 'desc')
        ->get();
    return Inertia::render('ERP/repairer/PricingAndServices', compact('initialServices'));
})->middleware(['auth:user', 'permission:access-pricing-services', 'check.user.business.type:repair,both'])->name('erp.repairer.pricing-services');

// Cashier Point of Sale Route - unified POS for cashier users
Route::get('/erp/cashier/point-of-sale', function () {
    if (Auth::guard('user')->user()?->force_password_change) {
        return redirect()->route('erp.profile');
    }
    return Inertia::render('ERP/cashier/POS');
})->middleware(['auth:user', 'permission:access-unified-pos'])->name('erp.cashier.point-of-sale');

// Repairer Point of Sale Route - frontend cashier page for in-shop payments
Route::get('/erp/repairer/point-of-sale', function () {
    return redirect()->route('erp.cashier.point-of-sale');
})->middleware(['auth:user'])->name('erp.repairer.point-of-sale');

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
Route::prefix('api/manager')->name('api.manager.')->middleware([
    'web',
    'auth:user',
    'check.suspension',
    'role_or_permission:Manager|access-manager-dashboard|access-audit-logs|access-manager-reports|access-inventory-overview|access-repair-reject-review|access-suspend-account'
])->group(function () {
    Route::get('/dashboard/stats', [ManagerController::class, 'getDashboardStats'])->name('dashboard.stats');
    Route::get('/dss-insights', [\App\Http\Controllers\ShopOwner\DssController::class, 'getInsights'])->name('dss-insights');
    Route::get('/staff-performance', [ManagerController::class, 'getStaffPerformance'])->name('staff-performance');
    Route::get('/analytics', [ManagerController::class, 'getAnalytics'])->name('analytics');
    Route::get('/inventory-overview', [ManagerController::class, 'getInventoryOverview'])->name('inventory-overview');
    Route::get('/products', [ManagerController::class, 'getProducts'])->name('products');
    Route::get('/reports', [ManagerController::class, 'getReports'])->name('reports.index');
    Route::post('/reports/generate', [ManagerController::class, 'generateReport'])->name('reports.generate');
    Route::post('/reports/{id}/send', [ManagerController::class, 'sendReport'])->name('reports.send');
    Route::get('/reports/{id}/download', [ManagerController::class, 'downloadReport'])->name('reports.download');

    // Suspension Approval Routes
    Route::prefix('suspension-requests')->group(function () {
        Route::get('/', [\App\Http\Controllers\Erp\Manager\SuspensionApprovalController::class, 'index'])->name('suspension_requests.index');
        Route::get('/{id}', [\App\Http\Controllers\Erp\Manager\SuspensionApprovalController::class, 'show'])->name('suspension_requests.show');
        Route::post('/{id}/review', [\App\Http\Controllers\Erp\Manager\SuspensionApprovalController::class, 'review'])->name('suspension_requests.review');
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
        ->middleware('role_or_permission:Manager|Finance Manager|Super Admin|Shop Owner|access-leave-approvals')
        ->name('pending');
    Route::post('/{id}/approve', [LeaveController::class, 'approve'])
        ->middleware('role_or_permission:Manager|Finance Manager|Super Admin|Shop Owner|access-leave-approvals')
        ->name('approve');
    Route::post('/{id}/reject', [LeaveController::class, 'reject'])
        ->middleware('role_or_permission:Manager|Finance Manager|Super Admin|Shop Owner|access-leave-approvals')
        ->name('reject');
});

// Legacy API Routes
Route::post('/api/shop/register', [ShopRegistrationController::class, 'store']);
Route::post('/api/shop/register-full', [ShopRegistrationController::class, 'storeFull']);

// Module-specific API routes are registered in bootstrap/app.php via withRouting(... then: ...)
