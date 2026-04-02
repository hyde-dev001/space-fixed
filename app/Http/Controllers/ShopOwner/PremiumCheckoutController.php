<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\PremiumPlan;
use App\Models\ShopOwnerSubscription;
use App\Models\ShopOwnerSubscriptionPayment;
use App\Services\PremiumProrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PremiumCheckoutController extends Controller
{
    /**
     * Preview an upgrade with proration.
     *
     * POST /api/shop-owner/premium/upgrade
     */
    public function upgrade(Request $request, PremiumProrationService $prorationService)
    {
        $shopOwner = Auth::guard('shop_owner')->user();

        $validated = $request->validate([
            'user_id' => 'nullable|integer',
            'new_plan_id' => 'nullable|integer|exists:premium_plans,id',
            'plan_code' => 'nullable|string|exists:premium_plans,plan_code',
        ]);

        $targetPlan = $this->resolveTargetPlan($validated);
        if (!$targetPlan) {
            return response()->json([
                'success' => false,
                'message' => 'A valid target plan is required.',
            ], 422);
        }

        $currentSubscription = $this->resolveEntitledSubscription((int) $shopOwner->id);
        if (!$currentSubscription) {
            return response()->json([
                'success' => false,
                'message' => 'No active subscription found. Please subscribe normally instead of upgrading.',
            ], 409);
        }

        $currentPlan = $currentSubscription->premiumPlan;
        if (!$currentPlan) {
            return response()->json([
                'success' => false,
                'message' => 'Current subscription plan is missing. Please contact support.',
            ], 422);
        }

        if ((float) $targetPlan->price <= (float) $currentPlan->price) {
            return response()->json([
                'success' => false,
                'message' => 'Upgrade requires a higher-priced plan. Use schedule downgrade for lower plans.',
            ], 422);
        }

        $preview = $prorationService->preview($currentSubscription, $currentPlan, $targetPlan, now());

        return response()->json([
            'success' => true,
            'current_plan' => [
                'id' => $currentPlan->id,
                'name' => $currentPlan->name,
                'plan_code' => $currentPlan->plan_code,
                'price' => (float) $currentPlan->price,
                'duration_days' => (int) $currentPlan->duration_days,
                'showroom_slot_limit' => (int) $currentPlan->showroom_slot_limit,
            ],
            'new_plan' => [
                'id' => $targetPlan->id,
                'name' => $targetPlan->name,
                'plan_code' => $targetPlan->plan_code,
                'price' => (float) $targetPlan->price,
                'duration_days' => (int) $targetPlan->duration_days,
                'showroom_slot_limit' => (int) $targetPlan->showroom_slot_limit,
            ],
            'remaining_value' => $preview['remaining_value'],
            'remaining_days' => $preview['remaining_days'],
            'daily_rate' => $preview['daily_rate'],
            'new_plan_price' => $preview['new_plan_price'],
            'final_price' => $preview['final_price'],
            'new_expiry' => $preview['new_expiry'],
            'payment_required' => $preview['payment_required'],
            'slot_delta' => max(0, (int) $targetPlan->showroom_slot_limit - (int) $currentPlan->showroom_slot_limit),
        ]);
    }

    /**
     * Confirm an upgrade and process payment.
     *
     * POST /api/shop-owner/premium/confirm-upgrade
     */
    public function confirmUpgrade(Request $request, PremiumProrationService $prorationService)
    {
        $shopOwner = Auth::guard('shop_owner')->user();

        $validated = $request->validate([
            'user_id' => 'nullable|integer',
            'new_plan_id' => 'nullable|integer|exists:premium_plans,id',
            'plan_code' => 'nullable|string|exists:premium_plans,plan_code',
        ]);

        $targetPlan = $this->resolveTargetPlan($validated);
        if (!$targetPlan) {
            return response()->json([
                'success' => false,
                'message' => 'A valid target plan is required.',
            ], 422);
        }

        if (!$this->hasPlanChangeColumns()) {
            return response()->json([
                'success' => false,
                'message' => 'Upgrade flow is not available yet. Please run the latest migrations first.',
            ], 409);
        }

        $currentSubscription = $this->resolveEntitledSubscription((int) $shopOwner->id);
        if (!$currentSubscription) {
            return response()->json([
                'success' => false,
                'message' => 'No active subscription found. Please subscribe normally instead of upgrading.',
            ], 409);
        }

        $currentPlan = $currentSubscription->premiumPlan;
        if (!$currentPlan) {
            return response()->json([
                'success' => false,
                'message' => 'Current subscription plan is missing. Please contact support.',
            ], 422);
        }

        if ((float) $targetPlan->price <= (float) $currentPlan->price) {
            return response()->json([
                'success' => false,
                'message' => 'Upgrade requires a higher-priced plan. Use schedule downgrade for lower plans.',
            ], 422);
        }

        $preview = $prorationService->preview($currentSubscription, $currentPlan, $targetPlan, now());
        $finalPrice = (float) $preview['final_price'];
        $prorationCredit = (float) $preview['remaining_value'];
        $newEndsAt = now()->addDays(max(1, (int) $targetPlan->duration_days));

        if ($finalPrice <= 0) {
            $newSubscription = DB::transaction(function () use ($shopOwner, $currentSubscription, $targetPlan, $newEndsAt, $prorationCredit) {
                $lockedCurrent = ShopOwnerSubscription::query()
                    ->where('id', $currentSubscription->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $newSubscription = ShopOwnerSubscription::create([
                    'shop_owner_id' => $shopOwner->id,
                    'premium_plan_id' => $targetPlan->id,
                    'plan_code' => $targetPlan->plan_code,
                    'showroom_slot_limit' => $targetPlan->showroom_slot_limit,
                    'status' => 'active',
                    'auto_renew' => true,
                    'auto_renew_status' => ShopOwnerSubscription::AUTO_RENEW_STATUS_ENABLED,
                    'replaces_subscription_id' => $lockedCurrent->id,
                    'payment_method' => 'proration_credit',
                    'paid_amount' => 0,
                    'starts_at' => now(),
                    'ends_at' => $newEndsAt,
                ]);

                $lockedCurrent->update([
                    'status' => 'cancelled',
                    'auto_renew' => false,
                    'auto_renew_status' => ShopOwnerSubscription::AUTO_RENEW_STATUS_DISABLED,
                    'ends_at' => now(),
                    'pending_premium_plan_id' => null,
                    'pending_plan_effective_at' => null,
                ]);

                ShopOwnerSubscriptionPayment::create([
                    'shop_owner_id' => $shopOwner->id,
                    'subscription_id' => $newSubscription->id,
                    'source_subscription_id' => $lockedCurrent->id,
                    'from_premium_plan_id' => $lockedCurrent->premium_plan_id,
                    'to_premium_plan_id' => $targetPlan->id,
                    'payment_type' => 'upgrade',
                    'gateway' => 'paymongo',
                    'currency' => 'PHP',
                    'plan_price' => (float) $targetPlan->price,
                    'proration_credit' => $prorationCredit,
                    'amount_due' => 0,
                    'amount_paid' => 0,
                    'status' => 'paid',
                    'metadata' => [
                        'zero_charge_upgrade' => true,
                    ],
                    'paid_at' => now(),
                ]);

                return $newSubscription;
            });

            return response()->json([
                'success' => true,
                'immediate_applied' => true,
                'payment_required' => false,
                'remaining_value' => $prorationCredit,
                'final_price' => 0,
                'new_expiry' => $newSubscription->ends_at?->toISOString(),
                'subscription' => $newSubscription->fresh('premiumPlan'),
            ]);
        }

        $pendingSubscription = ShopOwnerSubscription::create([
            'shop_owner_id' => $shopOwner->id,
            'premium_plan_id' => $targetPlan->id,
            'plan_code' => $targetPlan->plan_code,
            'showroom_slot_limit' => $targetPlan->showroom_slot_limit,
            'status' => 'pending',
            'auto_renew' => true,
            'auto_renew_status' => ShopOwnerSubscription::AUTO_RENEW_STATUS_ENABLED,
            'replaces_subscription_id' => $currentSubscription->id,
            'payment_method' => 'paymongo',
            'paid_amount' => $finalPrice,
        ]);

        $payment = ShopOwnerSubscriptionPayment::create([
            'shop_owner_id' => $shopOwner->id,
            'subscription_id' => $pendingSubscription->id,
            'source_subscription_id' => $currentSubscription->id,
            'from_premium_plan_id' => $currentSubscription->premium_plan_id,
            'to_premium_plan_id' => $targetPlan->id,
            'payment_type' => 'upgrade',
            'gateway' => 'paymongo',
            'currency' => 'PHP',
            'plan_price' => (float) $targetPlan->price,
            'proration_credit' => $prorationCredit,
            'amount_due' => $finalPrice,
            'status' => 'pending',
            'metadata' => [
                'source_subscription_id' => $currentSubscription->id,
                'new_expiry' => $newEndsAt->toISOString(),
            ],
        ]);

        $checkoutResult = $this->createCheckoutSession(
            amount: $finalPrice,
            successUrl: route('shop-owner.premium-success', ['subscription_id' => $pendingSubscription->id]),
            cancelUrl: route('shop-owner.premium-cancel', ['subscription_id' => $pendingSubscription->id]),
            description: 'SoleSpace ' . $targetPlan->name . ' upgrade charge (after prorated credit)',
            lineItemName: 'SoleSpace ' . $targetPlan->name . ' Upgrade',
            metadata: [
                'type' => 'premium_subscription_upgrade',
                'subscription_id' => (string) $pendingSubscription->id,
                'source_subscription_id' => (string) $currentSubscription->id,
                'payment_record_id' => (string) $payment->id,
                'plan_code' => $targetPlan->plan_code,
                'proration_credit' => (string) $prorationCredit,
                'final_price' => (string) $finalPrice,
            ]
        );

        if (!($checkoutResult['success'] ?? false)) {
            $pendingSubscription->update(['status' => 'failed']);
            $payment->update(['status' => 'failed']);

            return response()->json([
                'success' => false,
                'message' => (string) ($checkoutResult['message'] ?? 'Failed to create checkout session.'),
            ], 502);
        }

        $pendingSubscription->update([
            'paymongo_session_id' => $checkoutResult['session_id'],
        ]);

        $payment->update([
            'paymongo_session_id' => $checkoutResult['session_id'],
        ]);

        return response()->json([
            'success' => true,
            'immediate_applied' => false,
            'payment_required' => true,
            'checkout_url' => $checkoutResult['checkout_url'],
            'session_id' => $checkoutResult['session_id'],
            'remaining_value' => $prorationCredit,
            'final_price' => $finalPrice,
            'new_expiry' => $newEndsAt->toISOString(),
        ]);
    }

    /**
     * Schedule a downgrade to be applied at period end.
     *
     * POST /api/shop-owner/premium/schedule-downgrade
     */
    public function scheduleDowngrade(Request $request)
    {
        $shopOwner = Auth::guard('shop_owner')->user();

        $validated = $request->validate([
            'user_id' => 'nullable|integer',
            'new_plan_id' => 'nullable|integer|exists:premium_plans,id',
            'plan_code' => 'nullable|string|exists:premium_plans,plan_code',
        ]);

        if (!$this->hasPlanChangeColumns()) {
            return response()->json([
                'success' => false,
                'message' => 'Downgrade scheduling is not available yet. Please run the latest migrations first.',
            ], 409);
        }

        $targetPlan = $this->resolveTargetPlan($validated);
        if (!$targetPlan) {
            return response()->json([
                'success' => false,
                'message' => 'A valid target plan is required.',
            ], 422);
        }

        $subscription = $this->resolveEntitledSubscription((int) $shopOwner->id);
        if (!$subscription || !$subscription->premiumPlan) {
            return response()->json([
                'success' => false,
                'message' => 'No active subscription found to downgrade.',
            ], 404);
        }

        if ((float) $targetPlan->price >= (float) $subscription->premiumPlan->price) {
            return response()->json([
                'success' => false,
                'message' => 'Downgrade requires a lower-priced target plan.',
            ], 422);
        }

        $effectiveAt = $subscription->ends_at ?: $subscription->starts_at?->copy()->addDays((int) $subscription->premiumPlan->duration_days);

        $subscription->update([
            'pending_premium_plan_id' => $targetPlan->id,
            'pending_plan_effective_at' => $effectiveAt,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Downgrade scheduled successfully. It will apply at the end of the current billing cycle.',
            'effective_at' => $effectiveAt?->toISOString(),
            'subscription' => $subscription->fresh(['premiumPlan', 'pendingPremiumPlan']),
        ]);
    }

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

        $paymentMethodTypes = ['card', 'gcash', 'paymaya', 'grab_pay'];

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
                    'payment_method_types' => $paymentMethodTypes,
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

        $entitledSubscription = ShopOwnerSubscription::with(['premiumPlan', 'pendingPremiumPlan'])
            ->where('shop_owner_id', $shopOwner->id)
            ->showroomEntitled()
            ->latest('updated_at')
            ->first();

        $subscription = $entitledSubscription ?: ShopOwnerSubscription::with(['premiumPlan', 'pendingPremiumPlan'])
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
            ->get(['id', 'plan_code', 'name', 'description', 'price', 'duration_days', 'showroom_slot_limit']);

        return response()->json([
            'success' => true,
            'plans'   => $plans,
        ]);
    }

    /**
     * @param array<string, mixed> $validated
     */
    private function resolveTargetPlan(array $validated): ?PremiumPlan
    {
        if (!empty($validated['new_plan_id'])) {
            return PremiumPlan::query()
                ->where('id', (int) $validated['new_plan_id'])
                ->where('status', 'active')
                ->first();
        }

        if (!empty($validated['plan_code'])) {
            return PremiumPlan::query()
                ->where('plan_code', (string) $validated['plan_code'])
                ->where('status', 'active')
                ->first();
        }

        return null;
    }

    private function resolveEntitledSubscription(int $shopOwnerId): ?ShopOwnerSubscription
    {
        return ShopOwnerSubscription::with('premiumPlan')
            ->where('shop_owner_id', $shopOwnerId)
            ->showroomEntitled()
            ->latest('updated_at')
            ->first();
    }

    private function hasPlanChangeColumns(): bool
    {
        return Schema::hasColumn('shop_owner_subscriptions', 'pending_premium_plan_id')
            && Schema::hasColumn('shop_owner_subscriptions', 'pending_plan_effective_at')
            && Schema::hasColumn('shop_owner_subscriptions', 'replaces_subscription_id');
    }

    /**
     * @param array<string, string> $metadata
     * @return array<string, mixed>
     */
    private function createCheckoutSession(
        float $amount,
        string $successUrl,
        string $cancelUrl,
        string $description,
        string $lineItemName,
        array $metadata
    ): array {
        $apiKey = (string) config('services.paymongo.secret_key');

        $paymentMethodTypes = ['card', 'gcash', 'paymaya', 'grab_pay'];

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($apiKey . ':'),
        ])->post('https://api.paymongo.com/v1/checkout_sessions', [
            'data' => [
                'attributes' => [
                    'success_url' => $successUrl,
                    'cancel_url' => $cancelUrl,
                    'description' => $description,
                    'send_email_receipt' => true,
                    'show_description' => true,
                    'show_line_items' => true,
                    'line_items' => [[
                        'currency' => 'PHP',
                        'amount' => (int) round($amount * 100),
                        'name' => $lineItemName,
                        'description' => $description,
                        'quantity' => 1,
                    ]],
                    'payment_method_types' => $paymentMethodTypes,
                    'metadata' => $metadata,
                ],
            ],
        ]);

        if ($response->failed()) {
            $errors = $response->json('errors');
            $errorMsg = $errors[0]['detail'] ?? $response->json('message') ?? 'PayMongo error';

            Log::error('PayMongo checkout session failed', [
                'http_status' => $response->status(),
                'body' => $response->json(),
                'metadata' => $metadata,
            ]);

            return [
                'success' => false,
                'message' => 'Payment gateway error: ' . $errorMsg,
            ];
        }

        $data = $response->json();
        $checkoutUrl = $data['data']['attributes']['checkout_url'] ?? null;
        $sessionId = $data['data']['id'] ?? null;

        if (!$checkoutUrl || !$sessionId) {
            return [
                'success' => false,
                'message' => 'Incomplete response from payment gateway.',
            ];
        }

        return [
            'success' => true,
            'checkout_url' => $checkoutUrl,
            'session_id' => $sessionId,
        ];
    }
}
