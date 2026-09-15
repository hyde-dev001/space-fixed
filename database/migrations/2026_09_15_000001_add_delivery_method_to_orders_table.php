<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'delivery_method')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->string('delivery_method', 32)->nullable()->after('carrier_company');
                $table->index('delivery_method');
            });
        }

        DB::table('orders')
            ->whereNull('delivery_method')
            ->whereRaw("LOWER(TRIM(COALESCE(carrier_company, ''))) = ?", ['shop-owned logistics'])
            ->update(['delivery_method' => 'shop_owned']);

        DB::table('orders')
            ->whereNull('delivery_method')
            ->whereNotNull('carrier_company')
            ->where('carrier_company', '<>', '')
            ->whereRaw("LOWER(TRIM(carrier_company)) <> ?", ['shop-owned logistics'])
            ->update(['delivery_method' => 'third_party']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'delivery_method')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropIndex(['delivery_method']);
                $table->dropColumn('delivery_method');
            });
        }
    }
};
