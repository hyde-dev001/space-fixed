<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add columns that PayrollService, MyPayslips.tsx, and PayslipApprovalController
     * reference but were missing from the original create_hr_payrolls_table migration.
     */
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            // Pay period date range (parsed from payroll_period by PayrollService)
            $table->date('pay_period_start')->nullable()->after('payroll_period');
            $table->date('pay_period_end')->nullable()->after('pay_period_start');

            // Basic/raw salary before component adjustments
            $table->decimal('basic_salary', 12, 2)->default(0)->after('pay_period_end');

            // Aggregated deduction total (sum of all deduction components, set by PayrollService)
            $table->decimal('total_deductions', 12, 2)->default(0)->after('deductions');

            // Withholding tax calculated by PayrollService (BIR TRAIN brackets)
            $table->decimal('tax_amount', 12, 2)->default(0)->after('total_deductions');

            // Audit: who generated this payroll and when
            $table->unsignedBigInteger('generated_by')->nullable()->after('approved_at');
            $table->timestamp('generated_at')->nullable()->after('generated_by');

            // HR notes attached to this payslip
            $table->text('notes')->nullable()->after('approval_notes');

            // Attendance data used for proration (displayed in MyPayslips.tsx)
            $table->integer('attendance_days')->default(0)->after('notes');
            $table->integer('leave_days')->default(0)->after('attendance_days');
            $table->decimal('overtime_hours', 5, 2)->default(0)->after('leave_days');

            // Index to speed up employee self-service queries ordered by pay_period_start
            $table->index('pay_period_start');
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropIndex(['pay_period_start']);
            $table->dropColumn([
                'pay_period_start',
                'pay_period_end',
                'basic_salary',
                'total_deductions',
                'tax_amount',
                'generated_by',
                'generated_at',
                'notes',
                'attendance_days',
                'leave_days',
                'overtime_hours',
            ]);
        });
    }
};
