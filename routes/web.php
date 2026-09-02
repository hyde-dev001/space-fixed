<?php

use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\ManagerController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\ForgotPasswordOtpController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\PrivateSensitiveDocumentController;
use App\Http\Controllers\PrivilegedMfaController;
use App\Http\Controllers\PrivilegedPasswordResetController;
use App\Http\Controllers\PrivilegedReauthenticationController;
use App\Http\Controllers\PrivilegedSecurityController;
use App\Http\Controllers\PrivilegedSetupController;
use App\Http\Controllers\ShopOwner\EcommerceController;
use App\Http\Controllers\ShopOwner\ShopOwnerDocumentRenewalController;
use App\Http\Controllers\ShopOwner\ShopOwnerDashboardController;
use App\Http\Controllers\ShopOwner\ShopOwnerModuleController;
use App\Http\Controllers\ShopOwner\ShopOwnerUpgradeRequestController;
use App\Http\Controllers\ShopOwner\OwnerActionCenterController;
use App\Http\Controllers\ShopOwner\OwnerActionCenterSummaryController;
use App\Http\Controllers\ShopOwner\ShopSettingsController;
use App\Http\Controllers\ShopOwner\UserAccessControlController;
use App\Http\Controllers\ShopOwnerAuthController;
use App\Http\Controllers\ShopOwnerPasswordSetupController;
use App\Http\Controllers\superAdmin\AdministratorManagementController;
use App\Http\Controllers\superAdmin\FlaggedAccountsController;
use App\Http\Controllers\superAdmin\PremiumPlanController;
use App\Http\Controllers\superAdmin\PrivilegedAuditController;
use App\Http\Controllers\superAdmin\RegisteredShopController;
use App\Http\Controllers\superAdmin\ShopDocumentRenewalController as SuperAdminShopDocumentRenewalController;
use App\Http\Controllers\superAdmin\ShopOwnerRegistrationViewController;
use App\Http\Controllers\superAdmin\ShopOwnerUpgradeRequestController as SuperAdminShopOwnerUpgradeRequestController;
use App\Http\Controllers\superAdmin\SubscriptionInterventionController;
use App\Http\Controllers\superAdmin\SubscriptionManagementController;
use App\Http\Controllers\superAdmin\SystemMonitoringDashboardController;
use App\Http\Controllers\superAdmin\UserInterventionController;
use App\Http\Controllers\SuperAdminAuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserProfileController;
use App\Http\Controllers\UserSide\CartController;
use App\Http\Controllers\UserSide\CheckoutController;
use App\Http\Controllers\UserSide\CustomerProfileController;
use App\Http\Controllers\UserSide\LandingPageController;
use App\Http\Controllers\UserSide\OrderController;
use App\Http\Middleware\AttachPrivilegedCorrelationId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

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
    $user = Auth::guard('user')->user() ?? Auth::guard('shop_owner')->user();

    return Inertia::render('UserSide/Auth/VerificationNotice', [
        'status' => session('status'),
        'email' => $user ? $user->email : null,
    ]);
})->middleware('auth:user,shop_owner')->name('verification.notice');

Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware(['signed'])
    ->name('verification.verify');

Route::post('/email/verification-notification', function (Request $request) {
    // Support both regular user and shop owner guards.
    $user = Auth::guard('user')->user() ?? Auth::guard('shop_owner')->user();

    if ($user && ! $user->hasVerifiedEmail()) {
        $user->sendEmailVerificationNotification();
    }

    return back()->with('status', 'verification-link-sent');
})->middleware(['auth:user,shop_owner', 'throttle:6,1'])->name('verification.send');

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
Route::get('/payment-return/order', function (Request $request) {
    $query = array_filter([
        'paymongo_success' => $request->query('paymongo_success'),
        'paymongo_failed' => $request->query('paymongo_failed'),
        'pending_order_id' => $request->query('pending_order_id'),
        'return_ts' => $request->query('return_ts'),
        'return_sig' => $request->query('return_sig'),
    ], static fn ($value) => $value !== null && $value !== '');

    $target = '/order-success';
    if (! empty($query)) {
        $target .= '?'.http_build_query($query);
    }

    return redirect($target);
})->name('payment-return.order');
Route::get('/payment-return/repair', function (Request $request) {
    $query = array_filter([
        'paymongo_success' => $request->query('paymongo_success'),
        'paymongo_failed' => $request->query('paymongo_failed'),
        'pending_repair_id' => $request->query('pending_repair_id'),
        'return_ts' => $request->query('return_ts'),
        'return_sig' => $request->query('return_sig'),
    ], static fn ($value) => $value !== null && $value !== '');

    $target = '/my-repairs';
    if (! empty($query)) {
        $target .= '?'.http_build_query($query);
    }

    return redirect($target);
})->name('payment-return.repair');
Route::get('/payment-failed', function () {
    return Inertia::render('UserSide/Orders/PaymentFailed');
})->name('payment-failed');
Route::get('/my-orders', [OrderController::class, 'index'])->name('my-orders');
Route::post('/orders/confirm-delivery', [OrderController::class, 'confirmDelivery'])->name('orders.confirm-delivery');
Route::post('/orders/{order}/delivery-disputes', [OrderController::class, 'reportDeliveryIssue'])
    ->middleware('auth:user')
    ->name('orders.delivery-disputes.store');
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
Route::get('/api/policies/shops/{shopOwnerId}/active', [\App\Http\Controllers\Api\ShopPolicyController::class, 'active']);
Route::middleware('auth:user')->get('/api/policies/shops/{shopOwnerId}/prefill', [\App\Http\Controllers\Api\ShopPolicyController::class, 'prefill']);
Route::middleware('auth:user')->post('/api/policies/checkout/context', [\App\Http\Controllers\Api\ShopPolicyController::class, 'checkoutContext']);
Route::get('/erp/user/repair-reject-approval', [OwnerActionCenterController::class, 'legacyRedirect'])
    ->defaults('legacy_approval_family', 'repair_rejection')
    ->middleware('auth:user')
    ->name('erp.user.repair-reject-approval');
