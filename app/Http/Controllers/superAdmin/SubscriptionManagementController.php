<?php

declare(strict_types=1);

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Controller;
use App\Models\PremiumPlan;
use App\Models\ShopOwnerSubscription;
use App\Models\ShopOwnerSubscriptionPayment;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

final class SubscriptionManagementController extends Controller
{
    public function index(): Response
    {
        $now = \Carbon\Carbon::now();
        $hasCancellationColumns = Schema::hasColumn('shop_owner_subscriptions', 'cancellation_reason')
            && Schema::hasColumn('shop_owner_subscriptions', 'cancellation_notes');
        $hasPlanChangeColumns = Schema::hasColumn('shop_owner_subscriptions', 'replaces_subscription_id')
            && Schema::hasColumn('shop_owner_subscriptions', 'payment_method');

        $subscriptionModels = ShopOwnerSubscription::query()
            ->with(['shopOwner', 'premiumPlan', 'payments.refunds'])
            ->orderBy('created_at', 'desc')
            ->get();

        $planNameBySubscriptionId = $subscriptionModels->mapWithKeys(function ($item) {
            return [(int) $item->id => $item->premiumPlan?->name];
        });

        $subscriptions = $subscriptionModels
            ->map(function (ShopOwnerSubscription $subscription) use ($now, $hasCancellationColumns, $hasPlanChangeColumns, $planNameBySubscriptionId, $subscriptionModels) {
                $effectiveEndsAt = $subscription->ends_at;
                if (!$effectiveEndsAt && $subscription->starts_at && $subscription->premiumPlan) {
                    $effectiveEndsAt = $subscription->starts_at->copy()->addDays((int) $subscription->premiumPlan->duration_days);
                }

                $nextBillingAt = null;
                if ($subscription->status === 'active' && $effectiveEndsAt?->greaterThanOrEqualTo($now)) {
                    $nextBillingAt = $effectiveEndsAt;
                }

                $cancellationReason = $hasCancellationColumns ? $subscription->cancellation_reason : null;
                $cancellationNotes = $hasCancellationColumns ? $subscription->cancellation_notes : null;

                $replacesSubscriptionId = $hasPlanChangeColumns
                    ? ($subscription->replaces_subscription_id ? (int) $subscription->replaces_subscription_id : null)
                    : null;

                $hasPendingLifecycleChild = $subscriptionModels->contains(
                    fn (ShopOwnerSubscription $candidate): bool => $candidate->status === 'pending'
                        && ((int) $candidate->renewal_of_subscription_id === (int) $subscription->id
                            || (int) $candidate->replaces_subscription_id === (int) $subscription->id),
                );
                $paidPayments = $subscription->payments
                    ->filter(fn (ShopOwnerSubscriptionPayment $payment): bool => $payment->status === 'paid')
                    ->sortByDesc(fn (ShopOwnerSubscriptionPayment $payment): int => $payment->paid_at?->getTimestamp() ?? 0)
                    ->values();
                $grossCollected = (float) $paidPayments->sum(
                    fn (ShopOwnerSubscriptionPayment $payment): float => (float) ($payment->amount_paid ?? 0),
                );
                $refundAttempts = $subscription->payments
                    ->flatMap(fn (ShopOwnerSubscriptionPayment $payment) => $payment->refunds)
                    ->sortByDesc('id')
                    ->values();
                $refundedAmount = (float) $refundAttempts
                    ->where('status', 'succeeded')
                    ->sum(fn ($refund): float => (float) ($refund->amount ?? 0));
                $unresolvedRefund = $refundAttempts->first(
                    fn ($refund): bool => in_array($refund->status, ['pending', 'processing', 'unknown'], true),
                );
                $refundPayment = $paidPayments->first(function (ShopOwnerSubscriptionPayment $payment) use ($refundAttempts): bool {
                    if (
                        strtolower((string) $payment->gateway) !== 'paymongo'
                        || ! filled($payment->paymongo_payment_id)
                        || strtoupper((string) $payment->currency) !== 'PHP'
                        || (float) ($payment->amount_paid ?? 0) <= 0
                    ) {
                        return false;
                    }

                    return ! $refundAttempts->contains(
                        fn ($refund): bool => (int) $refund->payment_id === (int) $payment->id
                            && in_array($refund->status, ['pending', 'processing', 'unknown', 'succeeded'], true),
                    );
                });
                $canCancel = $subscription->status === 'active'
                    && $grossCollected > 0
                    && ! $hasPendingLifecycleChild;
                $eligibleForRefund = in_array($subscription->status, ['active', 'cancelled'], true)
                    && $refundPayment instanceof ShopOwnerSubscriptionPayment
                    && ! $hasPendingLifecycleChild;

                return [
                    'id' => $subscription->id,
                    'shop' => [
                        'id' => $subscription->shopOwner->id,
                        'business_name' => $subscription->shopOwner->business_name,
                        'owner_name' => $subscription->shopOwner->first_name . ' ' . $subscription->shopOwner->last_name,
                        'email' => $subscription->shopOwner->email,
                    ],
                    'premium_plan' => $subscription->premiumPlan ? [
                        'id' => $subscription->premiumPlan->id,
                        'name' => $subscription->premiumPlan->name,
                        'price' => $subscription->premiumPlan->price,
                        'duration_days' => $subscription->premiumPlan->duration_days,
                    ] : null,
                    'plan_code' => $subscription->plan_code,
                    'showroom_slot_limit' => $subscription->showroom_slot_limit,
                    'status' => $subscription->status,
                    'amount_paid' => $grossCollected,
                    'refunded_amount' => $refundedAmount,
                    'net_collected' => max(0, $grossCollected - $refundedAmount),
                    'starts_at' => $subscription->starts_at ? $subscription->starts_at->format('Y-m-d H:i:s') : null,
                    'ends_at' => $effectiveEndsAt?->format('Y-m-d H:i:s'),
                    'next_billing_at' => $nextBillingAt?->format('Y-m-d H:i:s'),
                    'cancellation_reason' => $cancellationReason,
                    'cancellation_notes' => $cancellationNotes,
                    'payment_method' => $hasPlanChangeColumns ? $subscription->payment_method : null,
                    'replaces_subscription_id' => $replacesSubscriptionId,
                    'previous_plan_name' => $replacesSubscriptionId ? $planNameBySubscriptionId->get($replacesSubscriptionId) : null,
                    'payments' => $subscription->payments->sortByDesc('id')->values()->map(
                        fn (ShopOwnerSubscriptionPayment $payment): array => [
                            'id' => (int) $payment->id,
                            'payment_type' => (string) $payment->payment_type,
                            'amount_due' => (float) ($payment->amount_due ?? 0),
                            'amount_paid' => $payment->amount_paid !== null ? (float) $payment->amount_paid : null,
                            'currency' => (string) $payment->currency,
                            'status' => (string) $payment->status,
                            'paid_at' => $payment->paid_at?->toISOString(),
                            'refunds' => $payment->refunds->sortByDesc('id')->values()->map(
                                fn ($refund): array => [
                                    'id' => (int) $refund->id,
                                    'status' => (string) $refund->status,
                                    'amount' => (float) $refund->amount,
                                    'currency' => (string) $refund->currency,
                                    'business_reason' => (string) $refund->business_reason,
                                    'provider_reason' => (string) $refund->provider_reason,
                                    'provider_refund_id' => $refund->provider_refund_id,
                                    'failure_code' => $refund->failure_code,
                                    'initiated_at' => $refund->initiated_at?->toISOString(),
                                    'finalized_at' => $refund->finalized_at?->toISOString(),
                                    'reconciled_at' => $refund->reconciled_at?->toISOString(),
                                ],
                            )->all(),
                        ],
                    )->all(),
                    'refund_attempts' => $refundAttempts->map(
                        fn ($refund): array => [
                            'id' => (int) $refund->id,
                            'status' => (string) $refund->status,
                            'amount' => (float) $refund->amount,
                            'currency' => (string) $refund->currency,
                            'business_reason' => (string) $refund->business_reason,
                            'provider_reason' => (string) $refund->provider_reason,
                            'provider_refund_id' => $refund->provider_refund_id,
                            'failure_code' => $refund->failure_code,
                            'initiated_at' => $refund->initiated_at?->toISOString(),
                            'finalized_at' => $refund->finalized_at?->toISOString(),
                            'reconciled_at' => $refund->reconciled_at?->toISOString(),
                        ],
                    )->all(),
                    'can_cancel' => $canCancel,
                    'legacy_correction_available' => $subscription->status === 'deactivated',
                    'eligible_for_refund' => $eligibleForRefund,
                    'refund_payment_id' => $eligibleForRefund ? (int) $refundPayment->id : null,
                    'refund_block_reason' => $unresolvedRefund ? 'reconciliation_required' : null,
                    'created_at' => $subscription->created_at->format('Y-m-d H:i:s'),
                ];
            });

        $isOngoing = function (array $sub) use ($now) {
            if (in_array(($sub['status'] ?? null), ['active', 'cancelled'], true) && !empty($sub['ends_at'])) {
                $endDate = \Carbon\Carbon::parse($sub['ends_at']);
                return $endDate->greaterThanOrEqualTo($now);
            }

            return ($sub['status'] ?? null) === 'active';
        };

        $grossCollected = (float) $subscriptions->sum('amount_paid');
        $refundedAmount = (float) $subscriptions->sum('refunded_amount');

        $stats = [
            'active' => $subscriptions->filter(fn ($sub) => $isOngoing($sub))->count(),
            'expired' => $subscriptions->filter(fn ($sub) => !$isOngoing($sub))->count(),
            'total_revenue' => $grossCollected,
            'gross_collected' => $grossCollected,
            'refunded_amount' => $refundedAmount,
            'net_collected' => max(0, $grossCollected - $refundedAmount),
            'expiring_soon' => $subscriptions->filter(function ($sub) use ($isOngoing, $now) {
                if (!$isOngoing($sub) || empty($sub['ends_at'])) {
                    return false;
                }

                $endDate = \Carbon\Carbon::parse($sub['ends_at']);
                return $endDate->betweenIncluded($now, $now->copy()->addDays(7));
            })->count(),
        ];

        $plans = PremiumPlan::query()
            ->withCount(['subscriptions as active_subscriptions_count' => fn ($query) => $query->showroomEntitled()])
            ->orderBy('price')
            ->get();

        return Inertia::render('superAdmin/Shops/SubscriptionManagement', [
            'subscriptions' => $subscriptions,
            'stats' => $stats,
            'plans' => $plans,
        ]);
    }
}
