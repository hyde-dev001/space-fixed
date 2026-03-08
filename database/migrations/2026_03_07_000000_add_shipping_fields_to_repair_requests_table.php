<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repair_requests', function (Blueprint $table) {
            $table->string('tracking_number')->nullable()->after('status');
            $table->string('carrier_company')->nullable()->after('tracking_number');
            $table->string('carrier_name')->nullable()->after('carrier_company');
            $table->string('carrier_phone')->nullable()->after('carrier_name');
            $table->string('tracking_link')->nullable()->after('carrier_phone');
            $table->timestamp('shipped_at')->nullable()->after('tracking_link');
        });
    }

    public function down(): void
    {
        Schema::table('repair_requests', function (Blueprint $table) {
            $table->dropColumn(['tracking_number', 'carrier_company', 'carrier_name', 'carrier_phone', 'tracking_link', 'shipped_at']);
        });
    }
};
