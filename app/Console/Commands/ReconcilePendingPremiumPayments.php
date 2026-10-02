<?php

namespace App\Console\Commands;

use App\Models\ShopOwnerSubscriptionPayment;
use App\Services\PremiumSubscriptionPaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class ReconcilePendingPremiumPayments extends Command
{
    protected $signature = 'premium-payments:reconcile-pending
        {--apply : Apply verified provider settlements; default is a dry-run}
        {--limit=100 : Maximum pending payments to inspect}
        {--shop-owner= : Limit reconciliation to one shop owner ID}';

    protected $description = 'Verify pending premium subscription payments with PayMongo';

    public function handle(PremiumSubscriptionPaymentService $payments): int
    {
        $limit = min(1000, max(1, (int) $this->option('limit')));
        $apply = (bool) $this->option('apply');
        $ownerId = $this->option('shop-owner');
        if ($ownerId !== null && (! ctype_digit((string) $ownerId) || (int) $ownerId < 1)) {
            $this->error('--shop-owner must be a positive owner ID.');

            return self::INVALID;
        }
        $this->info('Pending premium payment reconciliation ('.($apply ? 'apply' : 'dry-run').')');

        $candidates = ShopOwnerSubscriptionPayment::query()
            ->with('subscription')
            ->where('gateway', 'paymongo')
            ->where('status', 'pending')
            ->whereIn('payment_type', ['new_subscription', 'upgrade', 'renewal'])
            ->whereNotNull('paymongo_session_id')
            ->when(is_numeric($ownerId) && (int) $ownerId > 0, fn ($query) => $query->where('shop_owner_id', (int) $ownerId))
            ->whereHas('subscription', fn ($query) => $query->whereIn('status', ['pending', 'active']))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $counts = [];
        foreach ($candidates as $payment) {
            $result = $payments->verifyAndSettleProviderSession((string) $payment->paymongo_session_id, $apply);
            $status = (string) ($result['result'] ?? 'unsafe');
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $line = "{$status}: payment={$payment->id} subscription={$payment->subscription_id}";
            $this->line($line);
            Log::info('Pending premium payment reconciliation '.$status, [
                'payment_id' => $payment->id,
                'subscription_id' => $payment->subscription_id,
                'shop_owner_id' => $payment->shop_owner_id,
                'mode' => $apply ? 'apply' : 'dry-run',
            ]);
        }

        $this->line('Inspected: '.$candidates->count());
        foreach ($counts as $status => $count) {
            $this->line(ucfirst(str_replace('_', ' ', $status)).": {$count}");
        }

        return self::SUCCESS;
    }
}
