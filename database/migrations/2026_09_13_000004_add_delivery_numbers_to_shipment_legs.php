<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_legs', function (Blueprint $table): void {
            $table->foreignId('shop_owner_id')->nullable()->after('shipment_id')
                ->constrained('shop_owners')->nullOnDelete();
            $table->unsignedBigInteger('delivery_number')->nullable()->after('shop_owner_id');
        });

        $nextByShop = [];
        DB::table('shipment_legs as legs')
            ->join('shipments', 'shipments.id', '=', 'legs.shipment_id')
            ->orderBy('shipments.shop_owner_id')
            ->orderBy('legs.id')
            ->get(['legs.id', 'shipments.shop_owner_id'])
            ->each(function (object $leg) use (&$nextByShop): void {
                $shopOwnerId = (int) $leg->shop_owner_id;
                $nextByShop[$shopOwnerId] = ($nextByShop[$shopOwnerId] ?? 0) + 1;

                DB::table('shipment_legs')
                    ->where('id', $leg->id)
                    ->update([
                        'shop_owner_id' => $shopOwnerId,
                        'delivery_number' => $nextByShop[$shopOwnerId],
                    ]);
            });

        Schema::table('shipment_legs', function (Blueprint $table): void {
            $table->unique(
                ['shop_owner_id', 'delivery_number'],
                'shipment_legs_shop_owner_delivery_number_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('shipment_legs', function (Blueprint $table): void {
            $table->dropUnique('shipment_legs_shop_owner_delivery_number_unique');
            $table->dropForeign(['shop_owner_id']);
            $table->dropColumn(['shop_owner_id', 'delivery_number']);
        });
    }
};
