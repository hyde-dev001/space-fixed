<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shop_owner_subscriptions', function (Blueprint $table) {
            $table->decimal('paid_amount', 10, 2)->nullable()->after('payment_method');
        });

        // Backfill current records so amount is preserved after future plan changes.
        DB::table('shop_owner_subscriptions as s')
            ->leftJoin('premium_plans as p', 'p.id', '=', 's.premium_plan_id')
            ->whereNull('s.paid_amount')
            ->update([
                's.paid_amount' => DB::raw('COALESCE(p.price, 0)'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_owner_subscriptions', function (Blueprint $table) {
            $table->dropColumn('paid_amount');
        });
    }
};
