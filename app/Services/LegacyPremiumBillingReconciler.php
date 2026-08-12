<?php

namespace App\Services;

use App\Models\ShopOwnerSubscription;
use App\Models\ShopOwnerSubscriptionPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class LegacyPremiumBillingReconciler
{
    /**
     * @return array{result: string, payment_id: int|null, resulting_status: string}
     */
    public function reconcile(ShopOwnerSubscription $subscription, bool $apply): array
    {
        $subscription->loadMissing('premiumPlan');

        $payment = ShopOwnerSubscriptionPayment::query()
            ->where('subscription_id', $subscription->id)
            ->orderByDesc('id')
            ->first();

        if ($this->hasConflictingProviderReference($subscription)) {
            return [
                'result' => 'ambiguous',
                'payment_id' => $payment?->id,
                'resulting_status' => (string) $subscription->status,
            ];
        }

        $targetStatus = $this->legacyTargetStatus($subscription);
        $canReconcilePayment = $this->hasReliablePaymentEvidence($subscription, $payment);

        if ($payment || !$canReconcilePayment) {
            if ($targetStatus === null) {
                return [
                    'result' => 'ambiguous',
                    'payment_id' => $payment?->id,
                    'resulting_status' => (string) $subscription->status,
                ];
            }

            if (!$apply || $subscription->status !== 'deactivated') {
                return [
                    'result' => $subscription->status === 'deactivated' ? 'would_update' : 'unchanged',
                    'payment_id' => $payment?->id,
                    'resulting_status' => (string) $subscription->status,
                ];
            }

            return $this->applyStatusOnly($subscription, $targetStatus, $payment?->id);
        }

        if (!$apply) {
            return [
                'result' => 'would_reconcile',
                'payment_id' => null,
                'resulting_status' => $targetStatus ?? (string) $subscription->status,
            ];
        }

        return DB::transaction(function () use ($subscription, $targetStatus): array {
            $locked = ShopOwnerSubscription::query()
                ->whereKey($subscription->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = ShopOwnerSubscriptionPayment::query()
                ->where('subscription_id', $locked->id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->applyLegacyStatus($locked, $targetStatus, $existing->id);

                return [
                    'result' => 'unchanged',
                    'payment_id' => $existing->id,
                    'resulting_status' => (string) $locked->status,
                ];
            }

            $paymentType = 'premium_subscription';
            $ledgerKey = ShopOwnerSubscriptionPayment::ledgerKeyFor((int) $locked->id, $paymentType);
            $payment = ShopOwnerSubscriptionPayment::create([
                'shop_owner_id' => $locked->shop_owner_id,
                'subscription_id' => $locked->id,
                'payment_type' => $paymentType,
                'gateway' => 'paymongo',
                'currency' => 'PHP',
                'ledger_key' => $ledgerKey,
                'paymongo_session_id' => $locked->paymongo_session_id,
                'paymongo_payment_id' => $locked->paymongo_payment_id,
                'plan_price' => $locked->paid_amount,
                'amount_due' => $locked->paid_amount,
                'amount_paid' => $locked->paid_amount,
                'status' => 'paid',
                'metadata' => [
                    'reconciled_legacy' => true,
                    'source_subscription_id' => $locked->id,
                ],
                'paid_at' => $locked->starts_at ?? $locked->created_at,
            ]);

            activity('billing')
                ->performedOn($payment)
                ->withProperties([
                    'event' => 'premium_payment_reconciled',
                    'subscription_id' => (int) $locked->id,
                    'shop_owner_id' => (int) $locked->shop_owner_id,
                    'payment_id' => (int) $payment->id,
                    'amount' => (string) $payment->amount_paid,
                    'currency' => (string) $payment->currency,
                    'source' => 'premium-billing:reconcile-legacy',
                ])
                ->log('premium_payment_reconciled');

            $this->applyLegacyStatus($locked, $targetStatus, $payment->id);

            return [
                'result' => 'reconciled',
                'payment_id' => $payment->id,
                'resulting_status' => (string) $locked->status,
            ];
        });
    }

    private function hasReliablePaymentEvidence(
        ShopOwnerSubscription $subscription,
        ?ShopOwnerSubscriptionPayment $payment,
    ): bool {
        return !$payment
            && filled($subscription->paymongo_payment_id)
            && is_numeric($subscription->paid_amount)
            && (float) $subscription->paid_amount > 0;
    }

    private function hasConflictingProviderReference(ShopOwnerSubscription $subscription): bool
    {
        $providerPaymentId = trim((string) $subscription->paymongo_payment_id);
        if ($providerPaymentId !== '') {
            $paymentConflict = ShopOwnerSubscriptionPayment::query()
                ->where('paymongo_payment_id', $providerPaymentId)
                ->where(function ($query) use ($subscription): void {
                    $query
                        ->whereNull('subscription_id')
                        ->orWhere('subscription_id', '!=', $subscription->id);
                })
                ->exists();
            $subscriptionConflict = ShopOwnerSubscription::query()
                ->where('paymongo_payment_id', $providerPaymentId)
                ->where('id', '!=', $subscription->id)
                ->exists();

            if ($paymentConflict || $subscriptionConflict) {
                return true;
            }
        }

        $sessionId = trim((string) $subscription->paymongo_session_id);
        if ($sessionId === '') {
            return false;
        }

        return ShopOwnerSubscriptionPayment::query()
            ->where('paymongo_session_id', $sessionId)
            ->where(function ($query) use ($subscription): void {
                $query
                    ->whereNull('subscription_id')
                    ->orWhere('subscription_id', '!=', $subscription->id);
            })
            ->exists()
            || ShopOwnerSubscription::query()
                ->where('paymongo_session_id', $sessionId)
                ->where('id', '!=', $subscription->id)
                ->exists();
    }

    private function legacyTargetStatus(ShopOwnerSubscription $subscription): ?string
    {
        if ($subscription->status !== 'deactivated' || !$subscription->ends_at) {
            return null;
        }

        return $subscription->ends_at->greaterThan(now()) ? 'cancelled' : 'expired';
    }

    /**
     * @return array{result: string, payment_id: int|null, resulting_status: string}
     */
    private function applyStatusOnly(
        ShopOwnerSubscription $subscription,
        string $targetStatus,
        ?int $paymentId,
    ): array {
        DB::transaction(function () use ($subscription, $targetStatus, $paymentId): void {
            $locked = ShopOwnerSubscription::query()
                ->whereKey($subscription->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->applyLegacyStatus($locked, $targetStatus, $paymentId);
        });

        return [
            'result' => 'reconciled',
            'payment_id' => $paymentId,
            'resulting_status' => $targetStatus,
        ];
    }

    private function applyLegacyStatus(
        ShopOwnerSubscription $subscription,
        ?string $targetStatus,
        ?int $paymentId,
    ): void {
        if ($targetStatus === null || $subscription->status !== 'deactivated') {
            return;
        }

        $updates = ['status' => $targetStatus];
        if (
            Schema::hasColumn('shop_owner_subscriptions', 'auto_renew')
            && Schema::hasColumn('shop_owner_subscriptions', 'auto_renew_status')
        ) {
            $updates['auto_renew'] = false;
            $updates['auto_renew_status'] = ShopOwnerSubscription::AUTO_RENEW_STATUS_DISABLED;
        }

        $priorStatus = (string) $subscription->status;
        $subscription->update($updates);

        activity('billing')
            ->performedOn($subscription)
            ->withProperties([
                'event' => 'legacy_subscription_reconciled',
                'subscription_id' => (int) $subscription->id,
                'shop_owner_id' => (int) $subscription->shop_owner_id,
                'payment_id' => $paymentId,
                'prior_status' => $priorStatus,
                'resulting_status' => $targetStatus,
                'source' => 'premium-billing:reconcile-legacy',
            ])
            ->log('legacy_subscription_reconciled');
    }
}
