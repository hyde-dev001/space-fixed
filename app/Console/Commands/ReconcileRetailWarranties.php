<?php

namespace App\Console\Commands;

use App\Jobs\DeliverRetailWarranty;
use App\Models\CodCollection;
use App\Models\Order;
use App\Models\RetailWarrantyIssuance;
use App\Services\RetailWarrantyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileRetailWarranties extends Command
{
    protected $signature = 'retail-warranties:reconcile {--batch=100 : Maximum records per recovery lane}';

    protected $description = 'Recover captured warranty issuance/delivery and reconcile effective coverage without historical backfill';

    public function handle(RetailWarrantyService $warranties): int
    {
        $limit = max(1, min(1000, (int) $this->option('batch')));
        $orders = Order::whereNotNull('retail_warranty_fulfilled_at')->where('retail_warranty_policy_snapshot->eligible', true)
            ->whereIn('status', ['delivered', 'completed'])->whereDoesntHave('retailWarrantyIssuance')
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereRaw("LOWER(TRIM(COALESCE(payment_method,''))) NOT IN ('cod','cash_on_delivery','cash on delivery')")
                ->whereIn('payment_status', ['paid', 'completed']))->orWhereHas('codCollection', fn ($q) => $q
                ->whereIn('status', [CodCollection::STATUS_CASH_COLLECTED, CodCollection::STATUS_SETTLED])->where('collected_amount', '>', 0)))
            ->orderBy('id')->limit($limit)->get();
        foreach ($orders as $order) {
            $warranties->issueCaptured($order);
        }
        foreach ($warranties->reconciliationCandidates($limit)->pluck('order_id')->unique() as $orderId) {
            $warranties->reconcileOrder(Order::findOrFail($orderId));
        }
        $stale = RetailWarrantyIssuance::where('email_delivery_state', 'sending')
            ->where(fn ($q) => $q->whereNull('email_attempted_at')->orWhere('email_attempted_at', '<=', now()->subMinutes(15)))
            ->orderBy('id')->limit($limit)->get();
        foreach ($stale as $issuance) {
            DB::transaction(function () use ($issuance, $warranties) {
                $locked = RetailWarrantyIssuance::whereKey($issuance->id)->lockForUpdate()->firstOrFail();
                if ($locked->email_delivery_state === 'sending' && (! $locked->email_attempted_at || $locked->email_attempted_at->lte(now()->subMinutes(15)))) {
                    $locked->forceFill(['email_delivery_state' => 'unknown', 'email_failure_code' => 'worker_acceptance_unknown'])->save();
                    $warranties->audit($locked->shop_owner_id, 'retail_warranty.email_unknown', 'retail_warranty_issuance', $locked->id);
                }
            });
        }
        $pending = RetailWarrantyIssuance::where(fn ($q) => $q->where('email_delivery_state', 'pending')
            ->orWhere(fn ($q) => $q->where('email_delivery_state', 'failed')->where('email_attempted_at', '<=', now()->subMinutes(15))))
            ->orderBy('id')->limit($limit)->get();
        foreach ($pending as $issuance) {
            DeliverRetailWarranty::dispatch($issuance->id)->afterCommit();
        }
        $this->info('Retail Product Warranty recovery and reconciliation completed.');

        return self::SUCCESS;
    }
}
