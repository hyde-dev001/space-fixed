<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add absent_days to payrolls so it can be stored alongside attendance_days
     * and leave_days. absent_days = working_days - attendance_days - leave_days.
     */
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->integer('absent_days')->default(0)->after('leave_days');
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn('absent_days');
        });
    }
};
