<?php

namespace App\Console\Commands;

use App\Models\ShopOwner;
use App\Services\ShopModuleProvisioningService;
use Illuminate\Console\Command;

class BackfillShopOwnerModules extends Command
{
    protected $signature = 'shop-modules:backfill {--verify : Check module state without writing rows}';

    protected $description = 'Initialize and verify persisted shop module state.';

    public function handle(ShopModuleProvisioningService $provisioning): int
    {
        $verify = (bool) $this->option('verify');
        $missing = 0;
        $unknown = 0;
        $ineligibleEnabled = 0;
        $created = 0;
        $knownKeys = array_keys(config('shop_modules.modules', []));

        ShopOwner::query()
            ->select(['id', 'status', 'registration_type', 'business_type'])
            ->chunkById(100, function ($owners) use (
                $provisioning,
                $verify,
                $knownKeys,
                &$missing,
                &$unknown,
                &$ineligibleEnabled,
                &$created,
            ): void {
                foreach ($owners as $owner) {
                    $eligibleKeys = $provisioning->eligibleKeysFor($owner);
                    $moduleRows = $owner->modules()->get(['module_key', 'enabled']);
                    $storedKeys = $moduleRows->pluck('module_key')->map(static fn ($key): string => (string) $key)->all();

                    $unknown += count(array_diff($storedKeys, $knownKeys));
                    $ineligibleEnabled += $moduleRows
                        ->filter(fn ($row): bool => (bool) $row->enabled && ! in_array($row->module_key, $eligibleKeys, true))
                        ->count();
                    $missing += count(array_diff($eligibleKeys, $storedKeys));

                    if (! $verify) {
                        $created += $provisioning->initializeMissing($owner, $eligibleKeys)->count();
                    }
                }
            });

        $problems = $missing + $unknown + $ineligibleEnabled;
        if ($verify) {
            $this->line("Missing eligible rows: {$missing}");
            $this->line("Unknown stored keys: {$unknown}");
            $this->line("Enabled ineligible rows: {$ineligibleEnabled}");

            if ($problems > 0) {
                $this->error('Shop module verification failed.');

                return self::FAILURE;
            }

            $this->info('Shop module verification passed.');

            return self::SUCCESS;
        }

        $this->info("Initialized {$created} missing shop module rows.");

        return self::SUCCESS;
    }
}