Route::get('/repair-services', [LandingPageController::class, 'repair'])->name('repair');
Route::get('/repair-shop/{id}', [LandingPageController::class, 'repairShow'])->name('repair.show');
// Customer conversations / Chat with repairer
Route::get('/customer/conversations', function () {
    return Inertia::render('UserSide/Communication/message');
})->middleware(['auth:user', 'customer.account'])->name('customer.conversations');
// Message / Chat with shop owner
Route::get('/message/{shopOwnerId?}', function ($shopOwnerId = null) {
    return Inertia::render('UserSide/Communication/message', [
        'shopOwnerId' => $shopOwnerId ? (int) $shopOwnerId : null,
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
Route::get('/tracking/shipments/{shipment}', [\App\Http\Controllers\Logistics\CustomerTrackingController::class, 'show'])
    ->middleware('auth:user')
    ->name('tracking.shipments.show');
Route::get('/tracking/shipments/{shipment}/proofs/{proof}', [\App\Http\Controllers\Logistics\CustomerTrackingController::class, 'deliveryProof'])
    ->middleware('auth:user')
    ->name('customer.tracking.delivery-proof');
Route::get('/tracking/shipments/{shipment}/attempts/{attempt}/proof', [\App\Http\Controllers\Logistics\CustomerTrackingController::class, 'attemptProof'])
    ->middleware('auth:user')
    ->name('customer.tracking.attempt-proof');

Route::get('/shop-profile/{id}', [LandingPageController::class, 'shopProfile'])->name('shop-profile');
Route::get('/shop-profile/{id}/virtual-showroom', [LandingPageController::class, 'virtualShowroom'])
    ->middleware('has.active.retail.premium')
    ->name('shop-profile.virtual-showroom');

// Phase 10D - Public shop reviews (no auth required)
Route::get('/api/shop-owners/{id}/reviews', [\App\Http\Controllers\Api\RepairReviewController::class, 'getShopReviews']);

Route::get('/services', [LandingPageController::class, 'services'])->name('services');
Route::get('/download', [LandingPageController::class, 'download'])->name('download');
Route::get('/apk/scan-download', function () {
    // Use a relative path so mobile browsers stay on the same host that served the QR page.
    $downloadUrl = route('apk.download', [], false);
    $safeDownloadUrl = htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8');

    $html = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>SoleSpace APK Download</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            font-family: Arial, sans-serif;
            background: #f7f8fb;
            color: #171717;
        }

        .card {
            width: min(420px, 100%);
            background: #ffffff;
            border: 1px solid #e6e8ee;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 16px 40px -22px rgba(0, 0, 0, 0.28);
        }

        h1 {
            margin: 0 0 10px;
            font-size: 1.15rem;
        }

        p {
            margin: 0;
            line-height: 1.5;
            color: #4a4a4a;
        }

        a {
            display: inline-block;
            margin-top: 16px;
            padding: 10px 14px;
            border-radius: 10px;
            background: #171717;
            color: #ffffff;
            text-decoration: none;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <main class="card">
        <h1>Preparing your APK download...</h1>
        <p>If the download does not start automatically, tap the button below.</p>
        <a id="apk-download-link" href="__DOWNLOAD_URL__">Download SoleSpace APK</a>
    </main>

    <script>
        (function () {
            var downloadUrl = __DOWNLOAD_URL_JSON__;
            var started = false;

            function triggerDownload() {
                var frame = document.getElementById('apk-download-frame');

                if (!frame) {
                    frame = document.createElement('iframe');
                    frame.id = 'apk-download-frame';
                    frame.style.display = 'none';
                    document.body.appendChild(frame);
                }

                frame.src = downloadUrl;
            }

            function startDownload() {
                if (started) {
                    return;
                }

                started = true;
                triggerDownload();
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', startDownload, { once: true });
            } else {
                startDownload();
            }
        })();
    </script>
</body>
</html>
HTML;

    $html = str_replace('__DOWNLOAD_URL__', $safeDownloadUrl, $html);
    $html = str_replace(
        '__DOWNLOAD_URL_JSON__',
        json_encode($downloadUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        $html
    );

    return response($html, 200, [
        'Content-Type' => 'text/html; charset=UTF-8',
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
        'Expires' => '0',
    ]);
})->name('apk.scan.download');
Route::get('/apk/download', function () {
    $externalApkUrl = trim((string) env('VITE_APK_URL', ''));

    if ($externalApkUrl !== '' && preg_match('/^https?:\/\//i', $externalApkUrl)) {
        $externalHost = parse_url($externalApkUrl, PHP_URL_HOST);
        $externalPath = parse_url($externalApkUrl, PHP_URL_PATH);
        $requestHost = app(\Illuminate\Http\Request::class)->getHost();

        $normalizeHost = static function (?string $host): ?string {
            if (! is_string($host)) {
                return null;
            }

            return preg_replace('/^www\./i', '', $host);
        };

        $downloadPath = rtrim(route('apk.download', [], false), '/');
        $scanDownloadPath = rtrim(route('apk.scan.download', [], false), '/');
        $normalizedExternalPath = is_string($externalPath) ? rtrim($externalPath, '/') : null;

        $normalizedExternalHost = $normalizeHost($externalHost);
        $normalizedRequestHost = $normalizeHost($requestHost);
        $isSameHost = is_string($normalizedExternalHost)
            && is_string($normalizedRequestHost)
            && strcasecmp($normalizedExternalHost, $normalizedRequestHost) === 0;
        $isLoopTarget = $isSameHost && is_string($normalizedExternalPath)
            && in_array($normalizedExternalPath, [$downloadPath, $scanDownloadPath], true);

        if (! $isLoopTarget) {
            return redirect()->away($externalApkUrl);
        }
    }

    $apkFilePath = public_path('APK/solespace-release.apk');

    if (is_file($apkFilePath)) {
        return response()->download(
            $apkFilePath,
            'solespace-release.apk',
            ['Content-Type' => 'application/vnd.android.package-archive']
        );
    }

    abort(404, 'APK file not found.');
})->name('apk.download');
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

Route::get('/appeals/{token}', [\App\Http\Controllers\SuspensionAppealPublicController::class, 'show'])
    ->middleware('signed')
    ->name('appeals.show');

Route::post('/appeals/{token}', [\App\Http\Controllers\SuspensionAppealPublicController::class, 'submit'])
    ->middleware(['signed', 'throttle:5,1'])
    ->name('appeals.submit');

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
Route::get('/shop-owner/resubmit/{shopOwner}', [ShopOwnerAuthController::class, 'showResubmissionForm'])
    ->middleware('signed')
    ->name('shop-owner.resubmission.form');
Route::post('/shop-owner/resubmit/{shopOwner}', [ShopOwnerAuthController::class, 'resubmit'])
    ->middleware(['signed', 'throttle:10,1'])
    ->name('shop-owner.resubmission.submit');
Route::get('/shop-owner/resubmit/{shopOwner}/documents/{document}', [PrivateSensitiveDocumentController::class, 'showForSignedResubmission'])
    ->scopeBindings()
    ->middleware('signed')
    ->name('shop-owner.resubmission.document');

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

Route::prefix('api/logistics')->middleware(['auth:user,shop_owner'])->group(function () {
    Route::get('/batches', [\App\Http\Controllers\Api\Logistics\DeliveryBatchController::class, 'index']);
    Route::get('/batch-suggestions', [\App\Http\Controllers\Api\Logistics\DeliveryBatchController::class, 'suggestions']);
    Route::post('/batches', [\App\Http\Controllers\Api\Logistics\DeliveryBatchController::class, 'store']);
    Route::post('/legs/schedule', [\App\Http\Controllers\Api\Logistics\DeliveryBatchController::class, 'schedule']);
    Route::put('/batches/{batch}', [\App\Http\Controllers\Api\Logistics\DeliveryBatchController::class, 'update']);
    Route::delete('/batches/{batch}/legs/{leg}', [\App\Http\Controllers\Api\Logistics\DeliveryBatchController::class, 'remove']);
    Route::post('/legs/{leg}/urgent', [\App\Http\Controllers\Api\Logistics\DeliveryBatchController::class, 'urgent']);
    Route::post('/batches/{batch}/offer', [\App\Http\Controllers\Api\Logistics\DeliveryBatchController::class, 'offer']);
    Route::post('/batches/{batch}/accept', [\App\Http\Controllers\Api\Logistics\DeliveryBatchController::class, 'accept']);
    Route::post('/batches/{batch}/reject', [\App\Http\Controllers\Api\Logistics\DeliveryBatchController::class, 'reject']);
    Route::post('/batches/{batch}/start', [\App\Http\Controllers\Api\Logistics\DeliveryBatchController::class, 'start']);
    Route::post('/batches/{batch}/cancel', [\App\Http\Controllers\Api\Logistics\DeliveryBatchController::class, 'cancel']);
    Route::post('/batches/{batch}/restore', [\App\Http\Controllers\Api\Logistics\DeliveryBatchController::class, 'restore']);
    Route::get('/settings', [\App\Http\Controllers\Api\Logistics\LogisticsSettingController::class, 'show']);
    Route::put('/settings', [\App\Http\Controllers\Api\Logistics\LogisticsSettingController::class, 'update']);
    Route::get('/shipments', [\App\Http\Controllers\Api\Logistics\ShipmentController::class, 'index'])
        ->name('logistics.api.shipments.index');
    Route::get('/shipments/{shipment}', [\App\Http\Controllers\Api\Logistics\ShipmentController::class, 'show'])
        ->name('logistics.api.shipments.show');
    Route::post('/delivery-disputes/{dispute}/investigate', [\App\Http\Controllers\Api\Logistics\ShipmentController::class, 'investigateDispute']);
    Route::post('/delivery-disputes/{dispute}/resolve', [\App\Http\Controllers\Api\Logistics\ShipmentController::class, 'resolveDispute']);
    Route::get('/delivery-disputes/{dispute}/evidence/{mediaId}', [\App\Http\Controllers\Api\Logistics\DeliveryDisputeEvidenceController::class, 'show'])
        ->name('api.logistics.delivery-disputes.evidence');
    Route::post('/legs/{leg}/assign', [\App\Http\Controllers\Api\Logistics\ShipmentController::class, 'assign']);
    Route::post('/legs/{leg}/accept', [\App\Http\Controllers\Api\Logistics\ShipmentController::class, 'acceptOffer']);
    Route::post('/legs/{leg}/reject', [\App\Http\Controllers\Api\Logistics\ShipmentController::class, 'rejectOffer']);
    Route::post('/legs/{leg}/proof', [\App\Http\Controllers\Api\Logistics\ShipmentController::class, 'proof']);
    Route::post('/legs/{leg}/picked-up', [\App\Http\Controllers\Api\Logistics\ShipmentController::class, 'pickedUp']);
    Route::post('/legs/{leg}/in-transit', [\App\Http\Controllers\Api\Logistics\ShipmentController::class, 'inTransit']);
    Route::post('/legs/{leg}/pickup-proofs/{proof}/confirm', [\App\Http\Controllers\Api\Logistics\ShipmentController::class, 'confirmPickup']);
    Route::post('/legs/{leg}/pickup-proofs/{proof}/reject', [\App\Http\Controllers\Api\Logistics\ShipmentController::class, 'rejectPickup']);
    Route::post('/legs/{leg}/out-for-delivery', [\App\Http\Controllers\Api\Logistics\ShipmentController::class, 'outForDelivery']);
    Route::post('/legs/{leg}/delivered', [\App\Http\Controllers\Api\Logistics\ShipmentController::class, 'delivered']);
    Route::get('/proofs/{proof}/file', [\App\Http\Controllers\Api\Logistics\ShipmentController::class, 'proofFile']);
    Route::get('/attempts/{attempt}/file', [\App\Http\Controllers\Api\Logistics\ShipmentController::class, 'attemptFile'])
        ->name('api.logistics.attempts.file');
    Route::post('/proofs/{proof}/approve', [\App\Http\Controllers\Api\Logistics\ShipmentController::class, 'approveProof']);
    Route::post('/proofs/{proof}/reject', [\App\Http\Controllers\Api\Logistics\ShipmentController::class, 'rejectProof']);
    Route::post('/legs/{leg}/attempts', [\App\Http\Controllers\Api\Logistics\ShipmentController::class, 'attempts']);
    Route::post('/legs/{leg}/arrivals', [\App\Http\Controllers\Api\Logistics\ShipmentController::class, 'arrival']);
    Route::post('/legs/{leg}/report-issue', [\App\Http\Controllers\Api\Logistics\ShipmentController::class, 'reportIssue']);
    Route::post('/legs/{leg}/cancel', [\App\Http\Controllers\Api\Logistics\ShipmentController::class, 'cancel']);
    Route::post('/legs/{leg}/resolve/retry', [\App\Http\Controllers\Api\Logistics\ShipmentController::class, 'retryResolution']);
    Route::post('/legs/{leg}/resolve/return', [\App\Http\Controllers\Api\Logistics\ShipmentController::class, 'returnResolution']);
    Route::post('/legs/{leg}/incidents', [\App\Http\Controllers\Api\Logistics\DeliveryIncidentController::class, 'store']);
    Route::post('/incidents/{incident}/resolve', [\App\Http\Controllers\Api\Logistics\DeliveryIncidentController::class, 'resolve']);
    Route::get('/incidents/{incident}/evidence/{index}', [\App\Http\Controllers\Api\Logistics\DeliveryIncidentController::class, 'evidence'])
        ->whereNumber('index')
        ->name('api.logistics.incidents.evidence');
    Route::post('/legs/{leg}/return-to-shop', [\App\Http\Controllers\Api\Logistics\ShipmentController::class, 'createReturn']);
    Route::post('/legs/{leg}/return-proofs/{proof}/handoff', [\App\Http\Controllers\Api\Logistics\ShipmentController::class, 'confirmReturnHandoff']);
    Route::post('/legs/{leg}/return-proofs/{proof}/receipt', [\App\Http\Controllers\Api\Logistics\ShipmentController::class, 'confirmReturnReceipt']);
    Route::get('/riders', [\App\Http\Controllers\Api\Logistics\RiderProfileController::class, 'index'])
        ->name('logistics.api.riders.index');
    Route::post('/riders', [\App\Http\Controllers\Api\Logistics\RiderProfileController::class, 'store']);
    Route::patch('/riders/{rider}', [\App\Http\Controllers\Api\Logistics\RiderProfileController::class, 'update']);
});

Route::get('/api/logistics/dashboard-stats', [\App\Http\Controllers\Logistics\ErpLogisticsController::class, 'dashboardStats'])
    ->middleware(['auth:user', 'check.suspension'])
    ->name('logistics.api.dashboard-stats');

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
    if (! Auth::guard('user')->check()) {
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
    return redirect()->route('login');
})->name('user.login.form');

// Shop Owner Login Page
Route::get('/shop-owner/login', function () {
    return redirect()->route('login');
})->name('shop-owner.login.form');

// User Authentication Routes
Route::get('/auth/check-email-availability', [UserController::class, 'checkEmailAvailability'])->name('auth.check-email-availability');
Route::get('/auth/check-phone-availability', [UserController::class, 'checkPhoneAvailability'])->name('auth.check-phone-availability');
Route::post('/user/register', [UserController::class, 'register'])->name('user.register');
Route::post('/user/login', [UserController::class, 'login'])
    ->middleware('throttle:10,1')
    ->name('user.login');
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
Route::post('/shop-owner/login', [ShopOwnerAuthController::class, 'login'])
    ->middleware('throttle:10,1')
    ->name('shop-owner.login');
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

    if (! $shopOwner) {
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
        ],
    ]);
})->middleware('auth:shop_owner')->name('shop-owner.pending-approval');

