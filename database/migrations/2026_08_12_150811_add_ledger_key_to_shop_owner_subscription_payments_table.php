<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shop_owner_subscription_payments', function (Blueprint $table) {
            $table->string('ledger_key', 120)
                ->nullable()
                ->after('subscription_id')
                ->unique('shop_owner_sub_payments_ledger_key_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_owner_subscription_payments', function (Blueprint $table) {
            $table->dropUnique('shop_owner_sub_payments_ledger_key_unique');
            $table->dropColumn('ledger_key');
        });
    }
};
