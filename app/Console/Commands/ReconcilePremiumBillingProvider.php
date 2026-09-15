<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ShopOwnerSubscriptionRefund;
use App\Services\PremiumSubscriptionRefundService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

final class ReconcilePremiumBillingProvider extends Command
{
    protected $signature = 'premium-billing:reconcile-provider {--limit=100}';

    protected $description = 'Reconcile bounded pending or uncertain premium billing provider records.';

    public function handle(PremiumSubscriptionRefundService $refunds): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $processed = 0;
        $counts = [
            'succeeded' => 0,
            'processing' => 0,
            'failed' => 0,
            'unknown' => 0,
        ];
        $request = Request::create('/console/premium-billing/reconcile-provider', 'GET');

        ShopOwnerSubscriptionRefund::query()
            ->whereIn('status', ['pending', 'processing', 'unknown'])
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (ShopOwnerSubscriptionRefund $attempt) use ($refunds, $request, &$processed, &$counts): void {
                try {
                    $result = $refunds->reconcile($attempt, $request);
                    $outcome = (string) $result['outcome'];
                    $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
                } catch (\Throwable $exception) {
                    $counts['unknown']++;
                    $this->warn('Attempt '.$attempt->id.' could not be reconciled; retry remains bounded.');
                }

                $processed++;
            });

        $this->line('Provider reconciliation complete.');
        $this->line('Processed: '.$processed);
        foreach ($counts as $status => $count) {
            $this->line($status.': '.$count);
        }

        return self::SUCCESS;
    }
}
