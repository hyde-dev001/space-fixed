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
            $table->boolean('payment_enabled')->default(false)->after('payment_completed_at')
                ->comment('Whether repairer has enabled payment for this repair');
            $table->timestamp('payment_enabled_at')->nullable()->after('payment_enabled')
                ->comment('When repairer enabled payment');
            $table->unsignedBigInteger('payment_enabled_by')->nullable()->after('payment_enabled_at')
                ->comment('Repairer who enabled payment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('repair_requests', function (Blueprint $table) {
            $table->dropColumn(['payment_enabled', 'payment_enabled_at', 'payment_enabled_by']);
        });
    }
};
