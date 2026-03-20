<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $shopOwnersToConvert = DB::table('shop_owners')
            ->where('repair_payment_policy', 'pay_after')
            ->count();

        $repairsToConvert = DB::table('repair_requests')
            ->where('payment_policy', 'pay_after')
            ->count();

        DB::table('shop_owners')
            ->where('repair_payment_policy', 'pay_after')
            ->update(['repair_payment_policy' => 'full_upfront']);

        DB::table('repair_requests')
            ->where('payment_policy', 'pay_after')
            ->update(['payment_policy' => 'full_upfront']);

        Log::info('Repair online-only payment policy cutover applied', [
            'shop_owner_rows_converted' => $shopOwnersToConvert,
            'repair_request_rows_converted' => $repairsToConvert,
        ]);
    }

    public function down(): void
    {
        // Irreversible safely: we cannot distinguish full_upfront values that were
        // originally set as full_upfront from those converted from pay_after.
    }
};
