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
            if (!Schema::hasColumn('attendance_records', 'auto_clocked_out')) {
                $table->boolean('auto_clocked_out')->default(false)->after('check_out_time');
            }
            if (!Schema::hasColumn('attendance_records', 'auto_clockout_reason')) {
                $table->string('auto_clockout_reason')->nullable()->after('auto_clocked_out');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            if (Schema::hasColumn('attendance_records', 'auto_clocked_out')) {
                $table->dropColumn('auto_clocked_out');
            }
            if (Schema::hasColumn('attendance_records', 'auto_clockout_reason')) {
                $table->dropColumn('auto_clockout_reason');
            }
        });
    }
};
