<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\PremiumPlan;
use App\Models\ShopOwnerSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PremiumCheckoutController extends Controller
{
    /**
     * Initiate a PayMongo checkout session for a premium subscription.
     *
     * Uses the platform PAYMONGO_SECRET_KEY from .env — NOT the shop's own key —
     * because this is a B2B subscription fee paid to SoleSpace.
     *
     * POST /api/shop-owner/premium/checkout
     * Body: { "plan_code": "basic" | "pro" | "premium" }
     *
     * Returns: { success, checkout_url, subscription_id, session_id }
     */
    public function checkout(Request $request)
    {
        $validated = $request->validate([
            'plan_code' => 'required|string|exists:premium_plans,plan_code',
        ]);

        $shopOwner = Auth::guard('shop_owner')->user();

        // Guard: block if there is already an active subscription.
        // Active scope accepts open-ended subscriptions (ends_at = null).
        $existing = ShopOwnerSubscription::where('shop_owner_id', $shopOwner->id)
            ->active()
            ->first();

        if ($existing) {
            return response()->json([
                'success'      => false,
                'message'      => 'You already have an active premium subscription.',
                'subscription' => $existing,
            ], 409);
        }

        $plan = PremiumPlan::where('plan_code', $validated['plan_code'])
            ->where('status', 'active')
            ->firstOrFail();

        // Create a pending subscription record before calling PayMongo so we
        // always have a trace even if the gateway call fails.
        $hasAutoRenewColumns = $this->hasAutoRenewColumns();

        $subscriptionPayload = [
            'shop_owner_id'       => $shopOwner->id,
            'premium_plan_id'     => $plan->id,
            'plan_code'           => $plan->plan_code,
            'showroom_slot_limit' => $plan->showroom_slot_limit,
            'status'              => 'pending',
        ];

        if ($hasAutoRenewColumns) {
            $subscriptionPayload['auto_renew'] = true;
            $subscriptionPayload['auto_renew_status'] = ShopOwnerSubscription::AUTO_RENEW_STATUS_ENABLED;
        }

        $subscription = ShopOwnerSubscription::create($subscriptionPayload);

        $successUrl  = route('shop-owner.premium-success', [
            'subscription_id' => $subscription->id,
        ]);
        $cancelUrl   = route('shop-owner.premium-cancel', [
            'subscription_id' => $subscription->id,
        ]);
        $description = 'SoleSpace ' . $plan->name . ' – ' . $plan->duration_days . '-day subscription';

        // Platform key: this charge is paid TO SoleSpace, so we use our own key
        $apiKey = config('services.paymongo.secret_key');

        $response = Http::withHeaders([
            'Content-Type'  => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($apiKey . ':'),
        ])->post('https://api.paymongo.com/v1/checkout_sessions', [
            'data' => [
                'attributes' => [
                    'success_url'          => $successUrl,
                    'cancel_url'           => $cancelUrl,
                    'description'          => $description,
                    'send_email_receipt'   => true,
                    'show_description'     => true,
                    'show_line_items'      => true,
                    'line_items'           => [[
                        'currency'    => 'PHP',
                        'amount'      => (int) ($plan->price * 100), // PayMongo expects centavos
                        'name'        => 'SoleSpace ' . $plan->name,
                        'description' => $description,
                        'quantity'    => 1,
                    ]],
                    'payment_method_types' => ['card', 'gcash', 'paymaya', 'grab_pay'],
                    'metadata'             => [
                        'type'            => 'premium_subscription',
                        'subscription_id' => (string) $subscription->id,
                        'shop_owner_id'   => (string) $shopOwner->id,
                        'plan_code'       => $plan->plan_code,
                    ],
                ],
            ],
        ]);

        if ($response->failed()) {
            $subscription->update(['status' => 'failed']);

            $errors   = $response->json('errors');
            $errorMsg = $errors[0]['detail'] ?? $response->json('message') ?? 'PayMongo error';

            Log::error('PayMongo premium checkout session failed', [
                'shop_owner_id'   => $shopOwner->id,
                'subscription_id' => $subscription->id,
                'plan_code'       => $plan->plan_code,
                'http_status'     => $response->status(),
                'body'            => $response->json(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment gateway error: ' . $errorMsg,
            ], 502);
        }

        $data        = $response->json();
        $checkoutUrl = $data['data']['attributes']['checkout_url'] ?? null;
        $sessionId   = $data['data']['id'] ?? null;

        if (!$checkoutUrl || !$sessionId) {
            $subscription->update(['status' => 'failed']);

            Log::error('Incomplete PayMongo response for premium checkout', [
                'subscription_id' => $subscription->id,
                'response'        => $data,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Incomplete response from payment gateway.',
            ], 500);
        }

        // Persist the session ID so the webhook can look up this subscription
        $subscription->update(['paymongo_session_id' => $sessionId]);

        Log::info('Premium checkout session created', [
            'shop_owner_id'   => $shopOwner->id,
            'subscription_id' => $subscription->id,
            'plan_code'       => $plan->plan_code,
            'session_id'      => $sessionId,
        ]);

        return response()->json([
            'success'         => true,
            'checkout_url'    => $checkoutUrl,
            'subscription_id' => $subscription->id,
            'session_id'      => $sessionId,
        ]);
    }

    /**
     * Cancel a pending or active premium subscription for the current shop owner.
     *
     * POST /api/shop-owner/premium/cancel
     * Body (optional): {
     *   "subscription_id": 123,
     *   "cancellation_reason": "too_expensive",
     *   "cancellation_notes": "optional notes"
     * }
     */
    public function cancel(Request $request)
    {
        $shopOwner = Auth::guard('shop_owner')->user();

        $validated = $request->validate([
            'subscription_id' => 'nullable|integer',
            'cancellation_reason' => 'nullable|string|max:120',
            'cancellation_notes' => 'nullable|string|max:1000',
        ]);

        $subscriptionQuery = ShopOwnerSubscription::with('premiumPlan')
            ->where('shop_owner_id', $shopOwner->id)
            ->whereIn('status', ['pending', 'active']);

        if (!empty($validated['subscription_id'])) {
            $subscriptionQuery->where('id', (int) $validated['subscription_id']);
        }

        $subscription = $subscriptionQuery->latest('updated_at')->first();

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'No pending or active premium subscription was found to cancel.',
            ], 404);
        }

        $effectiveEndsAt = $subscription->ends_at;
        if ($subscription->status === 'active' && !$effectiveEndsAt && $subscription->starts_at && $subscription->premiumPlan) {
            $effectiveEndsAt = $subscription->starts_at->copy()->addDays((int) $subscription->premiumPlan->duration_days);
        }

        $reason = trim((string) ($validated['cancellation_reason'] ?? ''));
        $notes = trim((string) ($validated['cancellation_notes'] ?? ''));
        $hasCancellationColumns = Schema::hasColumn('shop_owner_subscriptions', 'cancellation_reason')
            && Schema::hasColumn('shop_owner_subscriptions', 'cancellation_notes');
        $hasAutoRenewColumns = $this->hasAutoRenewColumns();

        $updatePayload = [
            // Cancellation means stop renewal only. Access remains until the original deadline.
            'status'  => 'cancelled',
            'ends_at' => $effectiveEndsAt,
        ];

        if ($hasAutoRenewColumns) {
            $updatePayload['auto_renew'] = false;
            $updatePayload['auto_renew_status'] = ShopOwnerSubscription::AUTO_RENEW_STATUS_DISABLED;
        }

        if ($hasCancellationColumns) {
            $updatePayload['cancellation_reason'] = $reason !== '' ? $reason : null;
            $updatePayload['cancellation_notes'] = $notes !== '' ? $notes : null;
        }

        $subscription->update($updatePayload);

        Log::info('Shop owner cancelled premium subscription', [
            'shop_owner_id' => $shopOwner->id,
            'subscription_id' => $subscription->id,
            'reason' => $reason !== '' ? $reason : null,
            'notes' => $notes !== '' ? $notes : null,
        ]);

        return response()->json([
            'success'      => true,
            'message'      => 'Premium subscription cancelled successfully.',
            'subscription' => $subscription->fresh('premiumPlan'),
        ]);
    }

    /**
     * Toggle auto-renew for the current active premium subscription.
     *
     * PATCH /api/shop-owner/premium/auto-renew
     * Body: { "enabled": true|false }
     */
    public function toggleAutoRenewal(Request $request)
    {
        $shopOwner = Auth::guard('shop_owner')->user();

        if (!$this->hasAutoRenewColumns()) {
            return response()->json([
                'success' => false,
                'message' => 'Auto renewal is not available yet. Please run the latest database migration first.',
            ], 409);
        }

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $subscription = ShopOwnerSubscription::with('premiumPlan')
            ->where('shop_owner_id', $shopOwner->id)
            ->showroomEntitled()
            ->latest('updated_at')
            ->first();

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'No valid premium subscription found.',
            ], 404);
        }

        $enabled = (bool) $validated['enabled'];

        $updatePayload = [
            'auto_renew' => $enabled,
            'auto_renew_status' => $enabled
                ? ShopOwnerSubscription::AUTO_RENEW_STATUS_ENABLED
                : ShopOwnerSubscription::AUTO_RENEW_STATUS_DISABLED,
        ];

        // If user re-enables auto-renew during remaining paid access,
        // restore status to active so renewal processing can include this subscription.
        if ($enabled && $subscription->status === 'cancelled') {
            $updatePayload['status'] = 'active';

            if (
                Schema::hasColumn('shop_owner_subscriptions', 'cancellation_reason')
                && Schema::hasColumn('shop_owner_subscriptions', 'cancellation_notes')
            ) {
                $updatePayload['cancellation_reason'] = null;
                $updatePayload['cancellation_notes'] = null;
            }
        }

        $subscription->update($updatePayload);

        Log::info('Shop owner toggled premium auto-renew', [
            'shop_owner_id' => $shopOwner->id,
            'subscription_id' => $subscription->id,
            'enabled' => $enabled,
        ]);

        return response()->json([
            'success' => true,
            'message' => $enabled
                ? 'Auto renewal enabled. Your subscription will renew at period end.'
                : 'Auto renewal disabled. Your subscription will end at period end.',
            'subscription' => $subscription->fresh('premiumPlan'),
        ]);
    }

    private function hasAutoRenewColumns(): bool
    {
        return Schema::hasColumn('shop_owner_subscriptions', 'auto_renew')
            && Schema::hasColumn('shop_owner_subscriptions', 'auto_renew_status');
    }

    /**
     * Return the shop owner's most recent active or pending subscription.
     *
     * GET /api/shop-owner/premium/subscription
     */
    public function currentSubscription(Request $request)
    {
        $shopOwner = Auth::guard('shop_owner')->user();

        $entitledSubscription = ShopOwnerSubscription::with('premiumPlan')
            ->where('shop_owner_id', $shopOwner->id)
            ->showroomEntitled()
            ->latest('updated_at')
            ->first();

        $subscription = $entitledSubscription ?: ShopOwnerSubscription::with('premiumPlan')
            ->where('shop_owner_id', $shopOwner->id)
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'cancelled' THEN 1 WHEN 'pending' THEN 2 WHEN 'expired' THEN 3 WHEN 'failed' THEN 4 ELSE 5 END")
            ->latest('updated_at')
            ->first();

        return response()->json([
            'success'      => true,
            'subscription' => $subscription,
        ]);
    }

    /**
     * Return all available (active) premium plans from the database.
     *
     * GET /api/shop-owner/premium/plans
     */
    public function plans(Request $request)
    {
        $plans = PremiumPlan::where('status', 'active')
            ->orderBy('price')
            ->get(['plan_code', 'name', 'description', 'price', 'duration_days', 'showroom_slot_limit']);

        return response()->json([
            'success' => true,
            'plans'   => $plans,
        ]);
    }
}
