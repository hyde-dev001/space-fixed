<?php

namespace App\Services;

use App\Models\PlatformCreditApplication;
use App\Models\PlatformFeeAdjustment;
use App\Models\PlatformFeeCharge;
use App\Models\PlatformFeePaymentAllocation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class PlatformBalanceService
{
    public function __construct(
        private readonly PlatformFeeSettingsResolver $settings,
    ) {}

    /** @return array<string, mixed> */
    public function summary(int $shopId): array
    {
        $config = $this->settings->forShop($shopId);
        $charges = $this->sum(
            PlatformFeeCharge::query()
                ->where('shop_id', $shopId)
                ->where('source_origin', 'marketplace')
                ->where('status', '!=', 'void'),
            'total_charge',
        );
        $adjustments = $this->sum(PlatformFeeAdjustment::query()->where('shop_id', $shopId), 'total_delta');
        $paid = $this->sum(
            PlatformFeePaymentAllocation::query()
                ->where('allocation_type', 'payment')
                ->whereHas('payment', fn ($query) => $query->where('shop_id', $shopId)->where('status', 'paid')),
            'amount',
        );
        $creditApplications = PlatformCreditApplication::query()
            ->where('shop_id', $shopId)
            ->where('source_origin', 'marketplace')
            ->oldest('created_at')
            ->oldest('id')
            ->get([
                'id',
                'source_type',
                'source_id',
                'source_origin',
                'credit_amount',
                'status',
                'reason',
                'created_at',
            ]);
        $creditAllocations = $creditApplications->isEmpty()
            ? collect()
            : PlatformFeePaymentAllocation::query()
                ->where('allocation_type', 'credit')
                ->whereIn('platform_credit_application_id', $creditApplications->modelKeys())
                ->oldest('created_at')
                ->get(['platform_credit_application_id', 'amount', 'created_at']);
        $allocationsByCredit = $creditAllocations->groupBy('platform_credit_application_id');
        $issuedCredits = $this->sumValues($creditApplications->pluck('credit_amount'));
        $appliedCredits = $this->sumValues($creditAllocations->pluck('amount'));
        $remainingCredits = $this->maxZero($issuedCredits->minus($appliedCredits));
        $outstandingBeforeCredits = $this->maxZero($this->decimal($charges)->plus($adjustments)->minus($paid));
        $outstanding = $this->maxZero($outstandingBeforeCredits->minus($appliedCredits));
        $outstandingReducedByCredits = $appliedCredits->isGreaterThan($outstandingBeforeCredits)
            ? $outstandingBeforeCredits
            : $appliedCredits;
        $creditMovements = $this->creditMovements(
            $creditApplications,
            $allocationsByCredit,
            $outstandingBeforeCredits,
        );
        $availableCredits = $remainingCredits;
        $netPayable = $this->maxZero($outstanding->minus($availableCredits));
        $limit = $this->decimal((string) $config['balance_limit']);
        $utilization = $limit->isGreaterThan(0)
            ? $netPayable->dividedBy($limit, 8, RoundingMode::HALF_UP)->multipliedBy(100)->toScale(2, RoundingMode::HALF_UP)
            : BigDecimal::zero()->toScale(2);

        return [
            'outstanding_balance' => $outstanding->toScale(2, RoundingMode::HALF_UP)->__toString(),
            'available_credits' => $availableCredits->toScale(2, RoundingMode::HALF_UP)->__toString(),
            'net_payable' => $netPayable->toScale(2, RoundingMode::HALF_UP)->__toString(),
            'credit_summary' => [
                'issued' => $issuedCredits->toScale(2, RoundingMode::HALF_UP)->__toString(),
                'applied' => $appliedCredits->toScale(2, RoundingMode::HALF_UP)->__toString(),
                'remaining' => $remainingCredits->toScale(2, RoundingMode::HALF_UP)->__toString(),
                'outstanding_before_credits' => $outstandingBeforeCredits->toScale(2, RoundingMode::HALF_UP)->__toString(),
                'outstanding_after_credits' => $outstanding->toScale(2, RoundingMode::HALF_UP)->__toString(),
                'outstanding_reduced_by_credits' => $outstandingReducedByCredits->toScale(2, RoundingMode::HALF_UP)->__toString(),
            ],
            'credit_movements' => $creditMovements,
            'balance_limit' => $limit->toScale(2, RoundingMode::HALF_UP)->__toString(),
            'utilization_percentage' => $utilization->__toString(),
            'warning_threshold_percentage' => (string) $config['warning_threshold_percentage'],
            'critical_threshold_percentage' => (string) $config['critical_threshold_percentage'],
            'enforcement_enabled' => (bool) $config['enforcement_enabled'],
            'shop_type' => $config['shop_type'],
            'is_restricted' => (bool) $config['enforcement_enabled'] && $limit->isGreaterThan(0) && $netPayable->isGreaterThanOrEqualTo($limit),
        ];
    }

    private function sum($query, string $column): BigDecimal
    {
        return $this->sumValues($query->pluck($column));
    }

    private function sumValues(iterable $values): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($values as $value) {
            $total = $total->plus((string) ($value ?? '0'));
        }

        return $total;
    }

    /** @return array<int, array<string, mixed>> */
    private function creditMovements($creditApplications, $allocationsByCredit, BigDecimal $outstandingBeforeCredits): array
    {
        $runningOutstanding = $outstandingBeforeCredits;

        return $creditApplications
            ->map(function (PlatformCreditApplication $credit) use ($allocationsByCredit, &$runningOutstanding): array {
                $allocations = $allocationsByCredit->get($credit->id, collect());
                $applied = $this->sumValues($allocations->pluck('amount'));
                $creditAmount = $this->decimal((string) $credit->credit_amount);
                $before = $runningOutstanding;
                $after = $this->maxZero($before->minus($applied));
                $runningOutstanding = $after;
                $lastAllocation = $allocations->sortByDesc(
                    fn ($allocation): int => $allocation->created_at?->timestamp ?? 0,
                )->first();

                return [
                    'id' => (int) $credit->id,
                    'source_type' => (string) $credit->source_type,
                    'source_id' => (int) $credit->source_id,
                    'source_origin' => (string) $credit->source_origin,
                    'credit_amount' => $creditAmount->toScale(2, RoundingMode::HALF_UP)->__toString(),
                    'applied_amount' => $applied->toScale(2, RoundingMode::HALF_UP)->__toString(),
                    'remaining_amount' => $this->maxZero($creditAmount->minus($applied))
                        ->toScale(2, RoundingMode::HALF_UP)->__toString(),
                    'status' => (string) $credit->status,
                    'reason' => (string) $credit->reason,
                    'created_at' => $credit->created_at?->toISOString(),
                    'last_applied_at' => $lastAllocation?->created_at?->toISOString(),
                    'outstanding_before_credit' => $before->toScale(2, RoundingMode::HALF_UP)->__toString(),
                    'outstanding_after_credit' => $after->toScale(2, RoundingMode::HALF_UP)->__toString(),
                ];
            })
            ->reverse()
            ->values()
            ->all();
    }

    private function decimal(string $value): BigDecimal
    {
        return BigDecimal::of(trim($value) === '' ? '0' : $value);
    }

    private function maxZero(BigDecimal $value): BigDecimal
    {
        return $value->isLessThan(0) ? BigDecimal::zero() : $value;
    }
}
