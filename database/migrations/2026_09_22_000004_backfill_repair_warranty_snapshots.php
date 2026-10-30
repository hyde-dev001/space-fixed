<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DEFAULT_DURATION = 30;

    private const MAX_DURATION = [
        'days' => 365,
        'weeks' => 52,
        'months' => 12,
    ];

    public function up(): void
    {
        DB::table('repair_requests')
            ->whereNotNull('picked_up_at')
            ->where(function ($query): void {
                $query->where('is_warranty_job', false)->orWhereNull('is_warranty_job');
            })
            ->where('repair_warranty_issued', false)
            ->orderBy('id')
            ->chunkById(200, function ($repairs): void {
                foreach ($repairs as $repair) {
                    $settings = DB::table('shop_owners')
                        ->where('id', $repair->shop_owner_id)
                        ->first([
                            'warranty_enabled',
                            'repair_warranty_days',
                            'repair_warranty_duration_unit',
                        ]);

                    if (! $settings || ! (bool) $settings->warranty_enabled) {
                        continue;
                    }

                    $unit = strtolower(trim((string) ($settings->repair_warranty_duration_unit ?? 'days')));
                    if (! array_key_exists($unit, self::MAX_DURATION)) {
                        $unit = 'days';
                    }

                    $duration = max(1, (int) ($settings->repair_warranty_days ?? self::DEFAULT_DURATION));
                    $duration = min($duration, self::MAX_DURATION[$unit]);
                    $startedAt = Carbon::parse($repair->picked_up_at);
                    $expiresAt = match ($unit) {
                        'weeks' => $startedAt->copy()->addWeeks($duration)->endOfDay(),
                        'months' => $startedAt->copy()->addMonthsNoOverflow($duration)->endOfDay(),
                        default => $startedAt->copy()->addDays($duration)->endOfDay(),
                    };

                    DB::table('repair_requests')
                        ->where('id', $repair->id)
                        ->where('repair_warranty_issued', false)
                        ->update([
                            'repair_warranty_issued' => true,
                            'repair_warranty_started_at' => $startedAt,
                            'repair_warranty_expires_at' => $expiresAt,
                            'repair_warranty_duration' => $duration,
                            'repair_warranty_duration_unit' => $unit,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // This data backfill is intentionally irreversible; a rollback must not
        // erase immutable warranty terms already issued to customers.
    }
};
