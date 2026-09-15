<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_refunds', function (Blueprint $table): void {
            if (! Schema::hasColumn('pos_refunds', 'shop_refund_reference')) {
                $table->string('shop_refund_reference', 40)->nullable()->after('refund_no');
            }
        });

        $nextByScope = [];
        DB::table('pos_refunds')
            ->where('module_type', 'repair')
            ->whereNull('shop_refund_reference')
            ->orderBy('shop_owner_id')
            ->orderBy('id')
            ->get(['id', 'shop_owner_id', 'requested_at', 'created_at'])
            ->each(function (object $refund) use (&$nextByScope): void {
                $date = $refund->requested_at ?? $refund->created_at ?? null;
                $year = $date ? Carbon::parse($date)->format('Y') : now()->format('Y');
                $scope = $refund->shop_owner_id . ':' . $year;
                $number = (int) ($nextByScope[$scope] ?? 0) + 1;
                $reference = sprintf('RFD-%s-%04d', $year, $number);

                while (DB::table('pos_refunds')
                    ->where('shop_owner_id', $refund->shop_owner_id)
                    ->where('shop_refund_reference', $reference)
                    ->exists()) {
                    $number++;
                    $reference = sprintf('RFD-%s-%04d', $year, $number);
                }

                DB::table('pos_refunds')
                    ->where('id', $refund->id)
                    ->update(['shop_refund_reference' => $reference]);

                $nextByScope[$scope] = $number;
            });

        Schema::table('pos_refunds', function (Blueprint $table): void {
            $table->unique(
                ['shop_owner_id', 'shop_refund_reference'],
                'pos_refunds_shop_reference_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('pos_refunds', function (Blueprint $table): void {
            $table->dropUnique('pos_refunds_shop_reference_unique');

            if (Schema::hasColumn('pos_refunds', 'shop_refund_reference')) {
                $table->dropColumn('shop_refund_reference');
            }
        });
    }
};
