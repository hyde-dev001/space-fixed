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
        Schema::table('attendance_records', function (Blueprint $table) {
            // Check if columns don't exist before adding
            if (!Schema::hasColumn('attendance_records', 'is_early')) {
                $table->boolean('is_early')->default(false)->after('minutes_late');
            }
            if (!Schema::hasColumn('attendance_records', 'minutes_early')) {
                $table->integer('minutes_early')->default(0)->after('is_early');
            }
            if (!Schema::hasColumn('attendance_records', 'early_reason')) {
                $table->text('early_reason')->nullable()->after('minutes_early');
            }
            // expected_check_in already exists, skip it
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            if (Schema::hasColumn('attendance_records', 'is_early')) {
                $table->dropColumn('is_early');
            }
            if (Schema::hasColumn('attendance_records', 'minutes_early')) {
                $table->dropColumn('minutes_early');
            }
            if (Schema::hasColumn('attendance_records', 'early_reason')) {
                $table->dropColumn('early_reason');
            }
        });
    }
};
