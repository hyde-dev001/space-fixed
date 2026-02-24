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
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('pickup_enabled')->default(false)->after('status');
            $table->timestamp('pickup_enabled_at')->nullable()->after('pickup_enabled');
            $table->unsignedBigInteger('pickup_enabled_by')->nullable()->after('pickup_enabled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['pickup_enabled', 'pickup_enabled_at', 'pickup_enabled_by']);
        });
    }
};
