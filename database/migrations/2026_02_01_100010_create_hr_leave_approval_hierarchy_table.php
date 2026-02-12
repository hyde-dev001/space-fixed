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
        Schema::create('hr_leave_approval_hierarchy', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('approver_id')->constrained('users')->onDelete('cascade');
            
            // Hierarchy Configuration
            $table->integer('approval_level')->default(1); // 1 = first level (immediate manager), 2 = second level, etc.
            $table->enum('approver_type', ['manager', 'hr', 'department_head', 'custom'])->default('manager');
            
            // Conditional Approval Rules
            $table->integer('applies_for_days_greater_than')->nullable(); // Only required if leave > X days
            $table->json('applies_for_leave_types')->nullable(); // Only for specific leave types
            
            // Delegation
            $table->foreignId('delegated_to')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('delegation_start_date')->nullable();
            $table->timestamp('delegation_end_date')->nullable();
            $table->text('delegation_reason')->nullable();
            
            // Status
            $table->boolean('is_active')->default(true);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['shop_owner_id', 'employee_id']);
            $table->index(['approver_id', 'is_active']);
            $table->index(['delegated_to']);
            $table->index(['employee_id', 'approval_level']);
            
            // Ensure one approver per level per employee (custom short name for unique constraint)
            $table->unique(['shop_owner_id', 'employee_id', 'approval_level'], 'hr_approval_hierarchy_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_leave_approval_hierarchy');
    }
};