Route::get('/shop-owner/pending-approval/view/{shopOwner}', [ShopOwnerAuthController::class, 'showPendingApprovalFromEmail'])
    ->middleware('signed')
    ->name('shop-owner.pending-approval.public');

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
Route::prefix('superAdmin')->name('superAdmin.')->middleware([
    AttachPrivilegedCorrelationId::class,
    'super_admin.auth',
    'privileged.active',
    'privileged.mfa',
])->group(function () {
    Route::get('/super-admin-user-management', fn () => redirect()->route('admin.users.index', request()->query()))
        ->middleware('privileged.capability:intervene_accounts')
        ->name('super-admin-user-management');
    Route::get('/shop-owner-registration-view', fn () => redirect()->route('admin.registrations.index', request()->query()))
        ->middleware('privileged.capability:review_registrations')
        ->name('shop-owner-registration-view');
    Route::get('/flagged-accounts', fn () => redirect()->route('admin.flagged-accounts.index', request()->query()))
        ->middleware('privileged.capability:moderate_reports')
        ->name('flagged-accounts');
    Route::get('/system-monitoring-dashboard', fn () => redirect()->route('admin.system-monitoring', request()->query()))
        ->middleware('privileged.capability:view_monitoring')
        ->name('system-monitoring-dashboard');
    Route::get('/notification-communication-tools', fn () => redirect()->route('admin.notifications', request()->query()))
        ->name('notification-communication-tools');
    Route::get('/data-report-access', fn () => redirect()->route('admin.audit', request()->query()))
        ->middleware('privileged.capability:view_privileged_audit')
        ->name('data-report-access');
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

    Route::get('/refund-approvals', [OwnerActionCenterController::class, 'legacyRedirect'])
        ->defaults('legacy_approval_family', 'refund')
        ->middleware('auth:shop_owner')
        ->name('refund-approvals');
});

// Shop Owner Protected Routes
Route::middleware(['auth:shop_owner'])->get('/point-of-sale', function () {
    return redirect()->route('shop-owner.shell.home');
})->name('shop-owner.point-of-sale.legacy');

