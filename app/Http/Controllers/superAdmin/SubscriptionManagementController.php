<?php

declare(strict_types=1);

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Controller;
use App\Models\PremiumPlan;
use App\Models\ShopOwnerSubscription;
use App\Models\ShopOwnerSubscriptionPayment;
use App\Models\ShopOwnerSubscriptionRefund;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class SubscriptionManagementController extends Controller
{
    private const LIST_PAGE_SIZE = 25;

    private const HISTORY_PAGE_SIZE = 25;

    private const MAX_PAGE_SIZE = 100;

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::in(['all', 'ongoing', 'end', 'deactivated'])],
            'change_type' => ['sometimes', 'nullable', Rule::in(['all', 'upgraded', 'regular'])],
            'sort' => ['sometimes', 'nullable', Rule::in(['latest', 'oldest', 'amount_high', 'amount_low'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PAGE_SIZE],
        ]);

        $now = Carbon::now();
        $hasCancellationColumns = Schema::hasColumn('shop_owner_subscriptions', 'cancellation_reason')
            && Schema::hasColumn('shop_owner_subscriptions', 'cancellation_notes');
        $hasPlanChangeColumns = Schema::hasColumn('shop_owner_subscriptions', 'replaces_subscription_id')
            && Schema::hasColumn('shop_owner_subscriptions', 'payment_method');
        $effectiveEndExpression = $this->effectiveEndExpression(
            'shop_owner_subscriptions',
            'billing_plan',
        );

        $query = ShopOwnerSubscription::query()
            ->leftJoin('premium_plans as billing_plan', 'billing_plan.id', '=', 'shop_owner_subscriptions.premium_plan_id')
            ->select('shop_owner_subscriptions.*')
            ->with([
                'shopOwner:id,first_name,last_name,business_name,email',
                'premiumPlan:id,name,price,duration_days',
                'replacedSubscription:id,premium_plan_id',
                'replacedSubscription.premiumPlan:id,name',
            ])
            ->selectSub($this->grossCollectedSubquery(), 'gross_collected')
            ->selectSub($this->refundedAmountSubquery(), 'refunded_amount')
            ->selectSub($this->unresolvedRefundSubquery(), 'has_unresolved_refund')
            ->selectSub($this->pendingLifecycleChildSubquery($hasPlanChangeColumns), 'has_pending_lifecycle_child')
            ->selectSub($this->eligibleRefundPaymentSubquery(), 'eligible_refund_payment_id');

        if ($hasCancellationColumns) {
            $query->addSelect([
                'shop_owner_subscriptions.cancellation_reason',
                'shop_owner_subscriptions.cancellation_notes',
            ]);
        }

        if ($hasPlanChangeColumns) {
            $query->addSelect([
                'shop_owner_subscriptions.replaces_subscription_id',
                'shop_owner_subscriptions.payment_method',
            ]);
        }

        if (($validated['search'] ?? null) !== null && $validated['search'] !== '') {
            $search = (string) $validated['search'];
            $query->whereHas('shopOwner', function (Builder $ownerQuery) use ($search): void {
                $ownerQuery->where(function (Builder $searchQuery) use ($search): void {
                    $this->whereContains($searchQuery, 'business_name', $search);
                    $this->whereContains($searchQuery, 'first_name', $search, 'or');
                    $this->whereContains($searchQuery, 'last_name', $search, 'or');
                    $this->whereContains($searchQuery, 'email', $search, 'or');
                });
            });
        }

        $this->applyStatusFilter($query, $validated['status'] ?? 'all', $effectiveEndExpression, $now);

        if (($validated['change_type'] ?? 'all') === 'upgraded' && $hasPlanChangeColumns) {
            $query->whereNotNull('shop_owner_subscriptions.replaces_subscription_id');
        } elseif (($validated['change_type'] ?? 'all') === 'regular' && $hasPlanChangeColumns) {
            $query->whereNull('shop_owner_subscriptions.replaces_subscription_id');
        } elseif (($validated['change_type'] ?? 'all') === 'upgraded' && ! $hasPlanChangeColumns) {
            $query->whereRaw('1 = 0');
        }

        $sort = $validated['sort'] ?? 'latest';
        if ($sort === 'oldest') {
            $query->orderBy('shop_owner_subscriptions.created_at')
                ->orderBy('shop_owner_subscriptions.id');
        } elseif ($sort === 'amount_high') {
            $query->orderByDesc('gross_collected')
                ->orderByDesc('shop_owner_subscriptions.id');
        } elseif ($sort === 'amount_low') {
            $query->orderBy('gross_collected')
                ->orderBy('shop_owner_subscriptions.id');
        } else {
            $query->orderByDesc('shop_owner_subscriptions.created_at')
                ->orderByDesc('shop_owner_subscriptions.id');
        }

        $subscriptions = $query
            ->paginate((int) ($validated['per_page'] ?? self::LIST_PAGE_SIZE))
            ->withQueryString()
            ->through(fn (ShopOwnerSubscription $subscription): array => $this->serializeSummary(
                $subscription,
                $now,
                $hasCancellationColumns,
                $hasPlanChangeColumns,
            ));

        $stats = $this->globalStats($now);

        $plans = PremiumPlan::query()
            ->withCount(['subscriptions as active_subscriptions_count' => fn ($planQuery) => $planQuery->showroomEntitled()])
            ->orderBy('price')
            ->get();

        return Inertia::render('superAdmin/Shops/SubscriptionManagement', [
            'subscriptions' => $subscriptions,
            'stats' => $stats,
            'plans' => $plans,
            'filters' => [
                'search' => $validated['search'] ?? null,
                'status' => $validated['status'] ?? 'all',
                'change_type' => $validated['change_type'] ?? 'all',
                'sort' => $validated['sort'] ?? 'latest',
            ],
        ]);
    }

    public function history(Request $request, ShopOwnerSubscription $subscription): JsonResponse
    {
        $validated = $request->validate([
            'payment_page' => ['sometimes', 'integer', 'min:1'],
            'refund_page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PAGE_SIZE],
        ]);

        // The route is already behind manage_plans. Resolve the model again in that
        // same global scope before touching either immutable child ledger.
        $subscription = ShopOwnerSubscription::query()->findOrFail($subscription->getKey());
        $perPage = (int) ($validated['per_page'] ?? self::HISTORY_PAGE_SIZE);

        $payments = ShopOwnerSubscriptionPayment::query()
            ->where('subscription_id', $subscription->id)
            ->select([
                'id',
                'payment_type',
                'amount_due',
                'amount_paid',
                'currency',
                'status',
                'paid_at',
                'created_at',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'payment_page', (int) ($validated['payment_page'] ?? 1))
            ->withQueryString()
            ->through(fn (ShopOwnerSubscriptionPayment $payment): array => $this->serializePayment($payment));

        $refunds = ShopOwnerSubscriptionRefund::query()
            ->where('subscription_id', $subscription->id)
            ->select([
                'id',
                'payment_id',
                'local_reference',
                'provider_refund_id',
                'amount',
                'currency',
                'business_reason',
                'provider_reason',
                'status',
                'failure_code',
                'initiated_at',
                'finalized_at',
                'reconciled_at',
                'created_at',
            ])
            ->orderByDesc('initiated_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'refund_page', (int) ($validated['refund_page'] ?? 1))
            ->withQueryString()
            ->through(fn (ShopOwnerSubscriptionRefund $refund): array => $this->serializeRefund($refund));

        return response()->json([
            'subscription_id' => (int) $subscription->id,
            'payments' => $payments,
            'refunds' => $refunds,
        ]);
    }

    /** @return array<string, int|float> */
    private function globalStats(Carbon $now): array
    {
        $base = DB::table('shop_owner_subscriptions as subscriptions')
            ->leftJoin('premium_plans as billing_plan', 'billing_plan.id', '=', 'subscriptions.premium_plan_id');
        $effectiveEndExpression = $this->effectiveEndExpression('subscriptions', 'billing_plan');
        $nowValue = $now->toDateTimeString();
        $soonValue = $now->copy()->addDays(7)->toDateTimeString();

        $ongoing = function ($query) use ($effectiveEndExpression, $nowValue): void {
            $query->where(function ($ongoingQuery) use ($effectiveEndExpression, $nowValue): void {
                $ongoingQuery
                    ->where(function ($activeQuery) use ($effectiveEndExpression, $nowValue): void {
                        $activeQuery->where('subscriptions.status', 'active')
                            ->whereRaw("({$effectiveEndExpression}) IS NULL OR ({$effectiveEndExpression}) >= ?", [$nowValue]);
                    })
                    ->orWhere(function ($cancelledQuery) use ($effectiveEndExpression, $nowValue): void {
                        $cancelledQuery->where('subscriptions.status', 'cancelled')
                            ->whereRaw("({$effectiveEndExpression}) >= ?", [$nowValue]);
                    });
            });
        };

        $active = (clone $base);
        $ongoing($active);
        $total = (clone $base)->count('subscriptions.id');
        $activeCount = $active->count('subscriptions.id');

        $expiring = (clone $base);
        $ongoing($expiring);
        $expiringCount = $expiring
            ->whereRaw("({$effectiveEndExpression}) BETWEEN ? AND ?", [$nowValue, $soonValue])
            ->count('subscriptions.id');

        $grossCollected = (float) ShopOwnerSubscriptionPayment::query()
            ->where('status', 'paid')
            ->sum('amount_paid');
        $refundedAmount = (float) ShopOwnerSubscriptionRefund::query()
            ->where('status', 'succeeded')
            ->sum('amount');

        return [
            'active' => $activeCount,
            'expired' => max(0, $total - $activeCount),
            'total_revenue' => $grossCollected,
            'gross_collected' => $grossCollected,
            'refunded_amount' => $refundedAmount,
            'net_collected' => max(0, $grossCollected - $refundedAmount),
            'expiring_soon' => $expiringCount,
        ];
    }

    private function applyStatusFilter(
        Builder $query,
        string $status,
        string $effectiveEndExpression,
        Carbon $now,
    ): void {
        $nowValue = $now->toDateTimeString();

        if ($status === 'deactivated') {
            $query->where('shop_owner_subscriptions.status', 'deactivated');
            return;
        }

        if ($status === 'ongoing') {
            $query->where(function (Builder $ongoingQuery) use ($effectiveEndExpression, $nowValue): void {
                $ongoingQuery
                    ->where(function (Builder $activeQuery) use ($effectiveEndExpression, $nowValue): void {
                        $activeQuery->where('shop_owner_subscriptions.status', 'active')
                            ->whereRaw("({$effectiveEndExpression}) IS NULL OR ({$effectiveEndExpression}) >= ?", [$nowValue]);
                    })
                    ->orWhere(function (Builder $cancelledQuery) use ($effectiveEndExpression, $nowValue): void {
                        $cancelledQuery->where('shop_owner_subscriptions.status', 'cancelled')
                            ->whereRaw("({$effectiveEndExpression}) >= ?", [$nowValue]);
                    });
            });
            return;
        }

        if ($status === 'end') {
            $query->whereRaw(
                "NOT ((shop_owner_subscriptions.status = 'active' AND (({$effectiveEndExpression}) IS NULL OR ({$effectiveEndExpression}) >= ?)) OR (shop_owner_subscriptions.status = 'cancelled' AND ({$effectiveEndExpression}) >= ?))",
                [$nowValue, $nowValue],
            );
        }
    }

    private function grossCollectedSubquery(): Builder
    {
        return ShopOwnerSubscriptionPayment::query()
            ->selectRaw('COALESCE(SUM(amount_paid), 0)')
            ->whereColumn('subscription_id', 'shop_owner_subscriptions.id')
            ->where('status', 'paid');
    }

    private function refundedAmountSubquery(): Builder
    {
        return ShopOwnerSubscriptionRefund::query()
            ->selectRaw('COALESCE(SUM(amount), 0)')
            ->whereColumn('subscription_id', 'shop_owner_subscriptions.id')
            ->where('status', 'succeeded');
    }

    private function unresolvedRefundSubquery(): Builder
    {
        return ShopOwnerSubscriptionRefund::query()
            ->selectRaw('1')
            ->whereColumn('subscription_id', 'shop_owner_subscriptions.id')
            ->whereIn('status', ['pending', 'processing', 'unknown'])
            ->limit(1);
    }

    private function pendingLifecycleChildSubquery(bool $hasPlanChangeColumns): Builder
    {
        $query = ShopOwnerSubscription::query()
            ->from('shop_owner_subscriptions as lifecycle_child')
            ->selectRaw('1')
            ->where('lifecycle_child.status', 'pending')
            ->where(function (Builder $childQuery) use ($hasPlanChangeColumns): void {
                $childQuery->whereColumn('lifecycle_child.renewal_of_subscription_id', 'shop_owner_subscriptions.id');

                if ($hasPlanChangeColumns) {
                    $childQuery->orWhereColumn('lifecycle_child.replaces_subscription_id', 'shop_owner_subscriptions.id');
                }
            })
            ->limit(1);

        return $query;
    }

    private function eligibleRefundPaymentSubquery(): Builder
    {
        return ShopOwnerSubscriptionPayment::query()
            ->from('shop_owner_subscription_payments as eligible_payment')
            ->select('eligible_payment.id')
            ->whereColumn('eligible_payment.subscription_id', 'shop_owner_subscriptions.id')
            ->where('eligible_payment.status', 'paid')
            ->whereRaw("LOWER(COALESCE(eligible_payment.gateway, '')) = 'paymongo'")
            ->whereNotNull('eligible_payment.paymongo_payment_id')
            ->where('eligible_payment.paymongo_payment_id', '!=', '')
            ->whereRaw("UPPER(COALESCE(eligible_payment.currency, '')) = 'PHP'")
            ->where('eligible_payment.amount_paid', '>', 0)
            ->whereNotExists(function ($refundQuery): void {
                $refundQuery->selectRaw('1')
                    ->from('shop_owner_subscription_refunds as blocking_refund')
                    ->whereColumn('blocking_refund.payment_id', 'eligible_payment.id')
                    ->whereIn('blocking_refund.status', ['pending', 'processing', 'unknown', 'succeeded']);
            })
            ->orderByDesc('eligible_payment.paid_at')
            ->orderByDesc('eligible_payment.id')
            ->limit(1);
    }

    private function effectiveEndExpression(string $subscriptionTable, string $planTable): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "COALESCE({$subscriptionTable}.ends_at, CASE WHEN {$subscriptionTable}.starts_at IS NOT NULL AND {$planTable}.duration_days IS NOT NULL THEN datetime({$subscriptionTable}.starts_at, '+' || {$planTable}.duration_days || ' days') END)";
        }

        return "COALESCE({$subscriptionTable}.ends_at, CASE WHEN {$subscriptionTable}.starts_at IS NOT NULL AND {$planTable}.duration_days IS NOT NULL THEN DATE_ADD({$subscriptionTable}.starts_at, INTERVAL {$planTable}.duration_days DAY) END)";
    }

    private function serializeSummary(
        ShopOwnerSubscription $subscription,
        Carbon $now,
        bool $hasCancellationColumns,
        bool $hasPlanChangeColumns,
    ): array {
        $effectiveEndsAt = $subscription->ends_at;
        if (! $effectiveEndsAt && $subscription->starts_at && $subscription->premiumPlan) {
            $effectiveEndsAt = $subscription->starts_at->copy()->addDays((int) $subscription->premiumPlan->duration_days);
        }

        $nextBillingAt = null;
        if ($subscription->status === 'active' && $effectiveEndsAt?->greaterThanOrEqualTo($now)) {
            $nextBillingAt = $effectiveEndsAt;
        }

        $grossCollected = (float) ($subscription->gross_collected ?? 0);
        $refundedAmount = (float) ($subscription->refunded_amount ?? 0);
        $hasPendingLifecycleChild = (bool) ($subscription->has_pending_lifecycle_child ?? false);
        $eligiblePaymentId = $subscription->eligible_refund_payment_id
            ? (int) $subscription->eligible_refund_payment_id
            : null;
        $eligibleForRefund = in_array($subscription->status, ['active', 'cancelled'], true)
            && $eligiblePaymentId !== null
            && ! $hasPendingLifecycleChild;

        return [
            'id' => (int) $subscription->id,
            'shop' => [
                'id' => (int) $subscription->shopOwner->id,
                'business_name' => $subscription->shopOwner->business_name,
                'owner_name' => trim($subscription->shopOwner->first_name.' '.$subscription->shopOwner->last_name),
                'email' => $subscription->shopOwner->email,
            ],
            'premium_plan' => $subscription->premiumPlan ? [
                'id' => (int) $subscription->premiumPlan->id,
                'name' => $subscription->premiumPlan->name,
                'price' => $subscription->premiumPlan->price,
                'duration_days' => (int) $subscription->premiumPlan->duration_days,
            ] : null,
            'plan_code' => $subscription->plan_code,
            'showroom_slot_limit' => (int) $subscription->showroom_slot_limit,
            'status' => $subscription->status,
            'amount_paid' => $grossCollected,
            'refunded_amount' => $refundedAmount,
            'net_collected' => max(0, $grossCollected - $refundedAmount),
            'starts_at' => $subscription->starts_at?->format('Y-m-d H:i:s'),
            'ends_at' => $effectiveEndsAt?->format('Y-m-d H:i:s'),
            'next_billing_at' => $nextBillingAt?->format('Y-m-d H:i:s'),
            'cancellation_reason' => $hasCancellationColumns ? $subscription->cancellation_reason : null,
            'cancellation_notes' => $hasCancellationColumns ? $subscription->cancellation_notes : null,
            'payment_method' => $hasPlanChangeColumns ? $subscription->payment_method : null,
            'replaces_subscription_id' => $hasPlanChangeColumns && $subscription->replaces_subscription_id
                ? (int) $subscription->replaces_subscription_id
                : null,
            'previous_plan_name' => $hasPlanChangeColumns ? $subscription->replacedSubscription?->premiumPlan?->name : null,
            'can_cancel' => $subscription->status === 'active'
                && $grossCollected > 0
                && ! $hasPendingLifecycleChild,
            'legacy_correction_available' => $subscription->status === 'deactivated',
            'eligible_for_refund' => $eligibleForRefund,
            'refund_payment_id' => $eligibleForRefund ? $eligiblePaymentId : null,
            'refund_block_reason' => (bool) ($subscription->has_unresolved_refund ?? false)
                ? 'reconciliation_required'
                : null,
            'created_at' => $subscription->created_at->format('Y-m-d H:i:s'),
        ];
    }

    /** @return array<string, mixed> */
    private function serializePayment(ShopOwnerSubscriptionPayment $payment): array
    {
        return [
            'id' => (int) $payment->id,
            'payment_type' => (string) $payment->payment_type,
            'amount_due' => $payment->amount_due !== null ? (float) $payment->amount_due : null,
            'amount_paid' => $payment->amount_paid !== null ? (float) $payment->amount_paid : null,
            'currency' => (string) $payment->currency,
            'status' => strtolower((string) $payment->status),
            'paid_at' => $payment->paid_at?->toISOString(),
            'created_at' => $payment->created_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeRefund(ShopOwnerSubscriptionRefund $refund): array
    {
        return [
            'id' => (int) $refund->id,
            'payment_id' => (int) $refund->payment_id,
            'local_reference' => (string) $refund->local_reference,
            'provider_refund_id' => $refund->provider_refund_id,
            'amount' => (float) $refund->amount,
            'currency' => (string) $refund->currency,
            'business_reason' => (string) $refund->business_reason,
            'provider_reason' => (string) $refund->provider_reason,
            'status' => strtolower((string) $refund->status),
            'failure_code' => $refund->failure_code,
            'initiated_at' => $refund->initiated_at?->toISOString(),
            'finalized_at' => $refund->finalized_at?->toISOString(),
            'reconciled_at' => $refund->reconciled_at?->toISOString(),
            'created_at' => $refund->created_at?->toISOString(),
        ];
    }

    private function whereContains(Builder $query, string $column, string $value, string $boolean = 'and'): void
    {
        $escaped = strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
        $query->whereRaw(
            "{$column} LIKE ? ESCAPE '!'",
            ["%{$escaped}%"],
            $boolean,
        );
    }
}
