<?php

namespace App\Console\Commands;

use App\Models\ShopOwnerSubscription;
use App\Services\PremiumSubscriptionRenewalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ProcessPremiumRenewals extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:process-premium-renewals {--dry-run : Preview due subscriptions without creating checkout sessions}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create renewal checkout sessions for premium subscriptions nearing expiry with auto-renew enabled';

    public function handle(PremiumSubscriptionRenewalService $renewalService): int
    {
        $requiredColumns = [
            'auto_renew',
            'auto_renew_status',
            'renewal_due_at',
            'renewal_retry_count',
            'renewal_last_attempt_at',
            'renewal_next_attempt_at',
            'renewal_checkout_session_id',
            'renewal_checkout_url',
            'renewal_checkout_url_expires_at',
            'renewal_of_subscription_id',
        ];

        $missingColumns = array_values(array_filter($requiredColumns, function (string $column): bool {
            return !Schema::hasColumn('shop_owner_subscriptions', $column);
        }));

        if (!empty($missingColumns)) {
            $this->error('Cannot process premium renewals. Missing columns: ' . implode(', ', $missingColumns));
            $this->line('Run: php artisan migrate');

            return self::FAILURE;
        }

        $windowStart = now()->subDay();
        $windowEnd = now()->addDay();

        $query = ShopOwnerSubscription::query()
            ->with(['premiumPlan', 'shopOwner'])
            ->where('status', 'active')
            ->where('auto_renew', true)
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [$windowStart, $windowEnd])
            ->where(function ($q) {
                $q->whereNull('renewal_next_attempt_at')
                    ->orWhere('renewal_next_attempt_at', '<=', now());
            })
            ->orderBy('ends_at');

        $totalDue = (clone $query)->count();

        if ($totalDue === 0) {
            $this->info('No premium subscriptions are currently due for renewal processing.');
            return self::SUCCESS;
        }

        $this->info("Found {$totalDue} due premium subscription(s) for renewal processing.");

        if ((bool) $this->option('dry-run')) {
            $query->get()->each(function (ShopOwnerSubscription $subscription): void {
                $this->line(sprintf(
                    '- #%d shop_owner=%d plan=%s ends_at=%s',
                    $subscription->id,
                    (int) $subscription->shop_owner_id,
                    (string) ($subscription->plan_code ?? 'n/a'),
                    (string) optional($subscription->ends_at)->toDateTimeString()
                ));
            });

            return self::SUCCESS;
        }

        $created = 0;
        $existing = 0;
        $failed = 0;

        $query->chunkById(100, function ($subscriptions) use ($renewalService, &$created, &$existing, &$failed): void {
            foreach ($subscriptions as $subscription) {
                /** @var ShopOwnerSubscription $subscription */
                $result = $renewalService->createRenewalCheckout($subscription);

                if (!($result['success'] ?? false)) {
                    $failed++;
                    $this->warn(sprintf(
                        'Failed renewal for subscription #%d: %s',
                        $subscription->id,
                        (string) ($result['message'] ?? 'Unknown error')
                    ));
                    continue;
                }

                if (($result['existing'] ?? false) === true) {
                    $existing++;
                    $this->line("Existing pending renewal retained for subscription #{$subscription->id}.");
                    continue;
                }

                $created++;
                $this->info("Renewal checkout created for subscription #{$subscription->id}.");
            }
        });

        $this->newLine();
        $this->info("Premium renewal processing complete: created={$created}, existing={$existing}, failed={$failed}.");

        return self::SUCCESS;
    }
}
