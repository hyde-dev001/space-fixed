<?php

namespace App\Console\Commands;

use App\Models\ShopOwnerSubscription;
use App\Services\LegacyPremiumBillingReconciler;
use Illuminate\Console\Command;

final class ReconcileLegacyPremiumBilling extends Command
{
    protected $signature = 'premium-billing:reconcile-legacy
        {--apply : Apply reliable local reconciliations; default is a dry-run}
        {--limit=100 : Maximum number of subscriptions to inspect}';

    protected $description = 'Report or reconcile reliable legacy premium billing records';

    public function handle(LegacyPremiumBillingReconciler $reconciler): int
    {
        $limit = min(1000, max(1, (int) $this->option('limit')));
        $apply = (bool) $this->option('apply');
        $mode = $apply ? 'apply' : 'dry-run';
        $this->info("Premium billing legacy reconciliation ({$mode})");
        if ($apply) {
            $this->line('Apply mode: reliable changes will be applied.');
        }

        $counts = [
            'reconciled' => 0,
            'would_reconcile' => 0,
            'would_update' => 0,
            'ambiguous' => 0,
            'unchanged' => 0,
        ];
        $processed = 0;

        ShopOwnerSubscription::query()
            ->whereIn('status', ['active', 'cancelled', 'expired', 'deactivated'])
            ->orderBy('id')
            ->limit($limit)
            ->chunkById(100, function ($subscriptions) use ($reconciler, $apply, &$counts, &$processed, $limit): bool {
                foreach ($subscriptions as $subscription) {
                    $result = $reconciler->reconcile($subscription, $apply);
                    $key = (string) ($result['result'] ?? 'unchanged');
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                    $processed++;

                    if ($processed >= $limit) {
                        return false;
                    }
                }

                return true;
            });

        $this->line('Processed: '.$processed);
        foreach ($counts as $name => $count) {
            if ($count > 0) {
                $this->line(ucfirst(str_replace('_', ' ', $name)).": {$count}");
            }
        }

        return self::SUCCESS;
    }
}
