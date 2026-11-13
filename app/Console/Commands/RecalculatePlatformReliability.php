<?php

namespace App\Console\Commands;

use App\Models\ShopOwner;
use App\Services\PlatformFeeRecommendationService;
use App\Services\PlatformReliabilityService;
use Illuminate\Console\Command;

final class RecalculatePlatformReliability extends Command
{
    protected $signature = 'platform:reliability-recalculate {--shop= : Recalculate one shop owner}';

    protected $description = 'Recalculate daily marketplace Platform Reliability Scores and recommendations.';

    public function handle(PlatformReliabilityService $reliability, PlatformFeeRecommendationService $recommendations): int
    {
        $query = ShopOwner::query()->approved();
        if ($this->option('shop') !== null) {
            $query->whereKey((int) $this->option('shop'));
        }

        $count = 0;
        foreach ($query->cursor() as $shop) {
            $reliability->recalculate($shop);
            $recommendations->recommend($shop);
            $count++;
        }

        $this->info("Recalculated {$count} shop reliability score(s).");

        return self::SUCCESS;
    }
}
