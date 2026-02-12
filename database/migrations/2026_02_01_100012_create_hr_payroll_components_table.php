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
        Schema::create('hr_payroll_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_id')->constrained('payrolls')->onDelete('cascade');
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->onDelete('cascade');
            
            // Component Type & Details
            $table->enum('component_type', ['earning', 'deduction', 'benefit'])->index();
            $table->string('component_name', 100);
            $table->string('component_code', 50)->nullable(); // For standardization (BASIC, HRA, TAX, SSF, etc.)
            $table->text('description')->nullable();
            
            // Amount & Calculation
            $table->decimal('amount', 10, 2);
            $table->enum('calculation_method', [
                'fixed',           // Fixed amount
                'percentage',      // Percentage of base/gross
                'formula',         // Custom formula
                'attendance_based',// Based on days worked
                'hourly'           // Hourly rate
            ])->default('fixed');
            $table->decimal('calculation_value', 10, 2)->nullable(); // For percentage or hourly rate
            $table->string('calculation_base', 50)->nullable(); // 'basic', 'gross', 'net' for percentage calculations
            $table->text('formula')->nullable(); // Custom formula if needed
            
            // Tax & Compliance
            $table->boolean('is_taxable')->default(true);
            $table->boolean('is_statutory')->default(false); // SSF, pension, etc.
            $table->boolean('affects_gross')->default(true); // Whether this adds to gross salary
            
            // Display & Ordering
            $table->integer('display_order')->default(0);
            $table->boolean('show_on_payslip')->default(true);
            $table->string('category', 50)->nullable(); // Group components (Basic Pay, Allowances, Deductions, etc.)
            
            // Metadata
            $table->json('metadata')->nullable(); // Additional data like tax exemption limits, etc.
            
            $table->timestamps();
            
            // Indexes
            $table->index(['payroll_id', 'component_type']);
            $table->index(['shop_owner_id', 'component_code']);
            $table->index(['component_type', 'is_statutory']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_payroll_components');
    }
};