Route::middleware('auth:shop_owner')->prefix('shop-owner')->name('shop-owner.')->group(function () {
    Route::get('/{shopOwner}/documents/{document}', [PrivateSensitiveDocumentController::class, 'showForShopOwner'])
        ->scopeBindings()
        ->name('documents.show');
    Route::post('/compliance-documents/{document}/renewals', [ShopOwnerDocumentRenewalController::class, 'store'])
        ->whereNumber('document')
        ->name('compliance-documents.renewals.store');

    // Dashboard - Available to ALL shop owners
    Route::get('/dashboard', ShopOwnerDashboardController::class)->name('dashboard');

    Route::prefix('logistics')->name('logistics.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Logistics\ShopOwnerLogisticsController::class, 'dashboard'])->name('dashboard');
        Route::get('/shipments', [\App\Http\Controllers\Logistics\ShopOwnerLogisticsController::class, 'shipments'])->name('shipments');
        Route::get('/riders', [\App\Http\Controllers\Logistics\ShopOwnerLogisticsController::class, 'riders'])->name('riders');
    });

    Route::get('/point-of-sale', function () {
        return redirect()->route('shop-owner.shell.home');
    })->name('point-of-sale');

    // PRODUCT MANAGEMENT - Retail or Both only
    Route::middleware('check.business.type:retail,both')->group(function () {
        Route::get('/products', function () {
            return Inertia::render('ShopOwner/Products/product management/ProductManagementWithVariants');
        })->name('products');

        Route::get('/product-uploder', function () {
            return Inertia::render('ShopOwner/Products/product management/ProductManagementWithVariants');
        })->name('product-uploder');
    });

    // Inventory is a company module for every supported business type. The
    // owner overview is read-only and therefore must not inherit the retail
    // product-management business-type gate above.
    Route::get('/inventory-overview', function () {
        return Inertia::render('ShopOwner/Products/product management/InventoryOverview');
    })->middleware('check.registration.type:company')->name('inventory-overview');

    // SERVICE MANAGEMENT - Repair or Both only
    Route::middleware('check.business.type:repair,both')->group(function () {
        Route::get('/high-value-repairs', function () {
            return Inertia::render('ShopOwner/Repairs/highValueRepairs');
        })->name('high-value-repairs');

        Route::get('/job-orders-repair', function () {
            $shopOwner = Auth::guard('shop_owner')->user();

            if ($shopOwner instanceof \App\Models\ShopOwner
                && strtolower(trim((string) $shopOwner->registration_type)) === 'company') {
                return Inertia::render('ShopOwner/Operations/RepairJobs');
            }

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
            Route::get('/repair-reject-approval', [OwnerActionCenterController::class, 'legacyRedirect'])
                ->defaults('legacy_approval_family', 'repair_rejection')
                ->name('repair-reject-approval');

            Route::get('/history-rejection', function () {
                return Inertia::render('ShopOwner/Repairs/historyRejection');
            })->name('history-rejection');
        });
    });

    // ORDERS - Available to ALL
    Route::get('/job-orders-retail', function () {
        $shopOwner = Auth::guard('shop_owner')->user();

        if ($shopOwner instanceof \App\Models\ShopOwner
            && strtolower(trim((string) $shopOwner->registration_type)) === 'company') {
            return Inertia::render('ShopOwner/Operations/JobOrders');
        }

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
    Route::post('/settings/business-upgrade', [ShopOwnerUpgradeRequestController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('upgrade-requests.store');
    Route::patch('/settings/modules/{moduleKey}', [ShopOwnerModuleController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('modules.update');
    Route::get('/settings/policies', [ShopSettingsController::class, 'getPolicyEditorState'])->name('settings.policies');
    Route::put('/settings/policies/draft', [ShopSettingsController::class, 'savePolicyDraft'])->name('settings.policies.draft');
    Route::post('/settings/policies/publish', [ShopSettingsController::class, 'publishPolicy'])->name('settings.policies.publish');
    Route::post('/settings/paymongo-key', [ShopSettingsController::class, 'updatePaymongoKey'])->name('settings.paymongo-key');
    Route::delete('/settings/paymongo-key', [ShopSettingsController::class, 'removePaymongoKey'])->name('settings.paymongo-key.remove');
    Route::post('/settings/geofence', [ShopSettingsController::class, 'updateGeofence'])->name('settings.geofence');

    // PREMIUM BENEFITS - Retail-capable shops only
    Route::get('/premium-benefits', function () {
        $shopOwner = \Illuminate\Support\Facades\Auth::guard('shop_owner')->user();

        $businessType = strtolower(trim((string) ($shopOwner?->business_type ?? '')));
        $hasRepairSignal = str_contains($businessType, 'repair') || str_contains($businessType, 'service');
        $hasRetailSignal = str_contains($businessType, 'retail') || str_contains($businessType, 'shoe') || str_contains($businessType, 'product');

        if ($shopOwner && $hasRepairSignal && ! $hasRetailSignal) {
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

        if ($shopOwner && ! empty($subscriptionId)) {
            $subscription = \App\Models\ShopOwnerSubscription::with('premiumPlan')
                ->where('id', (int) $subscriptionId)
                ->where('shop_owner_id', (int) $shopOwner->id)
                ->first();

            if ($subscription && in_array($subscription->status, ['pending', 'failed'], true) && ! empty($subscription->paymongo_session_id)) {
                try {
                    $apiKey = config('services.paymongo.secret_key');

                    if (! empty($apiKey)) {
                        $response = \Illuminate\Support\Facades\Http::withHeaders([
                            'Content-Type' => 'application/json',
                            'Authorization' => 'Basic '.base64_encode($apiKey.':'),
                        ])->get('https://api.paymongo.com/v1/checkout_sessions/'.$subscription->paymongo_session_id);

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

                                    if (! $lockedSubscription || ! in_array($lockedSubscription->status, ['pending', 'failed'], true)) {
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

    // REFUND APPROVALS - compatibility redirect to the Action Center
    Route::get('/refund-approvals', [OwnerActionCenterController::class, 'legacyRedirect'])
        ->defaults('legacy_approval_family', 'refund')
        ->name('refund-approvals');

    // PRICE APPROVALS - Business only (for approving staff price changes)
    Route::middleware('check.registration.type:company')->group(function () {
        Route::get('/price-approvals', [OwnerActionCenterController::class, 'legacyRedirect'])
            ->defaults('legacy_approval_family', 'price')
            ->name('price-approvals');

        Route::get('/payslip-approvals', [OwnerActionCenterController::class, 'legacyRedirect'])
            ->defaults('legacy_approval_family', 'payslip')
            ->name('payslip-approvals');

        Route::get('/purchase-request-approval', [OwnerActionCenterController::class, 'legacyRedirect'])
            ->defaults('legacy_approval_family', 'purchase')
            ->name('purchase-request-approval');

        Route::get('/expense-approvals', [OwnerActionCenterController::class, 'legacyRedirect'])
            ->defaults('legacy_approval_family', 'expense')
            ->name('expense-approvals');

        Route::get('/salary-adjustment-approvals', [OwnerActionCenterController::class, 'legacyRedirect'])
            ->defaults('legacy_approval_family', 'salary')
            ->name('salary-adjustment-approvals');
    });

    // STAFF/EMPLOYEE MANAGEMENT - Business only
    Route::middleware('check.registration.type:company')->group(function () {
        Route::get('/suspend-accounts', function () {
            return Inertia::render('ShopOwner/TeamManagement/suspendAccount');
        })->name('suspend-accounts');

        Route::post('/employees', [UserAccessControlController::class, 'storeEmployee'])->name('employees.store');
        Route::put('/employees/{employee}', [UserAccessControlController::class, 'updateEmployee'])->name('employees.update');
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
    Route::get('action-center/summary', OwnerActionCenterSummaryController::class)
        ->name('shop-owner.action-center.summary');
    Route::get('dashboard/stats', [\App\Http\Controllers\ShopOwner\DashboardController::class, 'getStats'])
        ->middleware(['erp.audience', 'erp.actor'])
        ->name('shop-owner.erp.api.retail.dashboard-stats');
    Route::get('dashboard/low-stock', [\App\Http\Controllers\ShopOwner\DashboardController::class, 'getLowStockAlerts']);
    Route::get('dashboard/dss-insights', [\App\Http\Controllers\ShopOwner\DssController::class, 'getInsights'])->name('api.shop_owner.dashboard.dss-insights');
    Route::get('orders', [\App\Http\Controllers\ShopOwner\OrderController::class, 'index'])->middleware('check.business.type:retail,both');
    Route::get('orders/{id}', [\App\Http\Controllers\ShopOwner\OrderController::class, 'show'])->middleware('check.business.type:retail,both');
    Route::patch('orders/{id}/status', [\App\Http\Controllers\ShopOwner\OrderController::class, 'updateStatus'])->middleware('check.business.type:retail,both');
    Route::post('orders/{id}/correct-terminal-outcome', [\App\Http\Controllers\ShopOwner\OrderController::class, 'correctTerminalOutcome'])->middleware('check.business.type:retail,both');

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
    Route::get('inventory-overview', [\App\Http\Controllers\Api\StaffInventoryController::class, 'index'])
        ->middleware(['permission:access-staff-dashboard|access-product-management|access-product-upload-staff', 'check.user.business.type:retail,both']);
    Route::get('orders', [\App\Http\Controllers\Api\StaffOrderController::class, 'index'])
        ->middleware('permission:access-staff-job-orders');
    Route::get('orders/{id}', [\App\Http\Controllers\Api\StaffOrderController::class, 'show'])
        ->middleware('permission:access-staff-job-orders');
    Route::patch('orders/{id}/status', [\App\Http\Controllers\Api\StaffOrderController::class, 'updateStatus'])
        ->middleware('permission:access-staff-job-orders');
    Route::post('orders/{id}/confirm-return-received', [\App\Http\Controllers\Api\StaffOrderController::class, 'confirmReturnReceived'])
        ->middleware('permission:access-staff-job-orders');
    Route::post('orders/{id}/refund/approve', [\App\Http\Controllers\Api\StaffOrderController::class, 'approveRefund'])
        ->middleware('permission:access-staff-job-orders');
    Route::post('orders/{id}/refund/reject', [\App\Http\Controllers\Api\StaffOrderController::class, 'rejectRefund'])
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
            ->middleware(['permission:access-product-upload-staff|access-product-management', 'owner.product.write']);
        Route::put('{id}', [\App\Http\Controllers\Api\ProductController::class, 'update'])
            ->middleware(['permission:access-product-upload-staff|access-product-management', 'owner.product.write']);
        Route::delete('{id}', [\App\Http\Controllers\Api\ProductController::class, 'destroy'])
            ->middleware('throttle:120,1')
            ->middleware(['permission:access-product-upload-staff|access-product-management', 'owner.product.write']);
        Route::post('upload-image', [\App\Http\Controllers\Api\ProductController::class, 'uploadImage'])
            ->middleware('throttle:180,1')
            ->middleware(['permission:access-product-upload-staff|access-product-management', 'owner.product.write']);
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
            ->middleware(['permission:access-product-upload-staff|access-product-management', 'owner.product.write']);
        Route::put('{productId}/color-variants/{colorVariantId}', [\App\Http\Controllers\Api\ProductController::class, 'updateColorVariant'])
            ->middleware(['permission:access-product-upload-staff|access-product-management', 'owner.product.write']);
        Route::delete('{productId}/color-variants/{colorVariantId}', [\App\Http\Controllers\Api\ProductController::class, 'deleteColorVariant'])
            ->middleware('throttle:180,1')
            ->middleware(['permission:access-product-upload-staff|access-product-management', 'owner.product.write']);

        // Color Variant Image Management
        Route::post('{productId}/color-variants/{colorVariantId}/images', [\App\Http\Controllers\Api\ProductController::class, 'uploadColorVariantImage'])
            ->middleware('throttle:240,1')
            ->middleware(['permission:access-product-upload-staff|access-product-management', 'owner.product.write']);
        Route::put('{productId}/color-variants/{colorVariantId}/images/{imageId}', [\App\Http\Controllers\Api\ProductController::class, 'updateColorVariantImage'])
            ->middleware(['permission:access-product-upload-staff|access-product-management', 'owner.product.write']);
        Route::delete('{productId}/color-variants/{colorVariantId}/images/{imageId}', [\App\Http\Controllers\Api\ProductController::class, 'deleteColorVariantImage'])
            ->middleware('throttle:240,1')
            ->middleware(['throttle:240,1', 'owner.product.write']);
        Route::post('{productId}/color-variants/{colorVariantId}/images/reorder', [\App\Http\Controllers\Api\ProductController::class, 'reorderColorVariantImages'])
            ->middleware('throttle:240,1')
            ->middleware(['throttle:240,1', 'owner.product.write']);
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

        // Restore archived repair service
        Route::post('{id}/restore', [\App\Http\Controllers\Api\RepairServiceController::class, 'restore'])
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
        Route::post('{id}/restore', [\App\Http\Controllers\Api\RepairPackageController::class, 'restore']);
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

Route::post('/api/customer/repairs/{id}/verify-payment-return', [\App\Http\Controllers\Api\RepairRequestController::class, 'verifyPayment'])
    ->middleware('throttle:20,1');

// Customer Repair Management API Routes (Phase 2)
Route::middleware('auth:user')->prefix('api/customer/repairs')->group(function () {
    // Get customer's repair requests
    Route::get('/', [\App\Http\Controllers\Api\RepairRequestController::class, 'myRepairs']);

    // Get single repair request
    Route::get('{id}', [\App\Http\Controllers\Api\RepairRequestController::class, 'show']);

    // Replace services after repairer acceptance and before payment
    Route::patch('{id}/services', [\App\Http\Controllers\Api\RepairRequestController::class, 'updateServices']);

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
    if (! app()->environment('production')) {
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
    Route::post('{id}/warranty-claims', [\App\Http\Controllers\Api\RepairWarrantyClaimController::class, 'store'])
        ->name('api.customer.repairs.warranty-claims');
    Route::get('{id}/warranty-claims/latest', [\App\Http\Controllers\Api\RepairWarrantyClaimController::class, 'latest'])
        ->name('api.customer.repairs.warranty-claims.latest');

    // Phase 10D - Reviews & Ratings
    Route::post('{id}/review', [\App\Http\Controllers\Api\RepairReviewController::class, 'store']);
    Route::get('{id}/review', [\App\Http\Controllers\Api\RepairReviewController::class, 'getRepairReview']);
    Route::get('{id}/can-review', [\App\Http\Controllers\Api\RepairReviewController::class, 'canReview']);

    // Set preferred drop-off date after repairer accepts and customer chats
    Route::patch('{id}/schedule', [\App\Http\Controllers\Api\RepairRequestController::class, 'setSchedule']);

    // Change pickup/delivery method before final receipt confirmation
    Route::patch('{id}/delivery-method', [\App\Http\Controllers\Api\RepairRequestController::class, 'changeDeliveryMethod']);
    Route::post('{id}/external-tracking', [\App\Http\Controllers\Api\RepairRequestController::class, 'updateExternalTracking']);
    Route::post('{id}/confirm-return-address', [\App\Http\Controllers\Api\RepairRequestController::class, 'confirmReturnAddress']);
    Route::post('{id}/return-recovery', [\App\Http\Controllers\Api\RepairRequestController::class, 'resolveReturnRecovery']);
    Route::post('{id}/pickup-recovery', [\App\Http\Controllers\Api\RepairRequestController::class, 'resolvePickupRecovery']);
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
Route::middleware(['auth:user', 'permission:access-refund-approval'])->prefix('api/finance/repair-delivery-reconciliations')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\RepairRefundWorkflowController::class, 'financeDeliveryReconciliations']);
    Route::post('{repair}/resolve', [\App\Http\Controllers\Api\RepairRefundWorkflowController::class, 'resolveFinanceDeliveryReconciliation']);
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
    Route::get('/dashboard', [\App\Http\Controllers\Repairer\DashboardController::class, 'getDashboardData'])
        ->name('erp.staff.api.repair-dashboard');

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
Route::middleware('auth:user')->get('/api/repair/shops/{shop}/delivery-quote', [\App\Http\Controllers\Api\RepairAvailabilityController::class, 'deliveryQuote']);

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
    Route::post('{id}/cancel-delivery-leg', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'cancelDeliveryLeg']);

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
// Reads and mutations use separate Manager capabilities.
Route::middleware([
    'auth:user',
    'check.user.business.type:repair,both',
])->prefix('api/manager/repairs')->group(function () {
    // Canonical Manager Repair Jobs API
    Route::get('/', [\App\Http\Controllers\Api\ManagerRepairController::class, 'index'])
        ->middleware('manager.capability:repair-jobs-read');
    Route::get('/{id}/eligible-repairers', [\App\Http\Controllers\Api\ManagerRepairController::class, 'eligibleRepairers'])
        ->whereNumber('id')
        ->middleware('manager.capability:repair-jobs-read');
    Route::get('/{id}', [\App\Http\Controllers\Api\ManagerRepairController::class, 'show'])
        ->whereNumber('id')
        ->middleware('manager.capability:repair-jobs-read');
    Route::post('/{id}/reassign', [\App\Http\Controllers\Api\ManagerRepairController::class, 'reassign'])
        ->whereNumber('id')
        ->middleware('manager.capability:repair-review');
    Route::post('/{id}/final-reject', [\App\Http\Controllers\Api\ManagerRepairController::class, 'finalReject'])
        ->whereNumber('id')
        ->middleware('manager.capability:repair-review');
    Route::post('/{id}/forward-to-owner', [\App\Http\Controllers\Api\ManagerRepairController::class, 'forwardToOwner'])
        ->whereNumber('id')
        ->middleware('manager.capability:repair-review');

    // Get repairs pending manager review
    Route::get('/rejected', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'getPendingManagerReviews'])
        ->middleware('manager.capability:repair-jobs-read');

    // Get rejection history timeline
    Route::get('/rejection-history', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'getRejectionHistory'])
        ->middleware('manager.capability:repair-jobs-read');

    // Approve repairer's rejection
    Route::post('{id}/approve-rejection', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'approveRejection'])
        ->middleware('manager.capability:repair-review');

    // Final manager approval to close rejection workflow
    Route::post('{id}/finalize-rejection', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'finalizeRejection'])
        ->middleware('manager.capability:repair-review');

    // Override rejection and reassign
    Route::post('{id}/override-rejection', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'overrideRejection'])
        ->middleware('manager.capability:repair-review');

    // Get available repairers for a repair (with skill matching)
    Route::get('{id}/available-repairers', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'getAvailableRepairersForRepair'])
        ->middleware('manager.capability:repair-jobs-read');
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
    Route::get('/rejection-pending/{id}', [\App\Http\Controllers\Api\RepairWorkflowController::class, 'getOwnerRejectionApproval']);
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
    $path = storage_path('app/public/reviews/'.$filename);

    if (! file_exists($path)) {
        abort(404);
    }

    return response()->file($path);
})->where('filename', '.*');

// Retired Finance session aliases. They are deliberately not proxied: a
// compatibility write must never create a second money-history path.
Route::middleware(['auth:user', 'shop.isolation'])->any('api/finance/session/{path?}', function (Request $request): \Illuminate\Http\JsonResponse {
    Log::warning('Finance compatibility route used', [
        'route' => 'session-alias',
        'path' => $request->path(),
    ]);

    return response()->json([
        'message' => 'The Finance session route family has moved to /api/finance.',
        'code' => 'FINANCE_ROUTE_MOVED',
        'replacement' => '/api/finance',
    ], 410);
})->where('path', '.*');

// Search routes
Route::middleware(['auth:user', 'check.suspension'])->group(function () {
    Route::get('/api/search', [\App\Http\Controllers\Api\SearchController::class, 'search']);
});

// Super Admin Notification API routes
Route::middleware([
    AttachPrivilegedCorrelationId::class,
    'super_admin.auth',
    'privileged.active',
    'privileged.mfa',
])->prefix('api/admin/notifications')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\AdminNotificationController::class, 'index']);
    Route::get('/unread-count', [\App\Http\Controllers\Api\AdminNotificationController::class, 'unreadCount']);
    Route::post('/{id}/read', [\App\Http\Controllers\Api\AdminNotificationController::class, 'markAsRead']);
    Route::post('/read-all', [\App\Http\Controllers\Api\AdminNotificationController::class, 'markAllAsRead']);
    Route::delete('/{id}', [\App\Http\Controllers\Api\AdminNotificationController::class, 'destroy']);
});

// Shop Registration Routes
Route::get('/shop/register', function () {
    return redirect()->route('shop-owner-register', request()->query());
})->name('shop.register.form');

// Shop Message Route
Route::get('/shop/message', function () {
    return Inertia::render('UserSide/Communication/message', [
        'shopOwner' => [
            'id' => 1,
            'name' => 'Test Business',
            'avatar' => 'https://via.placeholder.com/48',
            'online' => true,
        ],
    ]);
})->name('shop.message');

// Super Admin Authentication Routes (Second set - removed duplicate, fixing authentication flows)
Route::get('/admin/login', [SuperAdminAuthController::class, 'showLoginForm'])
    ->middleware(AttachPrivilegedCorrelationId::class)
    ->name('admin.login');
Route::post('/admin/login', [SuperAdminAuthController::class, 'login'])
    ->middleware([AttachPrivilegedCorrelationId::class, 'throttle:privileged-login'])
    ->name('admin.login.post');
Route::post('/admin/logout', [SuperAdminAuthController::class, 'logout'])
    ->middleware(AttachPrivilegedCorrelationId::class)
    ->name('admin.logout');
Route::get('/admin/setup', [PrivilegedSetupController::class, 'showSetupPage'])
    ->middleware([AttachPrivilegedCorrelationId::class, 'privileged.no-store'])
    ->name('admin.setup');
Route::post('/admin/setup/exchange', [PrivilegedSetupController::class, 'exchange'])
    ->middleware([AttachPrivilegedCorrelationId::class, 'privileged.no-store', 'throttle:privileged-setup'])
    ->name('admin.setup.exchange');
Route::post('/admin/setup/complete', [PrivilegedSetupController::class, 'completePassword'])
    ->middleware([AttachPrivilegedCorrelationId::class, 'privileged.no-store', 'throttle:privileged-setup'])
    ->name('admin.setup.complete');
Route::get('/admin/forgot-password', [PrivilegedPasswordResetController::class, 'showForgotPassword'])
    ->middleware([AttachPrivilegedCorrelationId::class, 'privileged.no-store'])
    ->name('admin.password.request');
Route::post('/admin/forgot-password', [PrivilegedPasswordResetController::class, 'send'])
    ->middleware([AttachPrivilegedCorrelationId::class, 'throttle:privileged-password-reset'])
    ->name('admin.password.email');
Route::get('/admin/reset-password', [PrivilegedPasswordResetController::class, 'showResetPassword'])
    ->middleware([AttachPrivilegedCorrelationId::class, 'privileged.no-store'])
    ->name('admin.password.reset');
Route::post('/admin/reset-password/exchange', [PrivilegedPasswordResetController::class, 'exchange'])
    ->middleware([AttachPrivilegedCorrelationId::class, 'privileged.no-store', 'throttle:privileged-password-reset'])
    ->name('admin.password.reset.exchange');
Route::post('/admin/reset-password/complete', [PrivilegedPasswordResetController::class, 'complete'])
    ->middleware([AttachPrivilegedCorrelationId::class, 'privileged.no-store', 'throttle:privileged-password-reset'])
    ->name('admin.password.reset.complete');
Route::get('/admin/mfa/challenge', [PrivilegedMfaController::class, 'show'])
    ->middleware([AttachPrivilegedCorrelationId::class, 'super_admin.auth', 'privileged.active', 'privileged.no-store'])
    ->name('admin.mfa.challenge');
Route::post('/admin/mfa/challenge', [PrivilegedMfaController::class, 'verify'])
    ->middleware([AttachPrivilegedCorrelationId::class, 'super_admin.auth', 'privileged.active', 'privileged.no-store', 'throttle:privileged-mfa'])
    ->name('admin.mfa.challenge.verify');
Route::get('/admin/mfa/setup', [PrivilegedSetupController::class, 'showEnrollment'])
    ->middleware([AttachPrivilegedCorrelationId::class, 'super_admin.auth', 'privileged.no-store'])
    ->name('admin.mfa.setup');
Route::post('/admin/mfa/setup/verify', [PrivilegedSetupController::class, 'verifyEnrollment'])
    ->middleware([AttachPrivilegedCorrelationId::class, 'super_admin.auth', 'privileged.no-store', 'throttle:privileged-setup'])
    ->name('admin.mfa.setup.verify');
Route::post('/admin/mfa/setup/recovery/acknowledge', [PrivilegedSetupController::class, 'acknowledgeRecovery'])
    ->middleware([AttachPrivilegedCorrelationId::class, 'super_admin.auth', 'privileged.no-store', 'throttle:privileged-setup'])
    ->name('admin.mfa.setup.recovery.acknowledge');

// Admin Protected Routes
Route::middleware([
    AttachPrivilegedCorrelationId::class,
    'super_admin.auth',
    'privileged.active',
    'privileged.mfa',
])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', function () {
        return redirect()->route('admin.system-monitoring');
    })->name('dashboard');
    Route::get('/reauthenticate', [PrivilegedReauthenticationController::class, 'show'])
        ->middleware('privileged.no-store')
        ->name('reauthenticate');
    Route::post('/reauthenticate', [PrivilegedReauthenticationController::class, 'authenticate'])
        ->middleware(['privileged.no-store', 'throttle:privileged-reauth'])
        ->name('reauthenticate.submit');
    Route::get('/security', [PrivilegedSecurityController::class, 'show'])
        ->middleware(['privileged.capability:manage_own_security', 'privileged.no-store'])
        ->name('security');
    Route::get('/profile', [SuperAdminAuthController::class, 'showProfile'])
        ->middleware(['privileged.capability:manage_own_security', 'privileged.no-store'])
        ->name('profile');
    Route::post('/security/password', [PrivilegedSecurityController::class, 'changePassword'])
        ->middleware([
            'privileged.capability:manage_own_security',
            'privileged.recent',
            'privileged.no-store',
        ])
        ->name('security.password');
    Route::post('/security/recovery/generate', [PrivilegedSecurityController::class, 'generateRecoveryCodes'])
        ->middleware([
            'privileged.capability:manage_own_security',
            'privileged.recent',
            'privileged.no-store',
            'throttle:privileged-setup',
        ])
        ->name('security.recovery.generate');
    Route::post('/security/recovery/acknowledge', [PrivilegedSecurityController::class, 'acknowledgeRecovery'])
        ->middleware([
            'privileged.capability:manage_own_security',
            'privileged.recent',
            'privileged.no-store',
            'throttle:privileged-setup',
        ])
        ->name('security.recovery.acknowledge');
    Route::post('/security/mfa/reset', [PrivilegedSecurityController::class, 'resetMfa'])
        ->middleware([
            'privileged.capability:manage_own_security',
            'privileged.recent',
            'privileged.no-store',
            'throttle:privileged-setup',
        ])
        ->name('security.mfa.reset');
    Route::get('/system-monitoring', [SystemMonitoringDashboardController::class, 'index'])
        ->middleware('privileged.capability:view_monitoring')
        ->name('system-monitoring');
    Route::get('/audit', [PrivilegedAuditController::class, 'index'])
        ->middleware('privileged.capability:view_privileged_audit')
        ->name('audit');

    // Administrator management owns all privileged identity mutations.
    Route::get('/administrators', [AdministratorManagementController::class, 'index'])
        ->middleware('privileged.capability:manage_administrators')
        ->name('administrators.index');
    Route::get('/administrators/create', [AdministratorManagementController::class, 'create'])
        ->middleware('privileged.capability:manage_administrators')
        ->name('administrators.create');
    Route::post('/administrators', [AdministratorManagementController::class, 'store'])
        ->middleware(['privileged.capability:manage_administrators', 'privileged.recent'])
        ->name('administrators.store');
    Route::post('/administrators/{administrator}/setup/resend', [AdministratorManagementController::class, 'resendSetupInvitation'])
        ->middleware(['privileged.capability:manage_administrators', 'privileged.recent'])
        ->name('administrators.setup.resend');
    Route::post('/administrators/{administrator}/suspend', [AdministratorManagementController::class, 'suspend'])
        ->middleware(['privileged.capability:manage_administrators', 'privileged.recent'])
        ->name('administrators.suspend');
    Route::post('/administrators/{administrator}/deactivate', [AdministratorManagementController::class, 'deactivate'])
        ->middleware(['privileged.capability:manage_administrators', 'privileged.recent'])
        ->name('administrators.deactivate');
    Route::post('/administrators/{administrator}/activate', [AdministratorManagementController::class, 'activate'])
        ->middleware(['privileged.capability:manage_administrators', 'privileged.recent'])
        ->name('administrators.activate');
    Route::patch('/administrators/{administrator}/role', [AdministratorManagementController::class, 'updateRole'])
        ->middleware(['privileged.capability:manage_administrators', 'privileged.recent'])
        ->name('administrators.role.update');
    Route::post('/administrators/{administrator}/mfa/reset', [AdministratorManagementController::class, 'resetMfa'])
        ->middleware(['privileged.capability:manage_platform_security', 'privileged.recent'])
        ->name('administrators.mfa.reset');

    // Temporary safe GET aliases for existing bookmarks and persisted links.
    Route::get('/admin', fn () => redirect()->route('admin.administrators.index', request()->query()))
        ->middleware('privileged.capability:manage_administrators')
        ->name('admin-management');
    Route::get('/create-admin', fn () => redirect()->route('admin.administrators.create', request()->query()))
        ->middleware('privileged.capability:manage_administrators')
        ->name('create-admin');

    Route::get('/registrations', [ShopOwnerRegistrationViewController::class, 'index'])
        ->middleware('privileged.capability:review_registrations')
        ->name('registrations.index');
    Route::get('/shop-owner-registration-view', fn () => redirect()->route('admin.registrations.index', request()->query()))
        ->middleware('privileged.capability:review_registrations')
        ->name('shop-owner-registration-view');
    Route::get('/document-renewals', [SuperAdminShopDocumentRenewalController::class, 'index'])
        ->middleware('privileged.capability:review_registrations')
        ->name('document-renewals.index');
    Route::post('/document-renewals/{document}/approve', [SuperAdminShopDocumentRenewalController::class, 'approve'])
        ->whereNumber('document')
        ->middleware('privileged.capability:review_registrations')
        ->name('document-renewals.approve');
    Route::post('/document-renewals/{document}/reject', [SuperAdminShopDocumentRenewalController::class, 'reject'])
        ->whereNumber('document')
        ->middleware('privileged.capability:review_registrations')
        ->name('document-renewals.reject');
    Route::get('/shop-owners/{shopOwner}/documents/{document}', [PrivateSensitiveDocumentController::class, 'showForPrivileged'])
        ->scopeBindings()
        ->middleware('privileged.capability:review_registrations')
        ->name('shop-documents.show');
    Route::get('/users/{user}/valid-id', [PrivateSensitiveDocumentController::class, 'showCustomerValidId'])
        ->middleware('privileged.capability:intervene_accounts')
        ->name('users.valid-id.show');
    Route::post('/registrations/{shopOwner}/approve', [ShopOwnerRegistrationViewController::class, 'approve'])
        ->whereNumber('shopOwner')
        ->middleware('privileged.capability:review_registrations')
        ->name('registrations.approve');
    Route::post('/registrations/{shopOwner}/reject', [ShopOwnerRegistrationViewController::class, 'reject'])
        ->whereNumber('shopOwner')
        ->middleware('privileged.capability:review_registrations')
        ->name('registrations.reject');
    Route::get('/flagged-accounts', [FlaggedAccountsController::class, 'index'])
        ->middleware('privileged.capability:moderate_reports')
        ->name('flagged-accounts.index');
    Route::post('/flagged-accounts/{id}/mark-reviewed', [FlaggedAccountsController::class, 'markReviewed'])
        ->whereNumber('id')
        ->middleware('privileged.capability:moderate_reports')
        ->name('flagged-accounts.mark-reviewed');
    Route::post('/flagged-accounts/{id}/dismiss', [FlaggedAccountsController::class, 'dismiss'])
        ->whereNumber('id')
        ->middleware('privileged.capability:moderate_reports')
        ->name('flagged-accounts.dismiss');
    Route::post('/flagged-accounts/{id}/ban', [FlaggedAccountsController::class, 'ban'])
        ->whereNumber('id')
        ->middleware('privileged.capability:moderate_reports')
        ->name('flagged-accounts.ban');
    // Registered-shop management owns shop reads and lifecycle mutations.
    Route::get('/shops', [RegisteredShopController::class, 'index'])
        ->middleware('privileged.capability:intervene_accounts')
        ->name('shops.index');
    Route::get('/shops/{shopOwner}', [RegisteredShopController::class, 'show'])
        ->whereNumber('shopOwner')
        ->middleware('privileged.capability:intervene_accounts')
        ->name('shops.show');
    Route::get('/registered-shops', fn () => redirect()->route('admin.shops.index', request()->query()))
        ->middleware('privileged.capability:intervene_accounts')
        ->name('registered-shops');
    Route::get('/business-upgrade-requests', [SuperAdminShopOwnerUpgradeRequestController::class, 'index'])
        ->middleware('privileged.capability:review_registrations')
        ->name('business-upgrade-requests.index');
    Route::patch('/business-upgrade-requests/{upgradeRequest}', [SuperAdminShopOwnerUpgradeRequestController::class, 'update'])
        ->middleware('privileged.capability:review_registrations')
        ->name('business-upgrade-requests.update');
    Route::get('/business-upgrade-requests/{upgradeRequest}/documents/{document}', [SuperAdminShopOwnerUpgradeRequestController::class, 'download'])
        ->middleware('privileged.capability:review_registrations')
        ->name('business-upgrade-requests.documents.download');
    Route::get('/business-upgrade-requests/{upgradeRequest}/documents/{document}/view', [SuperAdminShopOwnerUpgradeRequestController::class, 'view'])
        ->middleware('privileged.capability:review_registrations')
        ->name('business-upgrade-requests.documents.view');
    Route::get('/shops/{id}/details', fn (int $id) => redirect()->route(
        'admin.shops.show',
        ['shopOwner' => $id] + request()->query(),
    ))
        ->middleware('privileged.capability:intervene_accounts')
        ->name('shops.details');
    Route::get('/subscriptions', [SubscriptionManagementController::class, 'index'])
        ->middleware('privileged.capability:manage_plans')
        ->name('subscriptions.index');
    Route::get('/subscriptions/{subscription}/history', [SubscriptionManagementController::class, 'history'])
        ->whereNumber('subscription')
        ->middleware('privileged.capability:manage_plans')
        ->name('subscriptions.history');
    Route::get('/subscription-management', fn () => redirect()->route(
        'admin.subscriptions.index',
        request()->query(),
    ))
        ->middleware('privileged.capability:manage_plans')
        ->name('subscription-management');
    Route::post('/subscriptions/{subscription}/cancel', [SubscriptionInterventionController::class, 'cancel'])
        ->middleware([
            'privileged.capability:intervene_subscriptions',
            'privileged.recent',
        ])
        ->name('subscriptions.cancel');
    Route::patch('/subscriptions/{subscription}/legacy-correction', [SubscriptionInterventionController::class, 'legacyCorrection'])
        ->middleware([
            'privileged.capability:intervene_subscriptions',
            'privileged.recent',
        ])
        ->name('subscriptions.legacy-correction');
    Route::post('/subscription-payments/{payment}/refunds', [SubscriptionInterventionController::class, 'refund'])
        ->middleware([
            'privileged.capability:intervene_subscriptions',
            'privileged.recent',
            'throttle:privileged-subscription-refund',
        ])
        ->name('subscription-payments.refunds.store');
    Route::post('/plans', [PremiumPlanController::class, 'store'])
        ->middleware('privileged.capability:manage_plans')
        ->name('plans.store');
    Route::put('/plans/{premiumPlan}', [PremiumPlanController::class, 'update'])
        ->whereNumber('premiumPlan')
        ->middleware('privileged.capability:manage_plans')
        ->name('plans.update');
    Route::post('/plans/{premiumPlan}/archive', [PremiumPlanController::class, 'archive'])
        ->whereNumber('premiumPlan')
        ->middleware('privileged.capability:manage_plans')
        ->name('plans.archive');
    Route::post('/plans/{premiumPlan}/reactivate', [PremiumPlanController::class, 'reactivate'])
        ->whereNumber('premiumPlan')
        ->middleware('privileged.capability:manage_plans')
        ->name('plans.reactivate');
    Route::post('/shops/{shopOwner}/suspend', [RegisteredShopController::class, 'suspend'])
        ->whereNumber('shopOwner')
        ->middleware('privileged.capability:intervene_accounts')
        ->name('shops.suspend');
    Route::post('/shops/{shopOwner}/reactivate', [RegisteredShopController::class, 'reactivate'])
        ->whereNumber('shopOwner')
        ->middleware('privileged.capability:intervene_accounts')
        ->name('shops.reactivate');
    Route::post('/shops/{shopOwner}/archive', [RegisteredShopController::class, 'archive'])
        ->whereNumber('shopOwner')
        ->middleware(['privileged.capability:intervene_accounts', 'privileged.recent'])
        ->name('shops.archive');
    Route::post('/shops/{shopOwner}/restore', [RegisteredShopController::class, 'restore'])
        ->whereNumber('shopOwner')
        ->middleware(['privileged.capability:intervene_accounts', 'privileged.recent'])
        ->name('shops.restore');
    // User intervention owns the paginated customer-management read model and lifecycle mutations.
    Route::get('/users', [UserInterventionController::class, 'index'])
        ->middleware('privileged.capability:intervene_accounts')
        ->name('users.index');
    Route::get('/user-management', fn () => redirect()->route('admin.users.index', request()->query()))
        ->middleware('privileged.capability:intervene_accounts')
        ->name('user-management');
    Route::post('/users/{user}/suspend', [UserInterventionController::class, 'suspend'])
        ->whereNumber('user')
        ->middleware('privileged.capability:intervene_accounts')
        ->name('users.suspend');
    Route::post('/users/{user}/reactivate', [UserInterventionController::class, 'reactivate'])
        ->whereNumber('user')
        ->middleware('privileged.capability:intervene_accounts')
        ->name('users.reactivate');
    Route::post('/users/{user}/archive', [UserInterventionController::class, 'archive'])
        ->whereNumber('user')
        ->middleware(['privileged.capability:intervene_accounts', 'privileged.recent'])
        ->name('users.archive');
    Route::post('/users/{user}/restore', [UserInterventionController::class, 'restore'])
        ->whereNumber('user')
        ->middleware(['privileged.capability:intervene_accounts', 'privileged.recent'])
        ->name('users.restore');
    // Shop Reports routes
    Route::get('/shop-reports', [\App\Http\Controllers\superAdmin\ShopReportsController::class, 'index'])
        ->middleware('privileged.capability:moderate_reports')
        ->name('shop-reports');
    Route::get('/shop-reports/{shopOwner}', [\App\Http\Controllers\superAdmin\ShopReportsController::class, 'show'])
        ->whereNumber('shopOwner')
        ->middleware('privileged.capability:moderate_reports')
        ->name('shop-reports.show');
    Route::post('/shop-reports/{id}/action', [\App\Http\Controllers\superAdmin\ShopReportsController::class, 'action'])
        ->middleware('privileged.capability:moderate_reports')
        ->name('shop-reports.action');

    // Suspension Appeals routes
    Route::get('/appeals', [\App\Http\Controllers\superAdmin\SuspensionAppealsController::class, 'index'])
        ->middleware('privileged.capability:view_appeals')
        ->name('suspension-appeals');
    Route::post('/appeals/{id}/approve', [\App\Http\Controllers\superAdmin\SuspensionAppealsController::class, 'approve'])
        ->middleware('privileged.capability:resolve_appeals')
        ->name('appeals.approve');
    Route::post('/appeals/{id}/reject', [\App\Http\Controllers\superAdmin\SuspensionAppealsController::class, 'reject'])
        ->middleware('privileged.capability:resolve_appeals')
        ->name('appeals.reject');

    // Additional admin routes
    Route::get('/notifications', function () {
        return Inertia::render('superAdmin/Notifications/AdminNotifications');
    })->name('notifications');
    Route::get('/data-reports', fn () => redirect()->route('admin.audit', request()->query()))
        ->middleware('privileged.capability:view_privileged_audit')
        ->name('data-reports');
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

Route::prefix('erp/logistics')->name('erp.logistics.')->middleware(['auth:user', 'check.suspension'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Logistics\ErpLogisticsController::class, 'dashboard'])->name('dashboard');
    Route::get('/shipments', [\App\Http\Controllers\Logistics\ErpLogisticsController::class, 'shipments'])->name('shipments');
    Route::get('/deliveries', [\App\Http\Controllers\Logistics\ErpLogisticsController::class, 'deliveries'])->name('deliveries');
    Route::get('/riders', [\App\Http\Controllers\Logistics\ErpLogisticsController::class, 'riders'])->name('riders');
    Route::get('/settings', [\App\Http\Controllers\Logistics\ErpLogisticsController::class, 'settings'])->name('settings');
    Route::get('/batches', [\App\Http\Controllers\Logistics\ErpLogisticsController::class, 'batches'])->name('batches');
});

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
Route::get('/erp/hr/audit-logs', [\App\Http\Controllers\Erp\ReadPageController::class, 'hrAuditLogs'])
    ->middleware(['auth:user', 'permission:access-audit-logs'])
    ->name('erp.hr.audit-logs');

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
            'purchaseRequests' => $purchaseRequests,
        ]);
    })->name('index');

    Route::get('/dashboard', function () {
        return Inertia::render('ERP/Finance/Dashboard');

    })->name('dashboard');

    Route::get('/purchase-request-approval', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }

        return redirect('/finance?'.http_build_query(array_filter([
            'section' => 'purchase-request-approval',
            'purchase_request' => request()->query('purchase_request'),
        ], fn ($value) => $value !== null)));
    })->middleware('permission:access-finance-dashboard|access-approval-workflow|access-purchase-request-approval')->name('purchase-request-approval');
});

