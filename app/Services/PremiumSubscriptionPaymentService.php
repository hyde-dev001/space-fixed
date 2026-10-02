<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\ShopOwnerSubscription;
use App\Models\ShopOwnerSubscriptionPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class PremiumSubscriptionPaymentService
{
    public function verifyAndSettleProviderSession(string $sessionId, bool $apply = true): array
    {
        if (trim($sessionId) === '') {
            return ['result' => 'unsafe'];
        }

        $apiKey = (string) config('services.paymongo.secret_key');
        if ($apiKey === '') {
            return ['result' => 'provider_unconfigured'];
        }

        try {
            $response = Http::timeout(10)->connectTimeout(3)->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic '.base64_encode($apiKey.':'),
            ])->get('https://api.paymongo.com/v1/checkout_sessions/'.rawurlencode($sessionId));
        } catch (\Throwable $exception) {
            Log::warning('PayMongo premium session verification failed', [
                'session_id' => $sessionId,
                'exception_class' => $exception::class,
            ]);

            return ['result' => 'provider_error'];
        }

        if (! $response->ok()) {
            Log::warning('PayMongo premium session verification returned an error', [
                'session_id' => $sessionId,
                'http_status' => $response->status(),
            ]);

            return ['result' => 'provider_error'];
        }

        $session = $response->json('data');
        if (! is_array($session) || ($session['id'] ?? null) !== $sessionId) {
            return ['result' => 'unsafe'];
        }

        return $this->settleCheckoutSession($session, $apply);
    }

    /** @param array<string, mixed> $session */
    public function settleCheckoutSession(array $session, bool $apply = true): array
    {
        $sessionId = data_get($session, 'id');
        $attributes = data_get($session, 'attributes', []);
        $attributes = is_array($attributes) ? $attributes : [];
        $metadata = is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : [];
        $attempts = is_array($attributes['payments'] ?? null) ? $attributes['payments'] : [];
        $paidAttempts = array_values(array_filter($attempts, fn ($attempt) =>
            is_array($attempt)
            && strtolower((string) data_get($attempt, 'attributes.status')) === 'paid'
        ));

        if (! is_string($sessionId) || trim($sessionId) === '' || count($paidAttempts) !== 1) {
            return ['result' => count($paidAttempts) > 1 ? 'ambiguous' : 'unpaid'];
        }

        if (isset($attributes['payment_status'])
            && strtolower((string) $attributes['payment_status']) !== 'paid') {
            return ['result' => 'unpaid'];
        }

        $providerPayment = $paidAttempts[0];
        $providerPaymentId = data_get($providerPayment, 'id');
        $rawAmount = data_get($providerPayment, 'attributes.amount');
        $currency = strtoupper((string) data_get($providerPayment, 'attributes.currency', ''));
        if (! is_string($providerPaymentId) || trim($providerPaymentId) === ''
            || ! is_numeric($rawAmount) || floor((float) $rawAmount) !== (float) $rawAmount
            || $currency === '') {
            return ['result' => 'unsafe'];
        }

        $payments = ShopOwnerSubscriptionPayment::query()
            ->where('gateway', 'paymongo')
            ->where('paymongo_session_id', $sessionId)
            ->get();
        if ($payments->count() !== 1) {
            return ['result' => $payments->isEmpty() ? 'not_found' : 'ambiguous'];
        }

        /** @var ShopOwnerSubscriptionPayment $payment */
        $payment = $payments->first();
        $subscription = $payment->subscription;
        $providerAmount = (int) $rawAmount;
        if (! $subscription || ! $this->matchesBinding($sessionId, $metadata, $payment, $subscription)
            || $currency !== strtoupper((string) $payment->currency)
            || $providerAmount !== (int) round((float) $payment->amount_due * 100)
            || (filled($payment->paymongo_payment_id) && $payment->paymongo_payment_id !== $providerPaymentId)
            || (filled($subscription->paymongo_payment_id) && $subscription->paymongo_payment_id !== $providerPaymentId)) {
            Log::warning('Premium payment provider reconciliation failed local verification', [
                'session_id' => $sessionId,
                'payment_record_id' => $payment->id,
            ]);

            return ['result' => 'unsafe', 'payment_id' => $payment->id];
        }

        if ($payment->status === 'paid') {
            return ['result' => 'already_settled', 'payment_id' => $payment->id];
        }
        if (! $apply) {
            return in_array($payment->status, ['pending', 'failed'], true)
                ? ['result' => 'would_settle', 'payment_id' => $payment->id]
                : ['result' => 'unsafe', 'payment_id' => $payment->id];
        }

        $settlement = DB::transaction(function () use (
            $payment,
            $subscription,
            $sessionId,
            $metadata,
            $providerPaymentId,
            $providerAmount,
            $currency,
        ): array {
            $sourceId = $subscription->replaces_subscription_id;
            $source = $sourceId
                ? ShopOwnerSubscription::query()->whereKey($sourceId)->lockForUpdate()->first()
                : null;
            $lockedPayment = ShopOwnerSubscriptionPayment::query()->whereKey($payment->id)->lockForUpdate()->first();
            $lockedSubscription = ShopOwnerSubscription::query()->whereKey($subscription->id)->lockForUpdate()->first();

            if (! $lockedPayment || ! $lockedSubscription
                || ! $this->matchesBinding($sessionId, $metadata, $lockedPayment, $lockedSubscription)
                || ($lockedSubscription->replaces_subscription_id
                    && (int) $lockedSubscription->replaces_subscription_id !== (int) $sourceId)
                || strtoupper((string) $lockedPayment->currency) !== $currency
                || $providerAmount !== (int) round((float) $lockedPayment->amount_due * 100)
                || (filled($lockedPayment->paymongo_payment_id) && $lockedPayment->paymongo_payment_id !== $providerPaymentId)
                || (filled($lockedSubscription->paymongo_payment_id) && $lockedSubscription->paymongo_payment_id !== $providerPaymentId)) {
                return ['result' => 'unsafe', 'payment_id' => $payment->id];
            }

            if ($lockedPayment->status === 'paid') {
                return ['result' => 'already_settled', 'payment_id' => $lockedPayment->id];
            }
            if (! in_array($lockedPayment->status, ['pending', 'failed'], true)) {
                return ['result' => 'unsafe', 'payment_id' => $lockedPayment->id];
            }

            $paidAmount = number_format($providerAmount / 100, 2, '.', '');
            $paidAt = now();
            $reviewReason = null;
            if ($lockedSubscription->status === 'pending' && $lockedSubscription->replaces_subscription_id
                && (! $source || $source->status !== 'active')) {
                $reviewReason = 'upgrade_source_no_longer_active';
            } elseif (! in_array($lockedSubscription->status, ['pending', 'active'], true)) {
                $reviewReason = 'subscription_not_eligible_at_settlement';
            }

            $paymentMetadata = is_array($lockedPayment->metadata) ? $lockedPayment->metadata : [];
            if ($reviewReason !== null) {
                $paymentMetadata['settlement_review_required'] = true;
                $paymentMetadata['settlement_review_reason'] = $reviewReason;
            }
            $lockedPayment->update([
                'paymongo_payment_id' => $providerPaymentId,
                'status' => 'paid',
                'amount_paid' => $paidAmount,
                'paid_at' => $lockedPayment->paid_at ?? $paidAt,
                'metadata' => $paymentMetadata,
            ]);

            $subscriptionUpdate = [
                'paymongo_payment_id' => $providerPaymentId,
                'paid_amount' => $paidAmount,
            ];
            $newlyActivated = false;

            if ($reviewReason !== null) {
                if ($lockedSubscription->status === 'pending') {
                    $subscriptionUpdate['status'] = 'cancelled';
                }
            } elseif ($lockedSubscription->status === 'pending') {
                $lockedSubscription->loadMissing('premiumPlan');
                $durationDays = max(1, (int) ($lockedSubscription->premiumPlan?->duration_days ?? 30));
                $subscriptionUpdate += [
                    'status' => 'active',
                    'starts_at' => $paidAt,
                    'ends_at' => $paidAt->copy()->addDays($durationDays),
                ];
                if (Schema::hasColumn('shop_owner_subscriptions', 'auto_renew')
                    && Schema::hasColumn('shop_owner_subscriptions', 'auto_renew_status')) {
                    $subscriptionUpdate['auto_renew'] = true;
                    $subscriptionUpdate['auto_renew_status'] = ShopOwnerSubscription::AUTO_RENEW_STATUS_ENABLED;
                }
                $newlyActivated = true;
            }

            $lockedSubscription->update($subscriptionUpdate);

            if ($newlyActivated && $source) {
                $sourceUpdate = [
                    'status' => 'cancelled',
                    'ends_at' => $paidAt,
                ];
                if (Schema::hasColumn('shop_owner_subscriptions', 'auto_renew')
                    && Schema::hasColumn('shop_owner_subscriptions', 'auto_renew_status')) {
                    $sourceUpdate['auto_renew'] = false;
                    $sourceUpdate['auto_renew_status'] = ShopOwnerSubscription::AUTO_RENEW_STATUS_DISABLED;
                }
                if (Schema::hasColumn('shop_owner_subscriptions', 'pending_premium_plan_id')) {
                    $sourceUpdate['pending_premium_plan_id'] = null;
                }
                if (Schema::hasColumn('shop_owner_subscriptions', 'pending_plan_effective_at')) {
                    $sourceUpdate['pending_plan_effective_at'] = null;
                }
                $source->update($sourceUpdate);
            }

            activity()->performedOn($lockedSubscription)->withProperties([
                'subscription_id' => $lockedSubscription->id,
                'shop_owner_id' => $lockedSubscription->shop_owner_id,
                'payment_record_id' => $lockedPayment->id,
                'payment_id' => $providerPaymentId,
                'session_id' => $sessionId,
                'paid_amount' => $paidAmount,
                'settlement_review_required' => $reviewReason !== null,
            ])->log($newlyActivated ? 'Premium subscription activated: '.$lockedSubscription->plan_code : 'Premium subscription payment recorded');

            return [
                'result' => $reviewReason !== null ? 'paid_requires_review' : 'settled',
                'payment_id' => $lockedPayment->id,
                'subscription_id' => $lockedSubscription->id,
                'newly_activated' => $newlyActivated,
                'subscription' => $lockedSubscription->fresh(),
            ];
        });

        if (($settlement['newly_activated'] ?? false) && $settlement['subscription']) {
            $this->notifyOwner($settlement['subscription']);
        }

        unset($settlement['newly_activated'], $settlement['subscription']);

        return $settlement;
    }

    private function matchesBinding(
        string $sessionId,
        array $metadata,
        ShopOwnerSubscriptionPayment $payment,
        ShopOwnerSubscription $subscription,
    ): bool {
        $expectedType = match ($payment->payment_type) {
            'new_subscription' => 'premium_subscription',
            'upgrade' => 'premium_subscription_upgrade',
            'renewal' => 'premium_subscription_renewal',
            default => null,
        };

        return $payment->gateway === 'paymongo'
            && $expectedType !== null
            && ($metadata['type'] ?? null) === $expectedType
            && $payment->paymongo_session_id === $sessionId
            && $subscription->paymongo_session_id === $sessionId
            && (int) $payment->subscription_id === (int) $subscription->id
            && (int) $payment->shop_owner_id === (int) $subscription->shop_owner_id
            && isset($metadata['payment_record_id'])
            && is_scalar($metadata['payment_record_id'])
            && (string) $metadata['payment_record_id'] === (string) $payment->id
            && isset($metadata['subscription_id'])
            && is_scalar($metadata['subscription_id'])
            && (string) $metadata['subscription_id'] === (string) $subscription->id
            && isset($metadata['shop_owner_id'])
            && is_scalar($metadata['shop_owner_id'])
            && (string) $metadata['shop_owner_id'] === (string) $subscription->shop_owner_id
            && ($metadata['plan_code'] ?? null) === $subscription->plan_code
            && ($metadata['ledger_key'] ?? null) === $payment->ledger_key
            && ($payment->payment_type !== 'upgrade'
                || ($subscription->replaces_subscription_id
                    && (int) $payment->source_subscription_id === (int) $subscription->replaces_subscription_id
                    && isset($metadata['source_subscription_id'])
                    && (int) $metadata['source_subscription_id'] === (int) $subscription->replaces_subscription_id))
            && ($payment->payment_type !== 'renewal'
                || ($subscription->renewal_of_subscription_id
                    && (int) $payment->source_subscription_id === (int) $subscription->renewal_of_subscription_id
                    && isset($metadata['renewal_of_subscription_id'])
                    && (int) $metadata['renewal_of_subscription_id'] === (int) $subscription->renewal_of_subscription_id));
    }

    private function notifyOwner(ShopOwnerSubscription $subscription): void
    {
        try {
            $planLabel = ucfirst($subscription->plan_code);
            app(NotificationService::class)->sendToShopOwner(
                $subscription->shop_owner_id,
                NotificationType::PAYMENT_RECEIVED,
                'Premium Subscription Activated',
                "Your SoleSpace {$planLabel} subscription is now active and will continue until you cancel it.",
                [
                    'subscription_id' => $subscription->id,
                    'plan_code' => $subscription->plan_code,
                    'ends_at' => $subscription->ends_at?->toISOString(),
                ],
                rtrim((string) config('app.url'), '/').'/shop-owner/premium/benefits',
                'high',
            );
        } catch (\Throwable $exception) {
            Log::error('Failed to send premium activation notification', [
                'subscription_id' => $subscription->id,
                'exception_class' => $exception::class,
            ]);
        }
    }
}
