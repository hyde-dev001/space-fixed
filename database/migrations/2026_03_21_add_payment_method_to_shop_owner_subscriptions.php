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
        Schema::table('shop_owner_subscriptions', function (Blueprint $table) {
            $table->string('payment_method')->nullable()->after('paymongo_payment_id');
            $table->index('payment_method');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_owner_subscriptions', function (Blueprint $table) {
            $table->dropIndex(['payment_method']);
            $table->dropColumn('payment_method');
        });
    }
};
