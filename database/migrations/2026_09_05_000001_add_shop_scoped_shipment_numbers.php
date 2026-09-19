<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->unsignedBigInteger('shipment_number')->nullable()->after('id');
        });

        DB::table('shipments')
            ->select('shop_owner_id')
            ->distinct()
            ->orderBy('shop_owner_id')
            ->pluck('shop_owner_id')
            ->each(function ($shopOwnerId): void {
                $number = 1;

                DB::table('shipments')
                    ->where('shop_owner_id', $shopOwnerId)
                    ->orderBy('id')
                    ->pluck('id')
                    ->each(function ($shipmentId) use (&$number): void {
                        DB::table('shipments')
                            ->where('id', $shipmentId)
                            ->update(['shipment_number' => $number++]);
                    });
            });

        Schema::table('shipments', function (Blueprint $table): void {
            $table->unique(['shop_owner_id', 'shipment_number']);
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropUnique(['shop_owner_id', 'shipment_number']);
            $table->dropColumn('shipment_number');
        });
    }
};
