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
            $table->boolean('auto_renew')->default(true)->after('status');
            $table->string('auto_renew_status', 32)->default('enabled')->after('auto_renew');
            $table->index(['status', 'auto_renew'], 'shop_owner_subscriptions_status_auto_renew_idx');
        });

        DB::table('shop_owner_subscriptions')
            ->whereIn('status', ['expired', 'cancelled', 'deactivated', 'failed'])
            ->update([
                'auto_renew' => false,
                'auto_renew_status' => 'disabled',
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_owner_subscriptions', function (Blueprint $table) {
            $table->dropIndex('shop_owner_subscriptions_status_auto_renew_idx');
            $table->dropColumn(['auto_renew', 'auto_renew_status']);
        });
    }
};
