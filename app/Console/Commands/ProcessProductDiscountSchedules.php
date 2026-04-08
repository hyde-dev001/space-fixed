<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ProcessProductDiscountSchedules extends Command
{
    protected $signature = 'products:process-discount-schedules
                            {--dry-run : Report only and do not update records}';

    protected $description = 'Activate scheduled product discounts and auto-restore prices after end date';

    public function handle(): int
    {
        if (!Schema::hasTable('products')) {
            $this->warn('Products table does not exist.');
            return self::SUCCESS;
        }

        foreach (['scheduled_sale_price', 'sale_starts_at', 'sale_ends_at', 'compare_at_price', 'price'] as $column) {
            if (!Schema::hasColumn('products', $column)) {
                $this->warn("Missing products column: {$column}. Run latest migrations first.");
                return self::SUCCESS;
            }
        }

        $dryRun = (bool) $this->option('dry-run');
        $now = now();

        $expiredCount = 0;
        Product::query()
            ->whereNotNull('sale_ends_at')
            ->where('sale_ends_at', '<=', $now)
            ->where(function ($query) {
                $query->whereNotNull('compare_at_price')
                    ->orWhereNotNull('scheduled_sale_price');
            })
            ->orderBy('id')
            ->chunkById(100, function ($products) use (&$expiredCount, $dryRun) {
                foreach ($products as $product) {
                    $originalPrice = $product->compare_at_price !== null
                        ? (float) $product->compare_at_price
                        : (float) $product->price;

                    if (!$dryRun) {
                        $product->update([
                            'price' => $originalPrice,
                            'compare_at_price' => null,
                            'scheduled_sale_price' => null,
                            'sale_starts_at' => null,
                            'sale_ends_at' => null,
                        ]);
                    }

                    $expiredCount++;
                }
            });

        $activatedCount = 0;
        Product::query()
            ->whereNotNull('scheduled_sale_price')
            ->whereNotNull('sale_starts_at')
            ->where('sale_starts_at', '<=', $now)
            ->where(function ($query) use ($now) {
                $query->whereNull('sale_ends_at')
                    ->orWhere('sale_ends_at', '>', $now);
            })
            ->orderBy('id')
            ->chunkById(100, function ($products) use (&$activatedCount, $dryRun) {
                foreach ($products as $product) {
                    $scheduledPrice = (float) $product->scheduled_sale_price;
                    $currentPrice = (float) $product->price;
                    $baselineOriginal = $product->compare_at_price !== null
                        ? (float) $product->compare_at_price
                        : $currentPrice;

                    if ($scheduledPrice <= 0 || $scheduledPrice >= $baselineOriginal) {
                        continue;
                    }

                    if (!$dryRun) {
                        $product->update([
                            'compare_at_price' => $product->compare_at_price ?? $currentPrice,
                            'price' => $scheduledPrice,
                            'scheduled_sale_price' => null,
                        ]);
                    }

                    $activatedCount++;
                }
            });

        $this->info("Processed product discount schedules. activated={$activatedCount}, restored={$expiredCount}, dry_run=" . ($dryRun ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
