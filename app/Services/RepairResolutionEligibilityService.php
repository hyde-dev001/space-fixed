<?php

namespace App\Services;

use App\Models\PosRefund;
use App\Models\RepairRequest;
use App\Models\RepairWarrantyClaim;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class RepairResolutionEligibilityService
{
    /** Call inside the transaction before locking a refund or warranty claim. */
    public function lockRepair(int $repairId, int $shopOwnerId): ?RepairRequest
    {
        $repair = RepairRequest::query()->whereKey($repairId)->lockForUpdate()->first();
        if ($repair && (int) $repair->shop_owner_id !== $shopOwnerId) {
            throw ValidationException::withMessages(['repair' => ['Repair and financial source belong to different shops.']]);
        }

        return $repair;
    }

    public function assertWarrantyAllowed(RepairRequest $repair): void
    {
        $blocked = $this->serviceRefunds($repair)->where(function (Builder $query): void {
            $query->whereIn('status', ['requested', 'approved', 'processing'])
                ->orWhere(function (Builder $query): void {
                    $query->where('status', 'succeeded')->where('approved_amount', '>', 0);
                })
                ->orWhereHas('legs', function (Builder $query): void {
                    $query->where('status', 'processing')->orWhere(function (Builder $query): void {
                        $query->where('status', 'succeeded')->where('approved_amount', '>', 0);
                    });
                });
        })->exists();

        // These repair fields record service refunds, not delivery reconciliation.
        if ($blocked || (float) $repair->total_refunded_amount > 0
            || in_array((string) $repair->payment_status, ['refunded', 'partially_refunded'], true)) {
            throw ValidationException::withMessages([
                'repair' => ['Warranty is unavailable while a service refund is active or after any service amount has been refunded.'],
            ]);
        }
    }

    public function refundBlockReason(RepairRequest $repair, string $workflowSource, ?int $ignoreRefundId = null): ?string
    {
        if ($workflowSource === 'delivery_reconciliation') {
            return null;
        }

        if ((bool) $repair->is_warranty_job || $repair->billing_mode === 'warranty_no_charge') {
            return 'Service refunds must be requested against the original paid repair.';
        }

        if ($workflowSource === 'online_myrepair' && (string) $repair->status !== 'picked_up') {
            return 'Service refunds can only be requested after the customer receives the completed repair.';
        }

        $activeWarranty = RepairWarrantyClaim::query()
            ->where('shop_owner_id', $repair->shop_owner_id)
            ->where('original_repair_request_id', $repair->id)
            ->where(function (Builder $query): void {
                $query->where('status', RepairWarrantyClaim::STATUS_PENDING_REPAIRER)
                    ->orWhere(function (Builder $query): void {
                        $query->where('status', RepairWarrantyClaim::STATUS_APPROVED)
                            ->whereDoesntHave('approvedRepair', fn (Builder $job) => $job->whereIn('status', ['completed', 'picked_up']));
                    });
            })->exists();

        if ($activeWarranty) {
            return 'Refund cannot be requested while a warranty claim is active for this repair.';
        }

        if ($this->serviceRefunds($repair)
            ->when($ignoreRefundId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreRefundId))
            ->whereIn('status', ['requested', 'approved', 'processing'])->exists()) {
            return 'A service refund is already in progress for this repair.';
        }

        return null;
    }

    public function assertRefundAllowed(RepairRequest $repair, string $workflowSource, ?int $ignoreRefundId = null): void
    {
        $reason = $this->refundBlockReason($repair, $workflowSource, $ignoreRefundId);
        if ($reason !== null) {
            throw ValidationException::withMessages(['repair' => [$reason]]);
        }
    }

    public function serviceModificationBlockReason(RepairRequest $repair): ?string
    {
        $walkIn = ($repair->intake_delivery_method ?: $repair->delivery_method) === 'walk_in';
        if (!in_array($repair->status, ['repairer_accepted', 'pending'], true)
            || ($repair->status === 'pending' && !$walkIn)
            || !$repair->conversation_id || $repair->customer_confirmed_at
            || $repair->started_at || $repair->completed_at || $repair->received_at) {
            return 'Services can only be modified after repairer acceptance and before confirmation or work starts.';
        }
        if ($repair->origin_channel === 'pos' || $repair->manual_pos_queue_enabled
            || $repair->is_warranty_job || $repair->billing_mode === 'warranty_no_charge') {
            return 'This repair cannot be modified through the customer service editor.';
        }
        if ((float) $repair->total_paid_amount > 0 || $repair->payment_completed_at
            || in_array(strtolower((string) $repair->payment_status), [
                'paid', 'completed', 'down_payment_paid', 'partially_paid', 'partially_refunded', 'refunded',
            ], true)
            || $repair->posTransactions()->whereIn('status', ['paid', 'partially_refunded', 'refunded'])->exists()) {
            return 'Paid repairs can no longer be modified.';
        }
        if ($this->hasMaterialUsageHistory($repair)) {
            return 'Material usage has already been recorded; services can no longer be modified.';
        }

        return null;
    }

    public function hasMaterialUsageHistory(RepairRequest $repair): bool
    {
        return $repair->materialUsages()->exists()
            || $repair->materialPlanItems()->where('actual_quantity', '>', 0)->exists()
            || StockMovement::query()->where('reference_type', 'repair_request')
                ->where('reference_id', $repair->id)->where('movement_type', 'repair_usage')
                ->whereHas('inventoryItem', fn (Builder $query) => $query->where('shop_owner_id', $repair->shop_owner_id))
                ->exists();
    }

    private function serviceRefunds(RepairRequest $repair): Builder
    {
        return PosRefund::query()->where('shop_owner_id', $repair->shop_owner_id)
            ->where('module_type', 'repair')->where('module_reference_id', $repair->id)
            ->where(fn (Builder $query) => $query->whereNull('workflow_source')->orWhere('workflow_source', '!=', 'delivery_reconciliation'));
    }
}
