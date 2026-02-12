<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_performance_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_owner_id')->constrained('shop_owners')->onDelete('cascade');
            $table->foreignId('cycle_id')->constrained('hr_performance_cycles')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->text('goal_description');
            $table->string('target_value', 100)->nullable();
            $table->decimal('weight', 5, 2)->default(0.00)->comment('Goal weight in percentage');
            $table->enum('status', ['not_started', 'in_progress', 'achieved', 'not_achieved', 'cancelled'])->default('not_started');
            $table->date('due_date')->nullable();
            $table->text('progress_notes')->nullable();
            $table->decimal('actual_value', 10, 2)->nullable()->comment('Actual achievement value');
            $table->integer('self_rating')->nullable()->comment('Employee self-rating 1-5');
            $table->integer('manager_rating')->nullable()->comment('Manager rating 1-5');
            $table->timestamps();

            // Indexes
            $table->index('shop_owner_id');
            $table->index('cycle_id');
            $table->index('employee_id');
            $table->index(['employee_id', 'cycle_id']);
            $table->index(['shop_owner_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_performance_goals');
    }
};
