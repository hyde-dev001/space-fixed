<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repair_requests', function (Blueprint $table) {
            $table->enum('intake_delivery_method', ['walk_in', 'customer_delivery'])->nullable()->after('delivery_method')->comment('How customer delivers shoes to shop: walk_in = customer visits shop, customer_delivery = customer arranges delivery (Lalamove, etc)');
            $table->enum('return_delivery_method', ['walk_in', 'shop_delivery', 'customer_pickup'])->nullable()->after('intake_delivery_method')->comment('How customer receives repaired shoes: walk_in = customer picks up from shop, shop_delivery = shop delivers, customer_pickup = customer arranges pickup');
            $table->json('return_address')->nullable()->after('return_delivery_method')->comment('Address where repaired shoes should be returned to customer');
            $table->json('intake_address')->nullable()->after('return_address')->comment('Address where customer wants to deliver shoes to shop (if customer_delivery method)');
        });

        DB::table('repair_requests')
            ->whereNull('intake_delivery_method')
            ->whereNotNull('delivery_method')
            ->update([
                'intake_delivery_method' => DB::raw("CASE WHEN delivery_method = 'walk_in' THEN 'walk_in' ELSE 'customer_delivery' END"),
            ]);

        DB::table('repair_requests')
            ->whereNull('return_delivery_method')
            ->whereNotNull('delivery_method')
            ->update([
                'return_delivery_method' => DB::raw("CASE WHEN delivery_method = 'walk_in' THEN 'walk_in' ELSE 'customer_pickup' END"),
            ]);

        DB::table('repair_requests')
            ->whereNull('intake_address')
            ->whereNotNull('pickup_address')
            ->update([
                'intake_address' => DB::raw('pickup_address'),
            ]);
    }

    public function down(): void
    {
        Schema::table('repair_requests', function (Blueprint $table) {
            $table->dropColumn(['intake_delivery_method', 'return_delivery_method', 'return_address', 'intake_address']);
        });
    }
};