// Finance Audit Logs
Route::get('/erp/finance/audit-logs', [\App\Http\Controllers\Erp\ReadPageController::class, 'financeAuditLogs'])
    ->middleware(['auth:user', 'shop.isolation', 'permission:access-audit-logs'])
    ->name('erp.finance.audit-logs');

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

// MANAGER routes use page-specific capability gates.
Route::prefix('erp/manager')->name('erp.manager.')->middleware([
    'auth:user',
])->group(function () {
    Route::get('/dashboard', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        return Inertia::render('ERP/Manager/Dashboard');
    })->middleware('manager.capability:dashboard-read')->name('dashboard');
    Route::get('/job-orders', [\App\Http\Controllers\Erp\ReadPageController::class, 'managerJobOrders'])
        ->middleware(['manager.capability:job-orders-read', 'check.user.business.type:retail,both'])->name('job-orders');
    Route::get('/repair-jobs', [\App\Http\Controllers\Erp\ReadPageController::class, 'managerRepairJobs'])
        ->middleware(['manager.capability:repair-jobs-read', 'check.user.business.type:repair,both'])->name('repair-jobs');
    Route::get('/staff-workload', [\App\Http\Controllers\Erp\ReadPageController::class, 'managerStaffWorkload'])
        ->middleware('manager.capability:staff-workload-read')->name('staff-workload');
    Route::get('/leave-approvals', [\App\Http\Controllers\Erp\ReadPageController::class, 'managerLeaveApprovals'])
        ->middleware('manager.capability:leave-approvals-read')->name('leave-approvals');
    Route::get('/reports', [\App\Http\Controllers\Erp\ReadPageController::class, 'managerReports'])
        ->middleware('manager.capability:reports-read')->name('reports');
    Route::get('/suspension-approvals', [\App\Http\Controllers\Erp\ReadPageController::class, 'managerSuspensionApprovals'])
        ->middleware('manager.capability:suspension-approvals-read')->name('suspension-approvals');
    Route::get('/suspend-approval', function () {
        return redirect()->route('erp.manager.suspension-approvals');
    })->middleware('manager.capability:suspension-approvals-read')->name('suspend-approval');
    Route::get('/termination-approvals', [\App\Http\Controllers\Erp\ReadPageController::class, 'managerTerminationApprovals'])
        ->middleware('manager.capability:termination-approvals-read')->name('termination-approvals');
    Route::get('/rehire-approvals', [\App\Http\Controllers\Erp\ReadPageController::class, 'managerRehireApprovals'])
        ->middleware('manager.capability:rehire-approvals-read')->name('rehire-approvals');
    Route::get('/shoe-pricing', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }

        return Inertia::render('ERP/STAFF/shoePricing');
    })->middleware(['permission:access-shoe-pricing', 'check.user.business.type:retail,both'])->name('shoe-pricing');
    Route::get('/products', function () {
        return redirect()->route('erp.manager.inventory-overview');
    })->middleware('manager.capability:inventory-read')->name('products');

    // Inventory routes - accessible by Inventory Manager (Manager needs explicit permission)
    Route::get('/inventory-overview', [\App\Http\Controllers\Erp\ReadPageController::class, 'managerInventoryOverview'])
        ->middleware('manager.capability:inventory-read')->name('inventory-overview');
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
            ->whereHas('inventoryItem', fn ($q) => $q->where('shop_owner_id', $shopOwnerId))
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
    Route::get('/audit-logs', [\App\Http\Controllers\Erp\ReadPageController::class, 'managerAuditLogs'])
        ->middleware('manager.capability:audit-read')->name('audit-logs');
    // Repair rejection review: manager route limited to repair-capable businesses
    Route::get('/repair-rejection-review', function () {
        return redirect()->route('erp.manager.repair-jobs');
    })->middleware(['manager.capability:repair-jobs-read', 'check.user.business.type:repair,both'])->name('repair-rejection-review');
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

    Route::get('/inventory-dashboard', [\App\Http\Controllers\Erp\ReadPageController::class, 'inventoryDashboard'])
        ->name('inventory-dashboard');

    Route::get('/stock-movement', [\App\Http\Controllers\Erp\ReadPageController::class, 'stockMovement'])
        ->name('stock-movement');

    Route::get('/product-inventory', [\App\Http\Controllers\Erp\ReadPageController::class, 'productInventory'])
        ->name('product-inventory');

    Route::get('/stock-request', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        $shopOwnerId = Auth::guard('user')->user()->shop_owner_id;
        $initialRequests = \App\Models\StockRequestApproval::with(['inventoryItem.sizes', 'inventoryItem.colorVariants.sizes', 'requester'])
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
        $initialData = \App\Models\PurchaseOrder::with(['supplier', 'items.inventoryItem.sizes', 'receipts.items'])
            ->where('shop_owner_id', $shopOwnerId)->orderBy('ordered_date', 'desc')->paginate(200);

        return Inertia::render('ERP/inventory/SupplierOrderMonitoring', compact('initialData'));
    })->name('supplier-order-monitoring');
});

