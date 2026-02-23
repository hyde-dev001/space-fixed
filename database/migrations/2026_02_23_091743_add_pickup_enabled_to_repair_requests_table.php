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
        Schema::table('repair_requests', function (Blueprint $table) {
            $table->boolean('pickup_enabled')->default(false)->after('payment_enabled_by')
                ->comment('Whether customer can confirm pickup');
            $table->timestamp('pickup_enabled_at')->nullable()->after('pickup_enabled')
                ->comment('When pickup confirmation was enabled by shop owner');
            $table->unsignedBigInteger('pickup_enabled_by')->nullable()->after('pickup_enabled_at')
                ->comment('Shop owner who enabled pickup confirmation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('repair_requests', function (Blueprint $table) {
            $table->dropColumn(['pickup_enabled', 'pickup_enabled_at', 'pickup_enabled_by']);
        });
    }
};
