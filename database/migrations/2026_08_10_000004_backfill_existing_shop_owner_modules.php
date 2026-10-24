<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shop_owners') || ! Schema::hasTable('shop_owner_modules')) {
            return;
        }

        $modules = config('shop_modules.modules', []);

        DB::table('shop_owners')
            ->select(['id', 'registration_type', 'business_type'])
            ->where('status', 'approved')
            ->whereIn('registration_type', ['individual', 'company'])
            ->orderBy('id')
            ->chunkById(100, function ($owners) use ($modules): void {
                foreach ($owners as $owner) {
                    $businessType = $this->normalizeBusinessType($owner->business_type);
                    $eligibleKeys = [];

                    foreach ($modules as $moduleKey => $module) {
                        if (($module['backfill_enabled'] ?? false)
                            && in_array($owner->registration_type, $module['registration_types'] ?? [], true)
                            && in_array($businessType, $module['business_types'] ?? [], true)) {
                            $eligibleKeys[] = (string) $moduleKey;
                        }
                    }

                    if ($eligibleKeys === []) {
                        continue;
                    }

                    $now = now();
                    $rows = array_map(
                        static fn (string $moduleKey): array => [
                            'shop_owner_id' => $owner->id,
                            'module_key' => $moduleKey,
                            'enabled' => true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                        $eligibleKeys,
                    );

                    DB::table('shop_owner_modules')->insertOrIgnore($rows);
                }
            });
    }

    public function down(): void
    {
        // Module rows are persisted configuration and must not be deleted on rollback.
    }

    private function normalizeBusinessType(?string $value): string
    {
        $normalized = strtolower(trim((string) $value));

        if (str_contains($normalized, 'both')
            || (str_contains($normalized, 'repair') && str_contains($normalized, 'retail'))) {
            return 'both';
        }

        if (str_contains($normalized, 'repair')) {
            return 'repair';
        }

        if (str_contains($normalized, 'retail')) {
            return 'retail';
        }

        return $normalized;
    }
};
