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
            // Expected times based on shop operating hours
            $table->time('expected_check_in')->nullable()->after('check_out_time');
            $table->time('expected_check_out')->nullable()->after('expected_check_in');
            
            // Lateness tracking
            $table->integer('minutes_late')->default(0)->after('expected_check_out');
            $table->integer('minutes_early_departure')->default(0)->after('minutes_late');
            $table->boolean('is_late')->default(false)->after('minutes_early_departure');
            $table->boolean('is_early_departure')->default(false)->after('is_late');
            
            // Optional lateness reason
            $table->text('lateness_reason')->nullable()->after('is_early_departure');
            
            // Index for querying late employees
            $table->index('is_late');
            $table->index(['date', 'is_late']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropIndex(['attendance_records_is_late_index']);
            $table->dropIndex(['attendance_records_date_is_late_index']);
            
            $table->dropColumn([
                'expected_check_in',
                'expected_check_out',
                'minutes_late',
                'minutes_early_departure',
                'is_late',
                'is_early_departure',
                'lateness_reason',
            ]);
        });
    }
};
