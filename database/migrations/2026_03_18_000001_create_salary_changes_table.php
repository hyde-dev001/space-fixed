<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_changes', function (Blueprint $table) {
            $table->id();

            // Core foreign keys
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->cascadeOnDelete();
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();

            // Salary amounts
            $table->decimal('previous_salary', 12, 2)->default(0);
            $table->decimal('new_salary', 12, 2);
            $table->decimal('change_percent', 6, 2)->default(0);

            // Classification per governance matrix
            $table->enum('change_type', [
                'new_hire_rate_setup',
                'minor_adjustment',
                'major_adjustment',
                'correction',
            ]);

            // Workflow
            $table->date('effective_date');
            $table->text('reason');
            $table->enum('status', [
                'pending',
                'approved',
                'rejected',
                'applied',
                'cancelled',
            ])->default('pending');
            $table->text('notes')->nullable();           // approver / rejector notes

            // Timestamps for each stage
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('applied_at')->nullable();  // set when employee.salary is updated

            // Retroactive-edit audit trail
            $table->boolean('retroactive')->default(false);
            $table->foreignId('retroactive_override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('retroactive_override_reason')->nullable();

            $table->timestamps();

            // Performance indexes
            $table->index(['employee_id', 'status']);
            $table->index(['shop_owner_id', 'effective_date']);
            $table->index(['status', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_changes');
    }
};
