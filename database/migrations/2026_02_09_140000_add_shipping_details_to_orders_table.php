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
            // Add missing shipping columns
            $table->string('carrier_company')->nullable()->after('shipping_carrier');
            $table->string('carrier_name')->nullable()->after('carrier_company');
            $table->string('carrier_phone')->nullable()->after('carrier_name');
            $table->string('tracking_link')->nullable()->after('tracking_number');
            $table->string('eta')->nullable()->after('shipped_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['carrier_company', 'carrier_name', 'carrier_phone', 'tracking_link', 'eta']);
        });
    }
};
