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
            $table->string('cancellation_reason', 120)->nullable()->after('paid_amount');
            $table->text('cancellation_notes')->nullable()->after('cancellation_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_owner_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['cancellation_reason', 'cancellation_notes']);
        });
    }
};
