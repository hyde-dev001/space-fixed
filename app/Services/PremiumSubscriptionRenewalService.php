<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\PremiumPlan;
use App\Models\ShopOwnerSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PremiumSubscriptionRenewalService
{
    /**
     * Create or reuse a renewal checkout session for a due premium subscription.
     */
    public function createRenewalCheckout(ShopOwnerSubscription $sourceSubscription): array
    {
        $sourceSubscription->loadMissing('premiumPlan', 'shopOwner');

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

        $renewalSubscription = DB::transaction(function () use ($sourceSubscription, $plan) {
            return ShopOwnerSubscription::create([
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
        });

        $successUrl = route('shop-owner.premium-success', [
            'subscription_id' => $renewalSubscription->id,
        ]);
        $cancelUrl = route('shop-owner.premium-cancel', [
            'subscription_id' => $renewalSubscription->id,
        ]);

        $description = 'SoleSpace ' . $plan->name . ' renewal – ' . $plan->duration_days . '-day subscription';

        $apiKey = (string) config('services.paymongo.secret_key');

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
                        'amount' => (int) ($plan->price * 100),
                        'name' => 'SoleSpace ' . $plan->name . ' Renewal',
                        'description' => $description,
                        'quantity' => 1,
                    ]],
                    'payment_method_types' => ['card', 'gcash', 'paymaya', 'grab_pay'],
                    'metadata' => [
                        'type' => 'premium_subscription_renewal',
                        'subscription_id' => (string) $renewalSubscription->id,
                        'renewal_of_subscription_id' => (string) $sourceSubscription->id,
                        'shop_owner_id' => (string) $sourceSubscription->shop_owner_id,
                        'plan_code' => $plan->plan_code,
                    ],
                ],
            ],
        ]);

        if ($response->failed()) {
            $renewalSubscription->update(['status' => 'failed']);
            $sourceSubscription->update([
                'auto_renew_status' => ShopOwnerSubscription::AUTO_RENEW_STATUS_ACTION_REQUIRED,
                'renewal_retry_count' => (int) $sourceSubscription->renewal_retry_count + 1,
                'renewal_last_attempt_at' => now(),
                'renewal_next_attempt_at' => now()->addHours(6),
            ]);

            Log::warning('Premium renewal checkout creation failed', [
                'source_subscription_id' => $sourceSubscription->id,
                'renewal_subscription_id' => $renewalSubscription->id,
                'shop_owner_id' => $sourceSubscription->shop_owner_id,
                'http_status' => $response->status(),
                'body' => $response->json(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to create renewal checkout session.',
            ];
        }

        $sessionId = $response->json('data.id');
        $checkoutUrl = $response->json('data.attributes.checkout_url');

        if (!$sessionId || !$checkoutUrl) {
            $renewalSubscription->update(['status' => 'failed']);
            $sourceSubscription->update([
                'auto_renew_status' => ShopOwnerSubscription::AUTO_RENEW_STATUS_ACTION_REQUIRED,
                'renewal_retry_count' => (int) $sourceSubscription->renewal_retry_count + 1,
                'renewal_last_attempt_at' => now(),
                'renewal_next_attempt_at' => now()->addHours(6),
            ]);

            return [
                'success' => false,
                'message' => 'Incomplete renewal checkout response.',
            ];
        }

        $renewalSubscription->update([
            'paymongo_session_id' => $sessionId,
        ]);

        $sourceSubscription->update([
            'auto_renew_status' => ShopOwnerSubscription::AUTO_RENEW_STATUS_ACTION_REQUIRED,
            'renewal_retry_count' => 0,
            'renewal_last_attempt_at' => now(),
            'renewal_next_attempt_at' => now()->addHours(12),
            'renewal_checkout_session_id' => $sessionId,
            'renewal_checkout_url' => $checkoutUrl,
            'renewal_checkout_url_expires_at' => now()->addDay(),
        ]);

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
                'error' => $e->getMessage(),
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
}
