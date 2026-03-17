<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_branch_payroll_settings', function (Blueprint $table) {
            $table->boolean('require_payslip_approval')
                ->default(false)
                ->after('non_business_day_rule')
                ->comment('When false, payslips are auto-approved after generation — no Finance/Owner sign-off required.');
        });
    }

    public function down(): void
    {
        Schema::table('hr_branch_payroll_settings', function (Blueprint $table) {
            $table->dropColumn('require_payslip_approval');
        });
    }
};
