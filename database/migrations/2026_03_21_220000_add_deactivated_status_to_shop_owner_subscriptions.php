<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE shop_owner_subscriptions MODIFY status ENUM('pending', 'active', 'expired', 'cancelled', 'deactivated', 'failed') DEFAULT 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE shop_owner_subscriptions MODIFY status ENUM('pending', 'active', 'expired', 'cancelled', 'failed') DEFAULT 'pending'");
    }
};
