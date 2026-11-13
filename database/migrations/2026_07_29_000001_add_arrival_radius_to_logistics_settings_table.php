<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('arrival_radius_m')
                ->default(100)
                ->after('coverage_radius_km');
        });
    }

    public function down(): void
    {
        Schema::table('logistics_settings', function (Blueprint $table) {
            $table->dropColumn('arrival_radius_m');
        });
    }
};
