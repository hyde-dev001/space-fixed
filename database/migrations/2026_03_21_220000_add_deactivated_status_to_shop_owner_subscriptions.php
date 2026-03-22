<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE shop_owner_subscriptions MODIFY status ENUM('pending', 'active', 'expired', 'cancelled', 'deactivated', 'failed') DEFAULT 'pending'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE shop_owner_subscriptions MODIFY status ENUM('pending', 'active', 'expired', 'cancelled', 'failed') DEFAULT 'pending'");
        }
    }
};