// PROCUREMENT MODULE routes (accessible by Procurement Manager role or users with explicit permissions)
Route::prefix('erp/procurement')->name('erp.procurement.')->middleware('auth:user')->group(function () {

    Route::get('/dashboard', [\App\Http\Controllers\Erp\ReadPageController::class, 'procurementDashboard'])
        ->middleware('permission:view-procurement|access-procurement-dashboard')
        ->name('dashboard');

    Route::get('/purchase-request', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        $shopOwnerId = Auth::guard('user')->user()->shop_owner_id;
        $initialData = \App\Models\PurchaseRequest::with(['shopOwner', 'supplier', 'inventoryItem', 'requester', 'reviewer', 'approver'])
            ->where('shop_owner_id', $shopOwnerId)->orderBy('requested_date', 'desc')->paginate(100);
        $initialSuppliers = \App\Models\Supplier::where('shop_owner_id', $shopOwnerId)->orderBy('name')->get();
        $initialAcceptedRequests = \App\Models\StockRequestApproval::with(['inventoryItem', 'requester'])
            ->where('shop_owner_id', $shopOwnerId)->where('status', 'accepted')
            ->whereDoesntHave('purchaseRequest')->orderBy('requested_date', 'desc')->paginate(200);

        return Inertia::render('ERP/Procurement/PurchaseRequest', compact('initialData', 'initialSuppliers', 'initialAcceptedRequests'));
    })->middleware('permission:view-procurement|access-purchase-requests')->name('purchase-request');

    Route::get('/purchase-orders', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        $shopOwnerId = Auth::guard('user')->user()->shop_owner_id;
        $initialData = \App\Models\PurchaseOrder::with(['purchaseRequest', 'shopOwner', 'supplier', 'inventoryItem', 'orderer'])
            ->where('shop_owner_id', $shopOwnerId)->orderBy('ordered_date', 'desc')->paginate(100);
        $initialApprovedPRs = \App\Models\PurchaseRequest::with(['supplier', 'inventoryItem', 'requester'])
            ->where('shop_owner_id', $shopOwnerId)->approved()
            ->whereDoesntHave('purchaseOrders', fn ($q) => $q->whereNotIn('status', ['cancelled']))
            ->orderBy('approved_date', 'desc')->get();

        return Inertia::render('ERP/Procurement/PurchaseOrders', compact('initialData', 'initialApprovedPRs'));
    })->middleware('permission:view-procurement|access-purchase-orders')->name('purchase-orders');

    Route::get('/stock-request-approval', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }
        $shopOwnerId = Auth::guard('user')->user()->shop_owner_id;
        $initialData = \App\Models\StockRequestApproval::with(['shopOwner', 'inventoryItem.sizes', 'inventoryItem.colorVariants.sizes', 'requester', 'approver'])
            ->where('shop_owner_id', $shopOwnerId)->orderBy('requested_date', 'desc')->paginate(100);

        return Inertia::render('ERP/Procurement/StockRequestApproval', compact('initialData'));
    })->middleware('permission:view-procurement|access-stock-request-approval')->name('stock-request-approval');

    Route::get('/suppliers-management', [\App\Http\Controllers\Erp\ReadPageController::class, 'procurementSuppliers'])
        ->middleware('permission:view-procurement|access-suppliers-management')
        ->name('suppliers-management');
});

