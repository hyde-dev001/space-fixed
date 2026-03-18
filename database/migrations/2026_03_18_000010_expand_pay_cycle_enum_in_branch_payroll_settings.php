<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('hr_branch_payroll_settings')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE hr_branch_payroll_settings MODIFY COLUMN pay_cycle ENUM('monthly','semi_monthly') NOT NULL DEFAULT 'semi_monthly'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('hr_branch_payroll_settings')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::table('hr_branch_payroll_settings')
            ->where('pay_cycle', 'monthly')
            ->update(['pay_cycle' => 'semi_monthly']);

        DB::statement("ALTER TABLE hr_branch_payroll_settings MODIFY COLUMN pay_cycle ENUM('semi_monthly') NOT NULL DEFAULT 'semi_monthly'");
    }
};
