<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\PremiumPlan;
use App\Models\ShopOwnerSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        $subscription = ShopOwnerSubscription::create([
            'shop_owner_id'       => $shopOwner->id,
            'premium_plan_id'     => $plan->id,
            'plan_code'           => $plan->plan_code,
            'showroom_slot_limit' => $plan->showroom_slot_limit,
            'status'              => 'pending',
        ]);

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
     * Body (optional): { "subscription_id": 123 }
     */
    public function cancel(Request $request)
    {
        $shopOwner = Auth::guard('shop_owner')->user();

        $validated = $request->validate([
            'subscription_id' => 'nullable|integer',
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

        $subscription->update([
            // Cancellation means stop renewal only. Access remains until the original deadline.
            'status'  => 'cancelled',
            'ends_at' => $effectiveEndsAt,
        ]);

        return response()->json([
            'success'      => true,
            'message'      => 'Premium subscription cancelled successfully.',
            'subscription' => $subscription->fresh('premiumPlan'),
        ]);
    }

    /**
     * Return the shop owner's most recent active or pending subscription.
     *
     * GET /api/shop-owner/premium/subscription
     */
    public function currentSubscription(Request $request)
    {
        $shopOwner = Auth::guard('shop_owner')->user();

        $subscription = ShopOwnerSubscription::with('premiumPlan')
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