// STAFF routes (both MANAGER and STAFF can access)
Route::prefix('erp/staff')->name('erp.staff.')->middleware(['auth:user', 'manager.staff:staff'])->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\Staff\StaffDashboardController::class, 'index'])
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

    Route::get('/repair-dashboard', [\App\Http\Controllers\Erp\ReadPageController::class, 'repairDashboard'])
        ->middleware(['permission:access-repairer-dashboard', 'check.user.business.type:repair,both'])
        ->name('repair-dashboard');

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
        $user = Auth::guard('user')->user();

        if ($user?->force_password_change) {
            return redirect()->route('erp.profile');
        }

        $normalizedRoleNames = $user
            ? $user->getRoleNames()->map(fn (string $name) => strtoupper(trim($name)))->values()->all()
            : [];

        $isCashierOnly = in_array('CASHIER', $normalizedRoleNames, true)
            && count(array_diff($normalizedRoleNames, ['CASHIER'])) === 0;

        if ($isCashierOnly) {
            abort(403, 'Cashier accounts cannot access staff inventory overview.');
        }

        return Inertia::render('ERP/Manager/InventoryOverview');
    })->middleware(['permission:access-staff-dashboard|access-product-management|access-product-upload-staff', 'check.user.business.type:retail,both'])->name('inventory-overview');

    Route::get('/attendance', function () {
        if (Auth::guard('user')->user()?->force_password_change) {
            return redirect()->route('erp.profile');
        }

        return Inertia::render('ERP/STAFF/timeIn');
    })->middleware('permission:access-staff-time-in')->name('attendance');
});

