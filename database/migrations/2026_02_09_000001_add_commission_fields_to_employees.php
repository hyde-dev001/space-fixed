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
        Schema::table('employees', function (Blueprint $table) {
            // Retail commission structure
            $table->decimal('sales_commission_rate', 5, 4)->nullable()->after('salary')->comment('Sales commission rate (0.0500 = 5%)');
            $table->decimal('performance_bonus_rate', 5, 4)->nullable()->after('sales_commission_rate')->comment('Performance bonus rate (0.1000 = 10%)');
            $table->decimal('other_allowances', 10, 2)->nullable()->after('performance_bonus_rate')->comment('Other allowances (holiday pay, special bonuses)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['sales_commission_rate', 'performance_bonus_rate', 'other_allowances']);
        });
    }
};
