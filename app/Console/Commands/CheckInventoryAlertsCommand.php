<?php

namespace App\Console\Commands;

use App\Jobs\CheckLowStockJob;
use App\Jobs\CheckOverdueOrdersJob;
use App\Jobs\SyncInventoryWithProductsJob;
use App\Models\ShopOwner;
use Illuminate\Console\Command;

class CheckInventoryAlertsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'inventory:check-alerts {--shop-owner-id= : Specific shop owner ID to check}';

    /**
     * The console command description.
     */
    protected $description = 'Check inventory for low stock and overdue supplier orders';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting inventory alerts check...');

        if ($shopOwnerId = $this->option('shop-owner-id')) {
            // Check specific shop owner
            $this->checkShopOwner((int) $shopOwnerId);
        } else {
            // Check all active shop owners
            $shopOwners = ShopOwner::where('status', 'active')->get();
            
            $this->info("Found {$shopOwners->count()} active shop owners");

            foreach ($shopOwners as $shopOwner) {
                $this->checkShopOwner($shopOwner->id);
            }
        }

        $this->info('Inventory alerts check completed!');

        return Command::SUCCESS;
    }

    /**
     * Check inventory alerts for a specific shop owner
     */
    protected function checkShopOwner(int $shopOwnerId): void
    {
        $this->line("Checking shop owner ID: {$shopOwnerId}");

        // Dispatch low stock check
        CheckLowStockJob::dispatch($shopOwnerId);
        $this->line('  ✓ Low stock check queued');

        // Dispatch overdue orders check
        CheckOverdueOrdersJob::dispatch($shopOwnerId);
        $this->line('  ✓ Overdue orders check queued');

        // Dispatch inventory sync
        SyncInventoryWithProductsJob::dispatch($shopOwnerId);
        $this->line('  ✓ Inventory sync queued');
    }
}
