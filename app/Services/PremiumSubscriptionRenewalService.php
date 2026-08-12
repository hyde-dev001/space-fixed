<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\PremiumPlan;
use App\Models\ShopOwnerSubscription;
use App\Models\ShopOwnerSubscriptionPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PremiumSubscriptionRenewalService
{
    /**
     * Create or reuse a renewal checkout session for a due premium subscription.
     */
    public function createRenewalCheckout(ShopOwnerSubscription $sourceSubscription): array
    {
        $sourceSubscription->loadMissing('premiumPlan', 'shopOwner', 'pendingPremiumPlan');

        if (!$sourceSubscription->shopOwner) {
            return [
                'success' => false,
                'message' => 'Shop owner was not found for this subscription.',
            ];
        }

        if (!$sourceSubscription->auto_renew) {
            return [
                'success' => false,
                'message' => 'Auto-renew is disabled for this subscription.',
            ];
        }

        $pendingRenewal = ShopOwnerSubscription::query()
            ->where('renewal_of_subscription_id', $sourceSubscription->id)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if ($pendingRenewal) {
            return [
                'success' => true,
                'message' => 'Pending renewal checkout already exists.',
                'existing' => true,
                'renewal_subscription' => $pendingRenewal,
                'checkout_url' => $sourceSubscription->renewal_checkout_url,
            ];
        }

        $plan = $sourceSubscription->premiumPlan;
        if (!$plan) {
            $plan = PremiumPlan::query()
                ->where('plan_code', $sourceSubscription->plan_code)
                ->where('status', 'active')
                ->first();
        }

        // If a downgrade was scheduled for this cycle end, renew using the pending plan.
        if (
            Schema::hasColumn('shop_owner_subscriptions', 'pending_premium_plan_id')
            && Schema::hasColumn('shop_owner_subscriptions', 'pending_plan_effective_at')
            && $sourceSubscription->pendingPremiumPlan
            && $sourceSubscription->pending_plan_effective_at
            && $sourceSubscription->pending_plan_effective_at->lessThanOrEqualTo(now())
        ) {
            $plan = $sourceSubscription->pendingPremiumPlan;
        }

        if (!$plan) {
            $sourceSubscription->update([
                'auto_renew_status' => ShopOwnerSubscription::AUTO_RENEW_STATUS_ACTION_REQUIRED,
                'renewal_retry_count' => (int) $sourceSubscription->renewal_retry_count + 1,
                'renewal_last_attempt_at' => now(),
                'renewal_next_attempt_at' => now()->addHours(6),
            ]);

            return [
                'success' => false,
                'message' => 'Unable to resolve a premium plan for renewal.',
            ];
        }

        [$renewalSubscription, $payment] = DB::transaction(function () use ($sourceSubscription, $plan) {
            $renewalSubscription = ShopOwnerSubscription::create([
                'shop_owner_id' => $sourceSubscription->shop_owner_id,
                'premium_plan_id' => $plan->id,
                'plan_code' => $plan->plan_code,
                'showroom_slot_limit' => $plan->showroom_slot_limit,
                'status' => 'pending',
                'auto_renew' => true,
                'auto_renew_status' => ShopOwnerSubscription::AUTO_RENEW_STATUS_ACTION_REQUIRED,
                'renewal_of_subscription_id' => $sourceSubscription->id,
                'renewal_due_at' => $sourceSubscription->ends_at,
            ]);

            $paymentType = 'renewal';
            $ledgerKey = ShopOwnerSubscriptionPayment::ledgerKeyFor((int) $renewalSubscription->id, $paymentType);
            $metadata = [
                'type' => 'premium_subscription_renewal',
                'renewal_of_subscription_id' => (string) $sourceSubscription->id,
                'ledger_key' => $ledgerKey,
            ];
            $payment = ShopOwnerSubscriptionPayment::create([
                'shop_owner_id' => $sourceSubscription->shop_owner_id,
                'subscription_id' => $renewalSubscription->id,
                'source_subscription_id' => $sourceSubscription->id,
                'payment_type' => $paymentType,
                'gateway' => 'paymongo',
                'currency' => 'PHP',
                'plan_price' => (float) $plan->price,
                'amount_due' => (float) $plan->price,
                'status' => 'pending',
                'ledger_key' => $ledgerKey,
                'metadata' => $metadata,
            ]);
            $metadata['payment_record_id'] = (string) $payment->id;
            $payment->update(['metadata' => $metadata]);

            return [$renewalSubscription, $payment->fresh()];
        });

        $successUrl = route('shop-owner.premium-success', [
            'subscription_id' => $renewalSubscription->id,
        ]);
        $cancelUrl = route('shop-owner.premium-cancel', [
            'subscription_id' => $renewalSubscription->id,
        ]);

        $description = 'SoleSpace ' . $plan->name . ' renewal – ' . $plan->duration_days . '-day subscription';

        $apiKey = (string) config('services.paymongo.secret_key');

        $paymentMethodTypes = ['card', 'gcash', 'paymaya', 'grab_pay'];

        try {
            $response = Http::timeout(10)->connectTimeout(3)->withHeaders([
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
                            'amount' => (int) ($plan->price * 100),
                            'name' => 'SoleSpace ' . $plan->name . ' Renewal',
                            'description' => $description,
                            'quantity' => 1,
                        ]],
                        'payment_method_types' => $paymentMethodTypes,
                        'metadata' => [
                            'type' => 'premium_subscription_renewal',
                            'subscription_id' => (string) $renewalSubscription->id,
                            'renewal_of_subscription_id' => (string) $sourceSubscription->id,
                            'shop_owner_id' => (string) $sourceSubscription->shop_owner_id,
                            'plan_code' => $plan->plan_code,
                            'payment_record_id' => (string) $payment->id,
                            'ledger_key' => $payment->ledger_key,
                        ],
                    ],
                ],
            ]);
        } catch (\Throwable $exception) {
            $this->markRenewalCheckoutFailed($sourceSubscription, $renewalSubscription, $payment);

            Log::warning('Premium renewal checkout request failed', [
                'source_subscription_id' => $sourceSubscription->id,
                'renewal_subscription_id' => $renewalSubscription->id,
                'shop_owner_id' => $sourceSubscription->shop_owner_id,
                'exception_class' => $exception::class,
            ]);

            return [
                'success' => false,
                'message' => 'Failed to create renewal checkout session.',
            ];
        }

        if ($response->failed()) {
            $this->markRenewalCheckoutFailed($sourceSubscription, $renewalSubscription, $payment);

            Log::warning('Premium renewal checkout creation failed', [
                'source_subscription_id' => $sourceSubscription->id,
                'renewal_subscription_id' => $renewalSubscription->id,
                'shop_owner_id' => $sourceSubscription->shop_owner_id,
                'http_status' => $response->status(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to create renewal checkout session.',
            ];
        }

        $sessionId = $response->json('data.id');
        $checkoutUrl = $response->json('data.attributes.checkout_url');

        if (!$sessionId || !$checkoutUrl) {
            $this->markRenewalCheckoutFailed($sourceSubscription, $renewalSubscription, $payment);

            return [
                'success' => false,
                'message' => 'Incomplete renewal checkout response.',
            ];
        }

        $sourceUpdatePayload = [
            'auto_renew_status' => ShopOwnerSubscription::AUTO_RENEW_STATUS_ACTION_REQUIRED,
            'renewal_retry_count' => 0,
            'renewal_last_attempt_at' => now(),
            'renewal_next_attempt_at' => now()->addHours(12),
            'renewal_checkout_session_id' => $sessionId,
            'renewal_checkout_url' => $checkoutUrl,
            'renewal_checkout_url_expires_at' => now()->addDay(),
        ];

        if (Schema::hasColumn('shop_owner_subscriptions', 'pending_premium_plan_id')) {
            $sourceUpdatePayload['pending_premium_plan_id'] = null;
        }

        if (Schema::hasColumn('shop_owner_subscriptions', 'pending_plan_effective_at')) {
            $sourceUpdatePayload['pending_plan_effective_at'] = null;
        }

        DB::transaction(function () use ($renewalSubscription, $payment, $sourceSubscription, $sessionId, $sourceUpdatePayload): void {
            ShopOwnerSubscriptionPayment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail()
                ->update(['paymongo_session_id' => $sessionId]);

            ShopOwnerSubscription::query()
                ->whereKey($renewalSubscription->id)
                ->lockForUpdate()
                ->firstOrFail()
                ->update(['paymongo_session_id' => $sessionId]);

            ShopOwnerSubscription::query()
                ->whereKey($sourceSubscription->id)
                ->lockForUpdate()
                ->firstOrFail()
                ->update($sourceUpdatePayload);
        });

        try {
            app(NotificationService::class)->sendToShopOwner(
                $sourceSubscription->shop_owner_id,
                NotificationType::PAYMENT_FAILED,
                'Premium renewal requires payment confirmation',
                'Your premium plan renewal is ready. Complete payment to keep virtual showroom access continuous.',
                [
                    'subscription_id' => $sourceSubscription->id,
                    'renewal_subscription_id' => $renewalSubscription->id,
                    'checkout_url' => $checkoutUrl,
                ],
                $checkoutUrl,
                'high'
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to send renewal action-required notification', [
                'source_subscription_id' => $sourceSubscription->id,
                'renewal_subscription_id' => $renewalSubscription->id,
                'exception_class' => $e::class,
            ]);
        }

        return [
            'success' => true,
            'message' => 'Renewal checkout created.',
            'existing' => false,
            'renewal_subscription' => $renewalSubscription,
            'checkout_url' => $checkoutUrl,
            'checkout_session_id' => $sessionId,
        ];
    }

    private function markRenewalCheckoutFailed(
        ShopOwnerSubscription $sourceSubscription,
        ShopOwnerSubscription $renewalSubscription,
        ShopOwnerSubscriptionPayment $payment,
    ): void {
        DB::transaction(function () use ($sourceSubscription, $renewalSubscription, $payment): void {
            $lockedPayment = ShopOwnerSubscriptionPayment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedRenewal = ShopOwnerSubscription::query()
                ->whereKey($renewalSubscription->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedSource = ShopOwnerSubscription::query()
                ->whereKey($sourceSubscription->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPayment->status === 'pending') {
                $lockedPayment->update(['status' => 'failed']);
            }
            if ($lockedRenewal->status === 'pending') {
                $lockedRenewal->update(['status' => 'failed']);
            }

            $lockedSource->update([
                'auto_renew_status' => ShopOwnerSubscription::AUTO_RENEW_STATUS_ACTION_REQUIRED,
                'renewal_retry_count' => (int) $lockedSource->renewal_retry_count + 1,
                'renewal_last_attempt_at' => now(),
                'renewal_next_attempt_at' => now()->addHours(6),
            ]);
        });
    }
}
