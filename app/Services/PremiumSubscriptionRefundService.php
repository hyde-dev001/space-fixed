<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ShopOwnerSubscription;
use App\Models\ShopOwnerSubscriptionPayment;
use App\Models\ShopOwnerSubscriptionRefund;
use App\Models\SuperAdmin;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class PremiumSubscriptionRefundService
{
    public function __construct(
        private readonly PaymongoSubscriptionRefundGateway $gateway,
        private readonly PrivilegedAudit $audit,
    ) {
    }

    /**
     * @return array{refund: ShopOwnerSubscriptionRefund, outcome: string, replayed: bool}
     */
    public function refund(
        ShopOwnerSubscriptionPayment $payment,
        SuperAdmin $actor,
        Request $request,
        string $businessReason,
        string $providerReason,
    ): array {
        $local = $this->prepareAttempt($payment, $actor, $request, $businessReason, $providerReason);
        $attempt = $local['attempt'];

        if ($local['replayed']) {
            return [
                'refund' => $attempt,
                'outcome' => (string) $attempt->status,
                'replayed' => true,
            ];
        }

        $providerPayment = $this->gateway->retrievePayment($local['payment_id']);
        if (! ($providerPayment['success'] ?? false)) {
            return $this->finalizeProviderResult(
                attempt: $attempt,
                result: [
                    'outcome' => ($providerPayment['failure_code'] ?? '') === 'provider_timeout' ? 'unknown' : 'failed',
                    'refund_id' => null,
                    'amount' => null,
                    'currency' => null,
                    'payment_id' => null,
                    'failure_code' => $providerPayment['failure_code'] ?? 'provider_payment_lookup_failed',
                ],
                request: $request,
                actor: $actor,
            );
        }

        if (
            ($providerPayment['status'] ?? '') !== 'paid'
            || (int) ($providerPayment['amount'] ?? 0) !== $local['amount_in_centavos']
            || strtoupper((string) ($providerPayment['currency'] ?? '')) !== $local['currency']
        ) {
            return $this->finalizeProviderResult(
                attempt: $attempt,
                result: [
                    'outcome' => 'failed',
                    'refund_id' => null,
                    'amount' => is_numeric($providerPayment['amount'] ?? null) ? (int) $providerPayment['amount'] : null,
                    'currency' => isset($providerPayment['currency']) ? strtoupper((string) $providerPayment['currency']) : null,
                    'payment_id' => $providerPayment['payment_id'] ?? null,
                    'failure_code' => 'provider_payment_mismatch',
                ],
                request: $request,
                actor: $actor,
            );
        }

        $providerRefunds = $this->gateway->listRefunds($local['payment_id']);
        if (! ($providerRefunds['success'] ?? false)) {
            return $this->finalizeProviderResult(
                attempt: $attempt,
                result: [
                    'outcome' => ($providerRefunds['failure_code'] ?? '') === 'provider_timeout' ? 'unknown' : 'failed',
                    'refund_id' => null,
                    'amount' => null,
                    'currency' => null,
                    'payment_id' => $local['payment_id'],
                    'failure_code' => $providerRefunds['failure_code'] ?? 'provider_refund_lookup_failed',
                ],
                request: $request,
                actor: $actor,
            );
        }

        foreach ($providerRefunds['refunds'] as $providerRefund) {
            if (($providerRefund['status'] ?? '') === 'failed') {
                continue;
            }

            $providerRefundPaymentId = $providerRefund['payment_id'] ?? null;
            if ($providerRefundPaymentId !== null && $providerRefundPaymentId !== $local['payment_id']) {
                continue;
            }

            $providerRefundAmount = $providerRefund['amount'] ?? null;
            $providerRefundCurrency = $providerRefund['currency'] ?? null;
            $hasMatchingPayment = $providerRefundPaymentId === $local['payment_id'];
            $hasMatchingAmount = is_numeric($providerRefundAmount)
                && (int) $providerRefundAmount === $local['amount_in_centavos'];
            $hasMatchingCurrency = strtoupper((string) $providerRefundCurrency) === $local['currency'];

            if (! $hasMatchingPayment || ! $hasMatchingAmount || ! $hasMatchingCurrency) {
                return $this->finalizeProviderResult(
                    attempt: $attempt,
                    result: [
                        'outcome' => 'failed',
                        'refund_id' => $providerRefund['id'] ?? null,
                        'amount' => is_numeric($providerRefundAmount) ? (int) $providerRefundAmount : null,
                        'currency' => $providerRefundCurrency,
                        'payment_id' => $providerRefundPaymentId,
                        'failure_code' => 'provider_existing_refund_mismatch',
                    ],
                    request: $request,
                    actor: $actor,
                );
            }

            return $this->finalizeProviderResult(
                attempt: $attempt,
                result: [
                    'outcome' => $this->providerOutcome((string) ($providerRefund['status'] ?? '')),
                    'refund_id' => $providerRefund['id'] ?? null,
                    'amount' => (int) $providerRefundAmount,
                    'currency' => $providerRefundCurrency,
                    'payment_id' => $providerRefundPaymentId,
                    'failure_code' => null,
                ],
                request: $request,
                actor: $actor,
            );
        }

        $providerResult = $this->gateway->createRefund(
            paymentId: $local['payment_id'],
            amountInCentavos: $local['amount_in_centavos'],
            providerReason: $providerReason,
            localReference: $attempt->local_reference,
        );

        return $this->finalizeProviderResult(
            attempt: $attempt,
            result: $providerResult,
            request: $request,
            actor: $actor,
        );
    }

    /**
     * Apply a trusted provider webhook to an existing local attempt.
     *
     * @param array{outcome: string, refund_id: ?string, amount: ?int, currency: ?string, payment_id: ?string, failure_code: ?string} $result
     * @return array{refund: ShopOwnerSubscriptionRefund, outcome: string, replayed: bool}
     */
    public function applyProviderWebhook(
        ShopOwnerSubscriptionRefund $attempt,
        array $result,
        Request $request,
    ): array {
        return $this->finalizeProviderResult(
            attempt: $attempt,
            result: $result,
            request: $request,
            actor: null,
            source: 'provider_webhook',
        );
    }

    /**
     * Reconcile an existing attempt without creating another provider refund.
     *
     * @return array{refund: ShopOwnerSubscriptionRefund, outcome: string, replayed: bool}
     */
    public function reconcile(ShopOwnerSubscriptionRefund $attempt, Request $request): array
    {
        $attempt->loadMissing('payment');
        $paymentId = (string) $attempt->payment?->paymongo_payment_id;

        if ($paymentId === '') {
            return $this->finalizeProviderResult(
                attempt: $attempt,
                result: [
                    'outcome' => 'failed',
                    'refund_id' => $attempt->provider_refund_id,
                    'amount' => null,
                    'currency' => null,
                    'payment_id' => null,
                    'failure_code' => 'provider_payment_missing',
                ],
                request: $request,
                actor: null,
                source: 'provider_reconciliation',
            );
        }

        $providerRefund = null;
        if (filled($attempt->provider_refund_id)) {
            $lookup = $this->gateway->retrieveRefund((string) $attempt->provider_refund_id);
            if ($lookup['success'] ?? false) {
                $providerRefund = $lookup['refund'];
            } else {
                return $this->finalizeProviderResult(
                    attempt: $attempt,
                    result: [
                        'outcome' => ($lookup['failure_code'] ?? '') === 'provider_timeout' ? 'unknown' : 'failed',
                        'refund_id' => $attempt->provider_refund_id,
                        'amount' => null,
                        'currency' => null,
                        'payment_id' => $paymentId,
                        'failure_code' => $lookup['failure_code'] ?? 'provider_refund_lookup_failed',
                    ],
                    request: $request,
                    actor: null,
                    source: 'provider_reconciliation',
                );
            }
        } else {
            $lookup = $this->gateway->listRefunds($paymentId);
            if (! ($lookup['success'] ?? false)) {
                return $this->finalizeProviderResult(
                    attempt: $attempt,
                    result: [
                        'outcome' => ($lookup['failure_code'] ?? '') === 'provider_timeout' ? 'unknown' : 'failed',
                        'refund_id' => null,
                        'amount' => null,
                        'currency' => null,
                        'payment_id' => $paymentId,
                        'failure_code' => $lookup['failure_code'] ?? 'provider_refund_lookup_failed',
                    ],
                    request: $request,
                    actor: null,
                    source: 'provider_reconciliation',
                );
            }

            $matching = array_values(array_filter(
                $lookup['refunds'],
                static fn (array $refund): bool => ($refund['payment_id'] ?? null) === $paymentId,
            ));
            if (count($matching) === 1) {
                $providerRefund = $matching[0];
            }
        }

        if ($providerRefund === null) {
            return $this->finalizeProviderResult(
                attempt: $attempt,
                result: [
                    'outcome' => 'unknown',
                    'refund_id' => $attempt->provider_refund_id,
                    'amount' => null,
                    'currency' => null,
                    'payment_id' => $paymentId,
                    'failure_code' => 'provider_refund_not_found',
                ],
                request: $request,
                actor: null,
                source: 'provider_reconciliation',
            );
        }

        return $this->finalizeProviderResult(
            attempt: $attempt,
            result: [
                'outcome' => $this->providerOutcome((string) ($providerRefund['status'] ?? '')),
                'refund_id' => $providerRefund['id'] ?? null,
                'amount' => $providerRefund['amount'] ?? null,
                'currency' => $providerRefund['currency'] ?? null,
                'payment_id' => $providerRefund['payment_id'] ?? $paymentId,
                'failure_code' => null,
            ],
            request: $request,
            actor: null,
            source: 'provider_reconciliation',
        );
    }

    /**
     * @param array{outcome: string, refund_id: ?string, amount: ?int, currency: ?string, payment_id: ?string, failure_code: ?string} $result
     * @return array{refund: ShopOwnerSubscriptionRefund, outcome: string, replayed: bool}
     */
    private function finalizeProviderResult(
        ShopOwnerSubscriptionRefund $attempt,
        array $result,
        Request $request,
        ?SuperAdmin $actor,
        string $source = 'http',
    ): array {
        return DB::transaction(function () use ($attempt, $result, $request, $actor, $source): array {
                $targetAttempt = ShopOwnerSubscriptionRefund::query()
                    ->whereKey($attempt->getKey())
                    ->firstOrFail();
                $lockedPayment = ShopOwnerSubscriptionPayment::query()
                    ->whereKey($targetAttempt->payment_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $lockedSubscription = ShopOwnerSubscription::query()
                    ->whereKey($lockedPayment->subscription_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $lockedAttempt = ShopOwnerSubscriptionRefund::query()
                    ->whereKey($targetAttempt->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (in_array($lockedAttempt->status, ['succeeded', 'failed'], true)) {
                    return [
                        'refund' => $lockedAttempt->fresh(),
                        'outcome' => (string) $lockedAttempt->status,
                        'replayed' => true,
                    ];
                }

                if ((int) $lockedPayment->subscription_id !== (int) $lockedSubscription->id) {
                    throw new DomainException('Subscription refund binding is invalid.');
                }

                $outcome = (string) ($result['outcome'] ?? 'unknown');
                if (! in_array($outcome, ['succeeded', 'processing', 'failed', 'unknown'], true)) {
                    $outcome = 'unknown';
                }

                $providerPaymentId = $result['payment_id'] ?? null;
                $providerAmount = $result['amount'] ?? null;
                $providerCurrency = strtoupper((string) ($result['currency'] ?? ''));
                $providerRefundId = $result['refund_id'] ?? null;
                $requiresRefundBinding = in_array($outcome, ['succeeded', 'processing'], true);

                if (
                    ($requiresRefundBinding && (! is_string($providerRefundId) || trim($providerRefundId) === ''))
                    || ($requiresRefundBinding && (! is_string($providerPaymentId) || trim($providerPaymentId) === ''))
                    || ($requiresRefundBinding && ! is_numeric($providerAmount))
                    || ($requiresRefundBinding && $providerCurrency === '')
                    || ($providerPaymentId !== null && $providerPaymentId !== (string) $lockedPayment->paymongo_payment_id)
                    || ($providerAmount !== null && (int) $providerAmount !== (int) round(((float) $lockedAttempt->amount) * 100))
                    || ($providerCurrency !== '' && $providerCurrency !== strtoupper((string) $lockedAttempt->currency))
                ) {
                    $outcome = 'failed';
                    $result['failure_code'] = 'provider_response_mismatch';
                }

                $status = $outcome === 'processing' ? 'processing' : $outcome;
                $lockedAttempt->forceFill([
                    'status' => $status,
                    'provider_refund_id' => $providerRefundId,
                    'failure_code' => $result['failure_code'] ?? null,
                    'finalized_at' => now(),
                    'reconciled_at' => $source === 'http' ? $lockedAttempt->reconciled_at : now(),
                ])->save();

                if ($status === 'succeeded') {
                    $subscriptionUpdate = [
                        'status' => 'cancelled',
                        'ends_at' => now(),
                        'auto_renew' => false,
                        'auto_renew_status' => ShopOwnerSubscription::AUTO_RENEW_STATUS_DISABLED,
                    ];

                    if (Schema::hasColumn('shop_owner_subscriptions', 'pending_premium_plan_id')) {
                        $subscriptionUpdate['pending_premium_plan_id'] = null;
                    }
                    if (Schema::hasColumn('shop_owner_subscriptions', 'pending_plan_effective_at')) {
                        $subscriptionUpdate['pending_plan_effective_at'] = null;
                    }

                    $lockedSubscription->update($subscriptionUpdate);
                }

                if ($source === 'provider_webhook') {
                    $this->audit->premiumSubscriptionRefundWebhookUpdated(
                        request: $request,
                        refund: $lockedAttempt,
                        subscription: $lockedSubscription,
                        outcome: $status,
                    );
                } elseif ($actor instanceof SuperAdmin) {
                    $this->auditOutcome($request, $actor, $lockedAttempt, $lockedSubscription, $status);
                } elseif ($source === 'provider_reconciliation') {
                    $this->audit->premiumSubscriptionRefundReconciled(
                        request: $request,
                        refund: $lockedAttempt,
                        subscription: $lockedSubscription,
                        outcome: $status,
                    );
                }

                return [
                    'refund' => $lockedAttempt->fresh(),
                    'outcome' => $status,
                    'replayed' => false,
                ];
        });
    }

    /** @return array{attempt: ShopOwnerSubscriptionRefund, replayed: bool, payment_id: string, amount_in_centavos: int, currency: string} */
    private function prepareAttempt(
        ShopOwnerSubscriptionPayment $payment,
        SuperAdmin $actor,
        Request $request,
        string $businessReason,
        string $providerReason,
    ): array {
        return DB::transaction(function () use ($payment, $actor, $request, $businessReason, $providerReason): array {
            $lockedPayment = ShopOwnerSubscriptionPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $lockedSubscription = ShopOwnerSubscription::query()
                ->whereKey($lockedPayment->subscription_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertLocalEligibility($lockedPayment, $lockedSubscription);
            $existing = $this->activeAttempt($lockedPayment);

            if ($existing instanceof ShopOwnerSubscriptionRefund) {
                if ($this->sameRequest($existing, $businessReason, $providerReason)) {
                    return [
                        'attempt' => $existing->fresh(),
                        'replayed' => true,
                        'payment_id' => (string) $lockedPayment->paymongo_payment_id,
                        'amount_in_centavos' => (int) round(((float) $lockedPayment->amount_paid) * 100),
                        'currency' => strtoupper((string) $lockedPayment->currency),
                    ];
                }

                throw new DomainException('Another subscription refund attempt is already unresolved.');
            }

            $attempt = ShopOwnerSubscriptionRefund::query()->create([
                'payment_id' => $lockedPayment->id,
                'subscription_id' => $lockedSubscription->id,
                'actor_id' => $actor->id,
                'local_reference' => (string) Str::uuid(),
                'amount' => $lockedPayment->amount_paid,
                'currency' => strtoupper((string) $lockedPayment->currency),
                'business_reason' => trim($businessReason),
                'provider_reason' => trim($providerReason),
                'status' => 'pending',
                'initiated_at' => now(),
            ]);

            $this->audit->premiumSubscriptionRefundInitiated(
                request: $request,
                actor: $actor,
                refund: $attempt,
                payment: $lockedPayment,
                subscription: $lockedSubscription,
            );

            return [
                'attempt' => $attempt,
                'replayed' => false,
                'payment_id' => (string) $lockedPayment->paymongo_payment_id,
                'amount_in_centavos' => (int) round(((float) $lockedPayment->amount_paid) * 100),
                'currency' => strtoupper((string) $lockedPayment->currency),
            ];
        });
    }

    private function assertLocalEligibility(
        ShopOwnerSubscriptionPayment $payment,
        ShopOwnerSubscription $subscription,
    ): void {
        if ((int) $payment->shop_owner_id !== (int) $subscription->shop_owner_id) {
            throw new DomainException('Payment and subscription ownership do not match.');
        }

        if (strtolower((string) $payment->gateway) !== 'paymongo') {
            throw new DomainException('Only PayMongo subscription payments can be refunded.');
        }

        if ((string) $payment->status !== 'paid' || ! filled($payment->paymongo_payment_id)) {
            throw new DomainException('Only a paid PayMongo payment can be refunded.');
        }

        if ((float) $payment->amount_paid <= 0 || strtoupper((string) $payment->currency) !== 'PHP') {
            throw new DomainException('The payment is not eligible for a full refund.');
        }

        if (! in_array((string) $subscription->status, ['active', 'cancelled'], true)) {
            throw new DomainException('The subscription is not eligible for a refund.');
        }

        $hasPendingChild = ShopOwnerSubscription::query()
            ->where(function ($query) use ($subscription): void {
                $query
                    ->where('renewal_of_subscription_id', $subscription->id)
                    ->orWhere('replaces_subscription_id', $subscription->id);
            })
            ->where('status', 'pending')
            ->exists();

        if ($hasPendingChild) {
            throw new DomainException('A pending replacement or renewal must be resolved first.');
        }
    }

    private function activeAttempt(ShopOwnerSubscriptionPayment $payment): ?ShopOwnerSubscriptionRefund
    {
        return $payment->refunds()
            ->whereIn('status', ['pending', 'processing', 'unknown', 'succeeded'])
            ->latest('id')
            ->first();
    }

    private function sameRequest(
        ShopOwnerSubscriptionRefund $refund,
        string $businessReason,
        string $providerReason,
    ): bool {
        return (string) $refund->business_reason === trim($businessReason)
            && (string) $refund->provider_reason === trim($providerReason);
    }

    private function providerOutcome(string $status): string
    {
        return match (strtolower($status)) {
            'succeeded', 'completed', 'paid' => 'succeeded',
            'pending', 'processing' => 'processing',
            'failed', 'canceled', 'cancelled' => 'failed',
            default => 'unknown',
        };
    }

    private function auditOutcome(
        Request $request,
        SuperAdmin $actor,
        ShopOwnerSubscriptionRefund $refund,
        ShopOwnerSubscription $subscription,
        string $status,
    ): void {
        if ($status === 'succeeded') {
            $this->audit->premiumSubscriptionRefundSucceeded($request, $actor, $refund, $subscription);
        } elseif ($status === 'processing') {
            $this->audit->premiumSubscriptionRefundProcessing($request, $actor, $refund, $subscription);
        } elseif ($status === 'unknown') {
            $this->audit->premiumSubscriptionRefundUnknown($request, $actor, $refund, $subscription);
        } else {
            $this->audit->premiumSubscriptionRefundFailed($request, $actor, $refund, $subscription);
        }
    }
}