// Backward-compatible warranty queue URL for older ERP repairer links.
Route::middleware(['auth:user', 'manager.staff:staff', 'permission:access-repair-job-orders', 'check.user.business.type:repair,both'])
    ->get('/erp/repairer/warranty-queue', function () {
        return redirect()->route('erp.staff.warranty-queue');
    })->name('erp.repairer.warranty-queue');

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

// Cashier dashboard - shop-scoped summary for unified POS users
Route::get('/erp/cashier/dashboard', [\App\Http\Controllers\Erp\CashierDashboardController::class, 'index'])
    ->middleware(['auth:user', 'permission:access-unified-pos'])
    ->name('erp.cashier.dashboard');

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

// Manager API Routes
Route::prefix('api/manager')->name('api.manager.')->middleware([
    'web',
    'auth:user',
    'check.suspension',
])->group(function () {
    Route::get('/dashboard/stats', [ManagerController::class, 'getDashboardStats'])
        ->middleware('manager.capability:dashboard-read')->name('dashboard.stats');
    Route::get('/dss-insights', [\App\Http\Controllers\ShopOwner\DssController::class, 'getInsights'])
        ->middleware('manager.capability:reports-read')->name('dss-insights');
    Route::get('/audit-logs', [\App\Http\Controllers\ActivityLogController::class, 'managerIndex'])
        ->middleware('manager.capability:audit-read')->name('audit-logs');
    Route::get('/staff-performance', [ManagerController::class, 'getStaffPerformance'])
        ->middleware('manager.capability:staff-workload-read')->name('staff-performance');
    Route::get('/staff-workload', [ManagerController::class, 'getStaffWorkload'])
        ->middleware('manager.capability:staff-workload-read')->name('staff-workload');
    Route::get('/inventory-overview', [ManagerController::class, 'getInventoryOverview'])
        ->middleware('manager.capability:inventory-read')->name('inventory-overview');
    // Manager Job Order Routes
    Route::prefix('orders')->middleware('check.user.business.type:retail,both')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\ManagerOrderController::class, 'index'])
            ->middleware('manager.capability:job-orders-read')->name('orders.index');
        Route::get('/{id}/eligible-replacements', [\App\Http\Controllers\Api\ManagerOrderController::class, 'eligibleReplacements'])
            ->whereNumber('id')
            ->middleware('manager.capability:job-orders-read')->name('orders.replacements');
        Route::get('/{id}', [\App\Http\Controllers\Api\ManagerOrderController::class, 'show'])
            ->whereNumber('id')
            ->middleware('manager.capability:job-orders-read')->name('orders.show');
        Route::post('/{id}/reassign', [\App\Http\Controllers\Api\ManagerOrderController::class, 'reassign'])
            ->whereNumber('id')
            ->middleware('manager.capability:order-reassign')->name('orders.reassign');
    });

    Route::get('/reports', [ManagerController::class, 'getReports'])
        ->middleware('manager.capability:reports-read')->name('reports.index');
    Route::post('/reports/generate', [ManagerController::class, 'generateReport'])
        ->middleware('manager.capability:reports-generate')->name('reports.generate');
    Route::post('/reports/{id}/review', [ManagerController::class, 'reviewReport'])
        ->whereNumber('id')
        ->middleware('manager.capability:reports-review')->name('reports.review');
    Route::post('/reports/{id}/send', [ManagerController::class, 'sendReport'])
        ->whereNumber('id')
        ->middleware('manager.capability:reports-review')->name('reports.send');
    Route::get('/reports/{id}/download', [ManagerController::class, 'downloadReport'])
        ->middleware('manager.capability:reports-read')->name('reports.download');

    // Suspension Approval Routes
    Route::prefix('suspension-requests')->group(function () {
        Route::get('/', [\App\Http\Controllers\Erp\Manager\SuspensionApprovalController::class, 'index'])
            ->middleware('manager.capability:suspension-approvals-read')->name('suspension_requests.index');
        Route::get('/{id}', [\App\Http\Controllers\Erp\Manager\SuspensionApprovalController::class, 'show'])
            ->middleware('manager.capability:suspension-approvals-read')->name('suspension_requests.show');
        Route::post('/{id}/review', [\App\Http\Controllers\Erp\Manager\SuspensionApprovalController::class, 'review'])
            ->middleware('manager.capability:suspension-decision')->name('suspension_requests.review');
    });

    // Employee lifecycle approval routes
    Route::prefix('termination-requests')->group(function () {
        Route::get('/', [\App\Http\Controllers\Erp\Manager\EmployeeLifecycleApprovalController::class, 'indexTermination'])
            ->middleware('manager.capability:termination-approvals-read')->name('termination_requests.index');
        Route::get('/{id}', [\App\Http\Controllers\Erp\Manager\EmployeeLifecycleApprovalController::class, 'showTermination'])
            ->whereNumber('id')->middleware('manager.capability:termination-approvals-read')->name('termination_requests.show');
        Route::post('/{id}/review', [\App\Http\Controllers\Erp\Manager\EmployeeLifecycleApprovalController::class, 'reviewTermination'])
            ->whereNumber('id')->middleware('manager.capability:termination-decision')->name('termination_requests.review');
    });

    Route::prefix('rehire-requests')->group(function () {
        Route::get('/', [\App\Http\Controllers\Erp\Manager\EmployeeLifecycleApprovalController::class, 'indexRehire'])
            ->middleware('manager.capability:rehire-approvals-read')->name('rehire_requests.index');
        Route::get('/{id}', [\App\Http\Controllers\Erp\Manager\EmployeeLifecycleApprovalController::class, 'showRehire'])
            ->whereNumber('id')->middleware('manager.capability:rehire-approvals-read')->name('rehire_requests.show');
        Route::post('/{id}/review', [\App\Http\Controllers\Erp\Manager\EmployeeLifecycleApprovalController::class, 'reviewRehire'])
            ->whereNumber('id')->middleware('manager.capability:rehire-decision')->name('rehire_requests.review');
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

// Module-specific API routes are registered in bootstrap/app.php via withRouting(... then: ...)
