<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add geofence columns to shop_owners
        Schema::table('shop_owners', function (Blueprint $table) {
            $table->decimal('shop_latitude', 10, 8)->nullable()->after('sunday_close');
            $table->decimal('shop_longitude', 11, 8)->nullable()->after('shop_latitude');
            $table->string('shop_address', 300)->nullable()->after('shop_longitude');
            $table->smallInteger('shop_geofence_radius')->default(100)->after('shop_address'); // meters
            $table->boolean('attendance_geofence_enabled')->default(false)->after('shop_geofence_radius');
        });

        // Add location capture columns to attendance_records
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->decimal('check_in_latitude', 10, 8)->nullable()->after('auto_clockout_reason');
            $table->decimal('check_in_longitude', 11, 8)->nullable()->after('check_in_latitude');
            $table->smallInteger('distance_from_shop')->nullable()->after('check_in_longitude'); // meters
        });
    }

    public function down(): void
    {
        Schema::table('shop_owners', function (Blueprint $table) {
            $table->dropColumn([
                'shop_latitude',
                'shop_longitude',
                'shop_address',
                'shop_geofence_radius',
                'attendance_geofence_enabled',
            ]);
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn(['check_in_latitude', 'check_in_longitude', 'distance_from_shop']);
        });
    }
};
