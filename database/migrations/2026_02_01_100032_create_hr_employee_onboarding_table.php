<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employee_onboarding', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('checklist_id')->constrained('hr_onboarding_checklists')->onDelete('cascade');
            $table->foreignId('task_id')->constrained('hr_onboarding_tasks')->onDelete('cascade');
            $table->enum('status', ['pending', 'in_progress', 'completed', 'skipped'])->default('pending');
            $table->date('due_date')->nullable();
            $table->date('completed_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            // Indexes
            $table->index('shop_owner_id');
            $table->index('employee_id');
            $table->index('checklist_id');
            $table->index('task_id');
            $table->index(['employee_id', 'status']);
            $table->index(['shop_owner_id', 'status']);
            $table->index('due_date');
            
            // Unique constraint - one task per employee
            $table->unique(['employee_id', 'task_id'], 'emp_task_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_onboarding');
    }
};
