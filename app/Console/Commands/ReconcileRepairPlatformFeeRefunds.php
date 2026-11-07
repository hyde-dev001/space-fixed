<?php

namespace App\Console\Commands;

use App\Models\PlatformCreditApplication;
use App\Models\PlatformFeeAdjustment;
use App\Models\PosRefund;
use App\Services\PlatformFeeRefundService;
use Illuminate\Console\Command;

final class ReconcileRepairPlatformFeeRefunds extends Command
{
    protected $signature = 'platform-fees:reconcile-repair-refunds {--apply : Write missing refund reversals}';

    protected $description = 'Find and reconcile missing platform fee reversals for successful repair refunds';

    public function handle(PlatformFeeRefundService $refunds): int
    {
        $count = 0;
        PosRefund::query()->where('module_type', 'repair')
            ->whereIn('status', ['succeeded', 'successful', 'completed', 'paid', 'refunded'])
            ->where(fn ($query) => $query->whereNull('workflow_source')->orWhere('workflow_source', '!=', 'delivery_reconciliation'))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('platform_fee_charges')
                ->whereColumn('platform_fee_charges.source_id', 'pos_refunds.module_reference_id')
                ->whereColumn('platform_fee_charges.shop_id', 'pos_refunds.shop_owner_id')
                ->where('platform_fee_charges.source_type', 'repair')
                ->where('platform_fee_charges.source_origin', 'marketplace'))
            ->orderBy('id')->chunkById(100, function ($batch) use ($refunds, &$count): void {
                foreach ($batch as $refund) {
                    $key = "repair_refund:{$refund->id}";
                    if (PlatformFeeAdjustment::query()->where('idempotency_key', $key.':reversal')->exists()
                        || PlatformCreditApplication::query()->where('idempotency_key', $key.':credit')->exists()) {
                        continue;
                    }
                    $count++;
                    if ($this->option('apply')) {
                        $refunds->reverseRepairRefund($refund);
                    }
                }
            });
        $this->info(($this->option('apply') ? 'Processed' : 'Found').' '.$count.' repair refunds missing a platform fee entry.');

        return self::SUCCESS;
    }
}
