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
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->onDelete('cascade');
            
            $table->string('payroll_period'); // e.g., "2026-01" for January 2026
            
            // Salary components
            $table->decimal('base_salary', 12, 2);
            $table->decimal('gross_salary', 12, 2);
            $table->decimal('allowances', 12, 2)->default(0);
            $table->decimal('deductions', 12, 2)->default(0);
            $table->decimal('overtime_pay', 12, 2)->default(0);
            $table->decimal('bonus', 12, 2)->default(0);
            $table->decimal('net_salary', 12, 2);
            
            // Payment details
            $table->enum('status', ['pending', 'processed', 'paid'])->default('pending');
            $table->timestamp('payment_date')->nullable();
            $table->enum('payment_method', ['cash', 'bank_transfer', 'check'])->default('bank_transfer');
            
            // Tax information
            $table->decimal('tax_deductions', 12, 2)->default(0);
            $table->decimal('sss_contributions', 12, 2)->default(0);
            $table->decimal('philhealth', 12, 2)->default(0);
            $table->decimal('pag_ibig', 12, 2)->default(0);
            
            $table->timestamps();
            
            // Indexes
            $table->index('employee_id');
            $table->index('shop_owner_id');
            $table->index('payroll_period');
            $table->index('status');
            
            // Unique constraint to prevent duplicate payroll for same period
            $table->unique(['employee_id', 'payroll_period']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};