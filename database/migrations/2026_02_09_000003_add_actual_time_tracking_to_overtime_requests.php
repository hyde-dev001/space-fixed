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
        Schema::table('hr_overtime_requests', function (Blueprint $table) {
            // Track actual overtime worked
            if (!Schema::hasColumn('hr_overtime_requests', 'actual_start_time')) {
                $table->time('actual_start_time')->nullable()->after('end_time');
            }
            if (!Schema::hasColumn('hr_overtime_requests', 'actual_end_time')) {
                $table->time('actual_end_time')->nullable()->after('actual_start_time');
            }
            if (!Schema::hasColumn('hr_overtime_requests', 'actual_hours')) {
                $table->decimal('actual_hours', 5, 2)->nullable()->after('actual_end_time');
            }
            if (!Schema::hasColumn('hr_overtime_requests', 'checked_in_at')) {
                $table->timestamp('checked_in_at')->nullable()->after('actual_hours');
            }
            if (!Schema::hasColumn('hr_overtime_requests', 'checked_out_at')) {
                $table->timestamp('checked_out_at')->nullable()->after('checked_in_at');
            }
            if (!Schema::hasColumn('hr_overtime_requests', 'confirmed_by')) {
                $table->unsignedBigInteger('confirmed_by')->nullable()->after('approved_by');
            }
            if (!Schema::hasColumn('hr_overtime_requests', 'confirmed_at')) {
                $table->timestamp('confirmed_at')->nullable()->after('confirmed_by');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hr_overtime_requests', function (Blueprint $table) {
            $columns = [
                'actual_start_time',
                'actual_end_time', 
                'actual_hours',
                'checked_in_at',
                'checked_out_at',
                'confirmed_by',
                'confirmed_at'
            ];
            
            foreach ($columns as $column) {
                if (Schema::hasColumn('hr_overtime_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
