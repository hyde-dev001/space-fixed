<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add columns that PayrollService writes when creating component records,
     * and change calculation_method to VARCHAR so all service METHOD_* values
     * (allowance, commission, overtime, custom, etc.) are accepted without
     * modifying the ENUM list.
     */
    public function up(): void
    {
        Schema::table('hr_payroll_components', function (Blueprint $table) {
            // Raw input amount (before applying calculation method)
            $table->decimal('base_amount', 12, 2)->default(0)->after('amount');

            // Final computed amount used for totals and payslip display
            $table->decimal('calculated_amount', 12, 2)->default(0)->after('base_amount');

            // Whether this component recurs every pay period
            $table->boolean('is_recurring')->default(true)->after('is_statutory');

            // Optional filters: restrict component to a specific grade or department
            $table->string('applies_to_grade', 50)->nullable()->after('is_recurring');
            $table->string('applies_to_department', 100)->nullable()->after('applies_to_grade');

            // Change ENUM → VARCHAR so PayrollService METHOD_* string values
            // (e.g. 'allowance', 'commission', 'overtime', 'custom', 'days_worked',
            //  'hours_worked', 'percentage_of_basic', 'percentage_of_gross') are stored
            // without needing to alter the ENUM on every new method addition.
            $table->string('calculation_method', 50)->default('fixed')->change();

            // Index for quick lookup of components by calculated amount type
            $table->index(['payroll_id', 'calculated_amount']);
        });
    }

    public function down(): void
    {
        Schema::table('hr_payroll_components', function (Blueprint $table) {
            $table->dropIndex(['payroll_id', 'calculated_amount']);
            $table->dropColumn([
                'base_amount',
                'calculated_amount',
                'is_recurring',
                'applies_to_grade',
                'applies_to_department',
            ]);
            // Restore original ENUM
            $table->enum('calculation_method', ['fixed', 'percentage', 'formula', 'attendance_based', 'hourly'])
                  ->default('fixed')
                  ->change();
        });
    }
};
